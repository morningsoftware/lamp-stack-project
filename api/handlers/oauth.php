<?php
// ============================================================
//  api/handlers/oauth.php — GitHub OAuth (sign in + account linking)
//
//  GET  /oauth/github            start "sign in with GitHub"
//  POST /oauth/github/connect    start "connect GitHub" (auth), returns url
//  GET  /oauth/github/callback   OAuth redirect target
//  POST /oauth/github/disconnect unlink the authenticated user's GitHub
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/oauth-state.php';

$db       = getDB();
$segments = pathSegments();
$action   = $segments[1] ?? '';
$sub      = $segments[2] ?? '';

if ($action !== 'github') {
    respond(404, ['error' => 'Unknown OAuth action']);
}

if ($sub === 'callback') {
    oauthCallback($db);
} elseif ($sub === 'disconnect') {
    requireMethod('POST');
    oauthDisconnect($db);
} elseif ($sub === 'connect') {
    requireMethod('POST');
    oauthConnect();
} else {
    oauthStart();
}

/**
 * Sets (or clears, when ttl <= 0) a short-lived OAuth cookie.
 *
 * @param string $name
 * @param string $value
 * @param int $ttl seconds to live (0 = delete)
 */
function oauthSetCookie($name, $value, $ttl = 0) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($name, (string) $value, [
        'expires'  => $ttl > 0 ? time() + $ttl : time() - 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Starts the "sign in with GitHub" flow by redirecting to GitHub.
 */
function oauthStart() {
    $state = 'login:' . bin2hex(random_bytes(16));
    oauthRemember($state);

    header('Location: ' . githubOAuthAuthorizeUrl($state));
    exit;
}

/**
 * Starts the "connect GitHub" flow for the authenticated user.
 * Returns the authorize URL; the browser then navigates to it.
 */
function oauthConnect() {
    $userid = requireAuth();
    $state  = 'connect:' . bin2hex(random_bytes(16));

    oauthRemember($state, $userid);

    respond(200, ['data' => ['url' => githubOAuthAuthorizeUrl($state)]]);
}

/**
 * Redirects back into the app with an error message instead of a raw
 * JSON error (the callback is a browser navigation, not an API call).
 *
 * @param string $mode 'connect' or 'login'
 * @param string $message
 */
function oauthFail($mode, $message) {
    oauthSetCookie('oauth_state', '', 0);
    oauthSetCookie('oauth_userid', '', 0);

    if ($mode === 'connect') {
        header('Location: ' . appBaseUrl() . '/#/settings?tab=github&oauth_error=' . rawurlencode($message));
    } else {
        header('Location: ' . appBaseUrl() . '/#/oauth?error=' . rawurlencode($message));
    }
    exit;
}

/**
 * Handles the GitHub redirect: links the account (connect) or signs in
 * (login), then redirects back into the app.
 *
 * @param PDO $db
 */
function oauthCallback($db) {
    $state = (string) ($_GET['state'] ?? '');
    $code  = (string) ($_GET['code'] ?? '');
    $mode  = str_starts_with($state, 'connect:') ? 'connect' : 'login';

    $pending = oauthConsume($state);
    if ($pending === null) {
        oauthFail($mode, 'The sign-in request expired or was invalid. Please try again.');
    }

    if ($code === '') {
        oauthFail($mode, 'GitHub authorization was not completed.');
    }

    $accessToken = githubOAuthExchangeCode($code);
    if ($accessToken === null) {
        oauthFail($mode, 'Could not connect to GitHub. Please try again.');
    }
    $ghUser = githubOAuthFetchUser($accessToken);
    if ($ghUser === null) {
        oauthFail($mode, 'Could not fetch your GitHub account. Please try again.');
    }

    if ($mode === 'connect') {
        $userid = (int) ($pending['userid'] ?? 0);
        $session = $db->prepare('SELECT s.userid FROM sessions s JOIN users u ON u.userid = s.userid WHERE s.token_hash = :hash AND s.expires_at > NOW() AND u.isactive = 1');
        $session->execute([':hash' => $pending['session_hash'] ?? '']);
        if ((int) $session->fetchColumn() !== $userid) {
            oauthFail($mode, 'Please sign in again before connecting GitHub.');
        }
        if ($userid <= 0) {
            oauthFail($mode, 'Could not identify the account to link. Please try again.');
        }

        $stmt = $db->prepare('SELECT userid FROM github_profiles WHERE github_id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $ghUser['id']]);
        $row = $stmt->fetch();
        if ($row && (int) $row['userid'] !== $userid) {
            oauthFail($mode, 'This GitHub account is already linked to another account.');
        }

        try {
            linkGithubAccount($db, $userid, $ghUser);
        } catch (DomainException $e) {
            oauthFail($mode, $e->getMessage());
        } catch (RuntimeException $e) {
            error_log('GitHub OAuth link failed: ' . $e->getMessage());
            oauthFail($mode, 'Could not link your GitHub account. Please try again.');
        }

        oauthSetCookie('oauth_state', '', 0);
        oauthSetCookie('oauth_userid', '', 0);
        header('Location: ' . appBaseUrl() . '/#/settings?tab=github');
        exit;
    }

    // Sign in / sign up.
    try {
        $userid = findOrCreateOAuthUser($db, $ghUser);
    } catch (DomainException $e) {
        oauthFail($mode, $e->getMessage());
    } catch (RuntimeException $e) {
        error_log('GitHub OAuth sign-in failed: ' . $e->getMessage());
        oauthFail($mode, 'Could not sign in with GitHub. Please try again.');
    }

    $stmt = $db->prepare('SELECT isactive FROM users WHERE userid = :id LIMIT 1');
    $stmt->execute([':id' => $userid]);
    $u = $stmt->fetch();
    if (!$u || (int) $u['isactive'] !== 1) {
        oauthFail($mode, 'This account has been disabled.');
    }

    $token = issueToken($db, $userid);

    oauthSetCookie('oauth_state', '', 0);
    header('Location: ' . appBaseUrl() . '/#/oauth?token=' . $token);
    exit;
}

/**
 * Unlinks the authenticated user's GitHub account.
 *
 * @param PDO $db
 */
function oauthDisconnect($db) {
    $userid = requireAuth();

    $check = $db->prepare('SELECT password FROM users WHERE userid = :userid');
    $check->execute([':userid' => $userid]);
    if (!$check->fetchColumn()) {
        respond(409, ['error' => 'Set a password through an administrator before disconnecting your only sign-in method.']);
    }

    $stmt = $db->prepare('DELETE FROM github_profiles WHERE userid = :userid');
    $stmt->execute([':userid' => $userid]);

    respond(200, ['data' => ['message' => 'GitHub account disconnected']]);
}
