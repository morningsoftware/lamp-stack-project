<?php
// ============================================================
//  api/config/helpers.php — Utility & Helper Functions for API
// ============================================================

/**
 * Loads environment variables from a .env file into putenv, $_ENV, and $_SERVER.
 *
 * @param string|null $path Path to the .env file
 */
function loadEnv($path = null) {
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if ($path === null) {
        $possiblePaths = [
            __DIR__ . '/../../.env',
            __DIR__ . '/../.env',
            __DIR__ . '/.env',
            (defined('ROOT_PATH') ? ROOT_PATH . '/.env' : null),
        ];
        foreach ($possiblePaths as $p) {
            if ($p && file_exists($p)) {
                $path = $p;
                break;
            }
        }
    }

    if ($path && file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name  = trim($name);
                $value = trim($value);

                // Strip surrounding quotes
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                if (getenv($name) === false) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
    $loaded = true;
}

// Automatically load environment variables
loadEnv();

/**
 * Sets standard CORS headers to allow cross-origin API requests.
 * Handles preflight OPTIONS requests by exiting with 200 OK.
 */
function setCORSHeaders() {
    $allowedOrigin = getenv('CORS_ALLOWED_ORIGIN') ?: '*';
    header("Access-Control-Allow-Origin: {$allowedOrigin}");
    header('Vary: Origin');
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Sends a JSON response with the specified HTTP status code and terminates execution.
 *
 * @param int $statusCode HTTP status code (e.g. 200, 201, 400, 404, 405, 500)
 * @param mixed $data Data array or object to serialize as JSON
 */
function respond($statusCode, $data) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Gets and decodes the JSON request body or falls back to $_POST input.
 *
 * @return array
 */
function getRequestBody() {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST ?? [];
}

/**
 * Sanitizes input data by trimming whitespace and stripping HTML tags.
 *
 * @param mixed $data
 * @return mixed
 */
function clean($data) {
    if (is_string($data)) {
        return trim(strip_tags($data));
    }
    return $data;
}

/**
 * Returns the request path split into segments, e.g. /profiles/3 -> ['profiles', '3'].
 *
 * @return array
 */
function pathSegments() {
    $path = trim($_SERVER['PATH_INFO'] ?? $_GET['route'] ?? '', '/');
    return $path === '' ? [] : explode('/', $path);
}

/**
 * Returns the uppercased HTTP request method.
 *
 * @return string
 */
function requestMethod() {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Ensures the request uses one of the allowed HTTP methods.
 *
 * @param string ...$allowed
 */
function requireMethod(...$allowed) {
    if (!in_array(requestMethod(), $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        respond(405, ['error' => 'Method not allowed']);
    }
}

/**
 * Ensures the decoded request body contains the given non-empty fields.
 *
 * @param array $data
 * @param string[] $fields
 */
function requireFields($data, $fields) {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || clean($data[$field]) === '') {
            $missing[] = $field;
        }
    }
    if ($missing) {
        respond(400, ['error' => 'Missing required field(s): ' . implode(', ', $missing)]);
    }
}

/**
 * Validates and normalizes an email address, or responds with 400.
 *
 * @param mixed $email
 * @return string
 */
function requireEmail($email) {
    $email = clean((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ['error' => 'A valid email address is required']);
    }
    return $email;
}

/**
 * Parses a positive integer path parameter, or responds with 400.
 *
 * @param mixed $value
 * @param string $label
 * @return int
 */
function requireId($value, $label = 'id') {
    if (!is_numeric($value) || (int) $value <= 0) {
        respond(400, ['error' => "Invalid {$label}"]);
    }
    return (int) $value;
}

/**
 * Reads the bearer token from the request, if present.
 *
 * @return string|null
 */
function bearerToken() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? null) : null);

    if (!$authHeader) {
        return null;
    }

    $token = trim(preg_replace('/^Bearer\s+/i', '', $authHeader));
    return $token !== '' ? $token : null;
}

/**
 * Hashes a bearer token for storage/lookup.
 *
 * @param string $token
 * @return string
 */
function hashToken($token) {
    return hash('sha256', $token);
}

/**
 * Resolves the authenticated user from a valid, unexpired session token.
 * Returns null when no valid session is present.
 *
 * @return int|null
 */
function optionalAuth() {
    static $resolved = false;
    static $userId = null;

    if ($resolved) {
        return $userId;
    }
    $resolved = true;

    $token = bearerToken();
    if ($token === null) {
        return null;
    }

    require_once __DIR__ . '/db.php';
    $stmt = getDB()->prepare(
        'SELECT s.userid
         FROM sessions s
         JOIN users u ON u.userid = s.userid
         WHERE s.token_hash = :hash AND s.expires_at > NOW() AND u.isactive = 1
         LIMIT 1'
    );
    $stmt->execute([':hash' => hashToken($token)]);
    $row = $stmt->fetch();

    if ($row) {
        $userId = (int) $row['userid'];
    }

    return $userId;
}

/**
 * Requires authentication and returns the authenticated User ID.
 * Validates the bearer token against the sessions table; never trusts
 * a client-supplied user id.
 *
 * @return int User ID
 */
function requireAuth() {
    $userId = optionalAuth();
    if ($userId === null) {
        respond(401, ['error' => 'Unauthorized']);
    }
    return $userId;
}

/**
 * Requires that the authenticated user matches the given user id.
 *
 * @param int $userid
 * @return int The authenticated user id
 */
function requireOwnership($userid) {
    $authUserId = requireAuth();
    if ($authUserId !== (int) $userid) {
        respond(403, ['error' => 'Forbidden']);
    }
    return $authUserId;
}

/**
 * Requires an authenticated user with the admin flag set.
 *
 * @return int The authenticated admin's user id
 */
function requireAdmin() {
    $userId = requireAuth();

    require_once __DIR__ . '/db.php';
    $stmt = getDB()->prepare('SELECT isadmin, isactive FROM users WHERE userid = :userid LIMIT 1');
    $stmt->execute([':userid' => $userId]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['isadmin'] !== 1 || (int) $row['isactive'] !== 1) {
        respond(403, ['error' => 'Admin access required']);
    }
    return $userId;
}

/**
 * Creates a new session for the user and returns the plaintext token.
 * Only the token hash is stored.
 *
 * @param PDO $db
 * @param int $userid
 * @return string
 */
function issueToken($db, $userid) {
    $token  = bin2hex(random_bytes(32));
    $ttlDays = (int) (getenv('SESSION_TTL_DAYS') ?: 7);

    $stmt = $db->prepare(
        'INSERT INTO sessions (token_hash, userid, expires_at)
         VALUES (:hash, :userid, DATE_ADD(NOW(), INTERVAL :days DAY))'
    );
    $stmt->bindValue(':hash', hashToken($token));
    $stmt->bindValue(':userid', $userid, PDO::PARAM_INT);
    $stmt->bindValue(':days', $ttlDays, PDO::PARAM_INT);
    $stmt->execute();

    return $token;
}

/**
 * Public shape of a user record (never exposes password_hash).
 *
 * @param array $row
 * @return array
 */
function publicUser($row) {
    return [
        'userid'    => (int) $row['userid'],
        'login'     => $row['loginuid'],
        'email'     => $row['email'] ?? null,
        'firstName' => $row['firstname'] ?? null,
        'lastName'  => $row['lastname'] ?? null,
    ];
}

/**
 * Returns the configured public base URL of the front-end.
 *
 * @return string
 */
function appBaseUrl() {
    $base = getenv('APP_BASE_URL') ?: '';
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = $scheme . '://' . $host;
    }
    return rtrim($base, '/');
}
