<?php
// ============================================================
//  api/handlers/auth.php — Registration, login, logout, session
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

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

    default:
        respond(404, ['error' => 'Unknown auth action']);
}

/**
 * Creates a user and profile, then returns a session token.
 *
 * @param PDO $db
 */
function registerUser($db) {
    $body = getRequestBody();
    requireFields($body, ['login', 'email', 'password']);

    $login    = clean($body['login']);
    $email    = requireEmail($body['email']);
    $password = (string) $body['password'];

    if (strlen($login) < 3 || strlen($login) > 50) {
        respond(400, ['error' => 'Login must be between 3 and 50 characters']);
    }
    if (strlen($password) < 8) {
        respond(400, ['error' => 'Password must be at least 8 characters']);
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'INSERT INTO users (loginuid, email, password_hash) VALUES (:login, :email, :hash)'
        );
        $stmt->execute([
            ':login' => $login,
            ':email' => $email,
            ':hash'  => password_hash($password, PASSWORD_BCRYPT),
        ]);
        $userid = (int) $db->lastInsertId();

        $profile = $db->prepare(
            'INSERT INTO profiles (userid, firstname, lastname, display_name)
             VALUES (:userid, :first, :last, :display)'
        );
        $firstName = isset($body['firstName']) ? clean($body['firstName']) : null;
        $lastName  = isset($body['lastName']) ? clean($body['lastName']) : null;
        $display   = $firstName !== null || $lastName !== null
            ? trim("{$firstName} {$lastName}")
            : $login;
        $profile->execute([
            ':userid'  => $userid,
            ':first'   => $firstName,
            ':last'    => $lastName,
            ':display' => $display,
        ]);

        $token = issueToken($db, $userid);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e->getCode() === '23000') {
            respond(409, ['error' => 'Login or email is already registered']);
        }
        error_log('Registration error: ' . $e->getMessage());
        respond(500, ['error' => 'Registration failed']);
    }

    respond(201, ['data' => [
        'userid' => $userid,
        'login'  => $login,
        'email'  => $email,
        'token'  => $token,
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
        'SELECT userid, loginuid, email, password_hash
         FROM users
         WHERE loginuid = :login OR email = :email
         LIMIT 1'
    );
    $stmt->execute([':login' => $identifier, ':email' => $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify((string) $password, $user['password_hash'])) {
        respond(401, ['error' => 'Invalid login or password']);
    }

    $token = issueToken($db, (int) $user['userid']);

    respond(200, ['data' => [
        'userid' => (int) $user['userid'],
        'login'  => $user['loginuid'],
        'email'  => $user['email'],
        'token'  => $token,
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
        'SELECT u.userid, u.loginuid, u.email, p.firstname, p.lastname,
                p.display_name, p.avatar_url
         FROM users u
         LEFT JOIN profiles p ON p.userid = u.userid
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
        'displayName' => $user['display_name'],
        'avatarUrl'   => $user['avatar_url'],
    ]]);
}
