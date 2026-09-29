<?php
// ============================================================
//  api/config/oauth.php — GitHub OAuth account linking/sign-in
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/github.php';

/**
 * Returns the configured GitHub OAuth client id.
 *
 * @return string
 */
function githubOAuthClientId() {
    return (string) (getenv('GITHUB_OAUTH_CLIENT_ID') ?: '');
}

/**
 * Returns the configured GitHub OAuth client secret.
 *
 * @return string
 */
function githubOAuthClientSecret() {
    return (string) (getenv('GITHUB_OAUTH_CLIENT_SECRET') ?: '');
}

/**
 * Returns the OAuth redirect/callback URL.
 *
 * @return string
 */
function githubOAuthRedirectUri() {
    return (string) (getenv('GITHUB_OAUTH_REDIRECT_URI') ?: '');
}

/**
 * Builds the GitHub OAuth authorize URL for a given state token.
 *
 * @param string $state
 * @return string
 */
function githubOAuthAuthorizeUrl($state) {
    return 'https://github.com/login/oauth/authorize?' . http_build_query([
        'client_id'    => githubOAuthClientId(),
        'redirect_uri' => githubOAuthRedirectUri(),
        'scope'        => 'read:user',
        'state'        => $state,
    ]);
}

/**
 * Exchanges an authorization code for a GitHub access token.
 * Returns the token, or null on failure.
 *
 * @param string $code
 * @return string|null
 */
function githubOAuthExchangeCode($code) {
    $ch = curl_init('https://github.com/login/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: collab.dev'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => githubOAuthClientId(),
            'client_secret' => githubOAuthClientSecret(),
            'code'          => $code,
            'redirect_uri'  => githubOAuthRedirectUri(),
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) && !empty($data['access_token']) ? (string) $data['access_token'] : null;
}

/**
 * Fetches the authenticated GitHub user for a given access token.
 * Returns the decoded user, or null on failure.
 *
 * @param string $accessToken
 * @return array|null
 */
