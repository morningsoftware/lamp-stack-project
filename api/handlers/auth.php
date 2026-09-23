<?php
// ============================================================
//  api/handlers/auth.php — Registration, login, logout, session,
//  account updates and password resets
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/github.php';

$db       = getDB();
$segments = pathSegments();
$action   = $segments[1] ?? '';

switch ($action) {
    case 'register':
        requireMethod('POST');
        registerUser($db);
        break;

    case 'login':
        requireMethod('POST');
        loginUser($db);
        break;

    case 'logout':
        requireMethod('POST');
        logoutUser($db);
        break;

    case 'session':
        requireMethod('GET');
        currentSession($db);
        break;

    case 'account':
        requireMethod('PUT');
        updateAccount($db);
        break;

    case 'reset':
        if (requestMethod() === 'GET') {
            validateResetToken($db);
        } else {
            requireMethod('POST');
            performReset($db);
        }
        break;

    default:
        respond(404, ['error' => 'Unknown auth action']);
}

/**
 * Creates a user account linked to a GitHub account, then returns a
 * session token. Profile data lives on the users row; the contacts
 * table is the user's address book and is not touched here.
 *
 * @param PDO $db
 */
function registerUser($db) {
    $body = getRequestBody();
    requireFields($body, ['login', 'email', 'password', 'githubUsername']);

    $login    = clean($body['login']);
    $email    = requireEmail($body['email']);
    $password = (string) $body['password'];
    $github   = clean($body['githubUsername']);

    if (strlen($login) < 3 || strlen($login) > 50) {
        respond(400, ['error' => 'Login must be between 3 and 50 characters']);
    }
    if (strlen($password) < 8) {
        respond(400, ['error' => 'Password must be at least 8 characters']);
    }
    if (!githubUsernameValid($github)) {
        respond(400, ['error' => 'A valid GitHub username is required']);
    }

    // GitHub is required at signup, so verify it before creating the account.
    $lookup = githubFetch("https://api.github.com/users/{$github}");
    if ($lookup['status'] === 404) {
        respond(400, ['error' => 'GitHub user not found']);
    }
    if ($lookup['status'] < 200 || $lookup['status'] >= 300 || !isset($lookup['data']['login'])) {
        respond(503, ['error' => 'Could not verify GitHub account, please try again']);
    }
    $ghProfile = $lookup['data'];

    $firstName = isset($body['firstName']) ? clean($body['firstName']) : '';
    $lastName  = isset($body['lastName']) ? clean($body['lastName']) : '';

    $display = isset($body['displayName']) && clean($body['displayName']) !== ''
        ? clean($body['displayName'])
        : trim("{$firstName} {$lastName}");
    if ($display === '') {
        $display = $login;
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'INSERT INTO users (loginuid, email, password, firstname, lastname, displayname)
             VALUES (:login, :email, :hash, :first, :last, :display)'
        );
        $stmt->execute([
            ':login'   => $login,
            ':email'   => $email,
            ':hash'    => password_hash($password, PASSWORD_BCRYPT),
            ':first'   => $firstName,
            ':last'    => $lastName,
            ':display' => $display,
        ]);
        $userid = (int) $db->lastInsertId();

        // Link the GitHub account immediately so it cannot be claimed twice.
        $githubStub = $db->prepare(
            'INSERT INTO github_profiles (userid, username) VALUES (:userid, :username)'
        );
        $githubStub->execute([':userid' => $userid, ':username' => $ghProfile['login']]);

        $token = issueToken($db, $userid);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e->getCode() === '23000') {
            respond(409, ['error' => 'Login, email, or GitHub account is already registered']);
        }
        error_log('Registration error: ' . $e->getMessage());
        respond(500, ['error' => 'Registration failed']);
    }

    // Best-effort initial sync; a failure does not block account creation.
    $githubSynced = false;
    try {
        syncGithubForUser($db, $userid, $ghProfile['login'], $ghProfile);
        $githubSynced = true;
    } catch (RuntimeException $e) {
        error_log('Initial GitHub sync failed: ' . $e->getMessage());
    }

    respond(201, ['data' => [
        'userid'       => $userid,
        'login'        => $login,
        'email'        => $email,
        'token'        => $token,
        'githubSynced' => $githubSynced,
    ]]);
}

/**
 * Verifies credentials and returns a session token.
 *
 * @param PDO $db
 */