function githubOAuthFetchUser($accessToken) {
    $ch = curl_init('https://api.github.com/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'User-Agent: collab.dev',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) && isset($data['id']) ? $data : null;
}

/**
 * Returns a unique loginuid derived from the GitHub login, appending a
 * numeric suffix if the base is already taken.
 *
 * @param PDO $db
 * @param string $login
 * @return string
 */
function oauthUniqueLogin($db, $login) {
    $base      = $login !== '' ? $login : 'user';
    $candidate = $base;
    $i         = 2;

    $stmt = $db->prepare('SELECT 1 FROM users WHERE loginuid = :login LIMIT 1');
    while (true) {
        $stmt->execute([':login' => $candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $base . $i;
        $i++;
    }
}

/**
 * Finds an existing user linked to a GitHub id, or creates a new account.
 * Matching is by GitHub id only (never by email).
 *
 * @param PDO $db
 * @param array $ghUser GitHub user payload from /user
 * @return int userid
 * @throws RuntimeException
 */
function findOrCreateOAuthUser($db, $ghUser) {
    $githubId = (int) $ghUser['id'];

    $stmt = $db->prepare('SELECT userid FROM github_profiles WHERE github_id = :id LIMIT 1');
    $stmt->execute([':id' => $githubId]);
    $row = $stmt->fetch();
    if ($row) {
        return (int) $row['userid'];
    }

    $ghLogin = clean((string) $ghUser['login']);
    $legacy = $db->prepare('SELECT userid FROM github_profiles WHERE username = :username AND github_id IS NULL');
    $legacy->execute([':username' => $ghLogin]);
    if ($legacy->fetch()) {
        throw new DomainException('This GitHub username is already on an existing account. Sign in with your username and password, then connect GitHub in Settings to enable GitHub sign-in.');
    }

    $email   = isset($ghUser['email']) ? clean((string) $ghUser['email']) : '';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    $name    = isset($ghUser['name']) ? clean((string) $ghUser['name']) : '';
    $display = $name !== '' ? $name : $ghLogin;
    $avatar  = isset($ghUser['avatar_url']) ? clean((string) $ghUser['avatar_url']) : null;
    $url     = isset($ghUser['html_url']) ? clean((string) $ghUser['html_url']) : null;

    // Never collide with an existing email; GitHub id is the identity.
    if ($email !== '') {
        $dup = $db->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $dup->execute([':email' => $email]);
        if ($dup->fetch()) {
            $email = '';
        }
    }

    $login = oauthUniqueLogin($db, $ghLogin);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'INSERT INTO users (loginuid, email, password, firstname, lastname, displayname, avatar)
             VALUES (:login, :email, NULL, :first, :last, :display, :avatar)'
        );
        $stmt->execute([
            ':login'   => $login,
            ':email'   => $email !== '' ? $email : null,
            ':first'   => '',
            ':last'    => '',
            ':display' => $display,
            ':avatar'  => $avatar,
        ]);
        $userid = (int) $db->lastInsertId();

        $stmt = $db->prepare(
            'INSERT INTO github_profiles (userid, github_id, username, avatar_url, profile_url)
             VALUES (:userid, :github_id, :username, :avatar, :url)'
        );
        $stmt->execute([
            ':userid'    => $userid,
            ':github_id' => $githubId,
            ':username'  => $ghLogin,
            ':avatar'    => $avatar,
            ':url'       => $url,
        ]);

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('OAuth account creation failed: ' . $e->getMessage());
        throw new RuntimeException('Could not create account');
    }

    // Best-effort initial data sync (repositories + commit activity).
    try {
        syncGithubForUser($db, $userid, $ghLogin, $ghUser);
    } catch (RuntimeException $e) {
        error_log('Initial GitHub sync failed: ' . $e->getMessage());
    }

    return $userid;
}

/**
 * Links a verified GitHub identity to an existing user's profile and
 * triggers a data sync.
 *
 * @param PDO $db
 * @param int $userid
 * @param array $ghUser GitHub user payload from /user
 */
function linkGithubAccount($db, $userid, $ghUser) {
    try {
        $db->beginTransaction();
        $check = $db->prepare('SELECT userid FROM github_profiles WHERE (github_id = :github_id OR username = :username) AND userid <> :userid FOR UPDATE');
        $check->execute([':github_id' => (int) $ghUser['id'], ':username' => $ghUser['login'], ':userid' => $userid]);
        if ($check->fetch()) {
            throw new DomainException('This GitHub account is already associated with another account. Sign in to that account to connect it.');
        }
        $own = $db->prepare('SELECT github_id FROM github_profiles WHERE userid = :userid FOR UPDATE');
        $own->execute([':userid' => $userid]);
        $existing = $own->fetch();
        if ($existing && $existing['github_id'] !== null && (int) $existing['github_id'] !== (int) $ghUser['id']) {
            throw new DomainException('Disconnect the current GitHub account before connecting a different one.');
        }
        $sql = $existing
            ? 'UPDATE github_profiles SET github_id = :github_id, username = :username, avatar_url = :avatar, profile_url = :url WHERE userid = :userid'
            : 'INSERT INTO github_profiles (userid, github_id, username, avatar_url, profile_url) VALUES (:userid, :github_id, :username, :avatar, :url)';
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':userid' => $userid,
            ':github_id' => (int) $ghUser['id'],
            ':username' => clean((string) $ghUser['login']),
            ':avatar' => $ghUser['avatar_url'] ?? null,
            ':url' => $ghUser['html_url'] ?? null,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    try {
        syncGithubForUser($db, $userid, clean((string) $ghUser['login']), $ghUser);
    } catch (RuntimeException $e) {
        error_log('Post-OAuth GitHub sync failed: ' . $e->getMessage());
    }
}