function loginUser($db) {
    $body = getRequestBody();
    $identifier = $body['login'] ?? $body['email'] ?? null;
    $password   = $body['password'] ?? null;

    if (!$identifier || $password === null) {
        respond(400, ['error' => 'Login and password are required']);
    }

    $identifier = clean($identifier);
    $stmt = $db->prepare(
        'SELECT userid, loginuid, email, password, isactive, isadmin
         FROM users
         WHERE loginuid = :login OR email = :email
         LIMIT 1'
    );
    $stmt->execute([':login' => $identifier, ':email' => $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify((string) $password, $user['password'])) {
        respond(401, ['error' => 'Invalid login or password']);
    }
    if ((int) $user['isactive'] !== 1) {
        respond(403, ['error' => 'This account has been disabled']);
    }

    $token = issueToken($db, (int) $user['userid']);

    respond(200, ['data' => [
        'userid'  => (int) $user['userid'],
        'login'   => $user['loginuid'],
        'email'   => $user['email'],
        'isAdmin' => (int) $user['isadmin'] === 1,
        'token'   => $token,
    ]]);
}

/**
 * Revokes the current session token.
 *
 * @param PDO $db
 */
function logoutUser($db) {
    requireAuth();
    $token = bearerToken();

    $stmt = $db->prepare('DELETE FROM sessions WHERE token_hash = :hash');
    $stmt->execute([':hash' => hashToken($token)]);

    respond(200, ['data' => ['message' => 'Logged out']]);
}

/**
 * Returns the currently authenticated user with profile summary.
 *
 * @param PDO $db
 */
function currentSession($db) {
    $userid = requireAuth();

    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, u.email, u.firstname, u.lastname,
                u.displayname, u.avatar, u.isadmin,
                gp.avatar_url AS github_avatar
         FROM users u
         LEFT JOIN github_profiles gp ON gp.userid = u.userid
         WHERE u.userid = :userid'
    );
    $stmt->execute([':userid' => $userid]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(404, ['error' => 'User not found']);
    }

    respond(200, ['data' => [
        'userid'      => (int) $user['userid'],
        'login'       => $user['loginuid'],
        'email'       => $user['email'],
        'firstName'   => $user['firstname'],
        'lastName'    => $user['lastname'],
        'displayName' => $user['displayname'],
        'avatarUrl'   => $user['github_avatar'] ?: $user['avatar'],
        'isAdmin'     => (int) $user['isadmin'] === 1,
    ]]);
}

/**
 * Updates the authenticated user's email and/or password.
 *
 * @param PDO $db
 */
function updateAccount($db) {
    $userid = requireAuth();
    $body   = getRequestBody();

    $current = isset($body['currentPassword']) ? (string) $body['currentPassword'] : '';
    if ($current === '') {
        respond(400, ['error' => 'Current password is required']);
    }

    $stmt = $db->prepare('SELECT password FROM users WHERE userid = :userid');
    $stmt->execute([':userid' => $userid]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password'])) {
        respond(401, ['error' => 'Current password is incorrect']);
    }

    $sets   = [];
    $params = [':userid' => $userid];

    if (isset($body['email'])) {
        $sets[] = 'email = :email';
        $params[':email'] = requireEmail($body['email']);
    }
    if (isset($body['newPassword']) && (string) $body['newPassword'] !== '') {
        $new = (string) $body['newPassword'];
        if (strlen($new) < 8) {
            respond(400, ['error' => 'Password must be at least 8 characters']);
        }
        $sets[] = 'password = :password';
        $params[':password'] = password_hash($new, PASSWORD_BCRYPT);
    }

    if (!$sets) {
        respond(400, ['error' => 'No changes provided']);
    }

    try {
        $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE userid = :userid')
           ->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            respond(409, ['error' => 'Email is already registered']);
        }
        error_log('Update account error: ' . $e->getMessage());
        respond(500, ['error' => 'Could not update account']);
    }

    respond(200, ['data' => ['message' => 'Account updated']]);
}

/**
 * Validates a password reset token.
 *
 * @param PDO $db
 */
function validateResetToken($db) {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($token === '') {
        respond(400, ['error' => 'A reset token is required']);
    }

    $stmt = $db->prepare(
        'SELECT 1 FROM password_resets WHERE token_hash = :hash AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([':hash' => hashToken($token)]);

    if (!$stmt->fetch()) {
        respond(400, ['error' => 'This reset link is invalid or has expired']);
    }

    respond(200, ['data' => ['valid' => true]]);
}

/**
 * Consumes a password reset token and sets a new password.
 *
 * @param PDO $db
 */
function performReset($db) {
    $body  = getRequestBody();
    $token = isset($body['token']) ? (string) $body['token'] : '';
    $new   = isset($body['newPassword']) ? (string) $body['newPassword'] : '';

    if ($token === '' || $new === '') {
        respond(400, ['error' => 'Token and new password are required']);
    }
    if (strlen($new) < 8) {
        respond(400, ['error' => 'Password must be at least 8 characters']);
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'SELECT userid FROM password_resets WHERE token_hash = :hash AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':hash' => hashToken($token)]);
        $row = $stmt->fetch();

        if (!$row) {
            $db->rollBack();
            respond(400, ['error' => 'This reset link is invalid or has expired']);
        }
        $resetUser = (int) $row['userid'];

        $db->prepare('UPDATE users SET password = :password WHERE userid = :userid')
           ->execute([':password' => password_hash($new, PASSWORD_BCRYPT), ':userid' => $resetUser]);

        $db->prepare('DELETE FROM password_resets WHERE userid = :userid')
           ->execute([':userid' => $resetUser]);
        $db->prepare('DELETE FROM sessions WHERE userid = :userid')
           ->execute([':userid' => $resetUser]);

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Password reset error: ' . $e->getMessage());
        respond(500, ['error' => 'Password reset failed']);
    }

    respond(200, ['data' => ['message' => 'Password updated']]);
}
