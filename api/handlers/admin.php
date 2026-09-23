<?php
// ============================================================
//  api/handlers/admin.php — Admin dashboard: stats, account
//  management and password resets
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/github.php';

$db       = getDB();
$adminId  = requireAdmin();
$segments = pathSegments();
$resource = $segments[1] ?? '';
$id       = $segments[2] ?? null;
$action   = $segments[3] ?? null;

if ($resource === 'stats') {
    requireMethod('GET');
    adminStats($db);
}

if ($resource === 'users') {
    if ($id === null) {
        requireMethod('GET');
        listUsers($db);
    }

    $targetId = requireId($id, 'user id');

    if ($action === 'disable') {
        requireMethod('POST');
        setAccountActive($db, $adminId, $targetId, false);
    }
    if ($action === 'enable') {
        requireMethod('POST');
        setAccountActive($db, $adminId, $targetId, true);
    }
    if ($action === 'reset-password') {
        requireMethod('POST');
        issuePasswordReset($db, $adminId, $targetId);
    }

    respond(404, ['error' => 'Unknown admin user action']);
}

respond(404, ['error' => 'Unknown admin action']);

/**
 * Returns dashboard statistics.
 *
 * @param PDO $db
 */
function adminStats($db) {
    $scalar = function ($sql, $params = []) use ($db) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };

    $hours = githubRefreshHours();

    respond(200, ['data' => [
        'users' => [
            'total'    => $scalar('SELECT COUNT(*) FROM users'),
            'active'   => $scalar('SELECT COUNT(*) FROM users WHERE isactive = 1'),
            'inactive' => $scalar('SELECT COUNT(*) FROM users WHERE isactive = 0'),
            'admins'   => $scalar('SELECT COUNT(*) FROM users WHERE isadmin = 1'),
            'new7'     => $scalar('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY'),
            'new30'    => $scalar('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY'),
        ],
        'github' => [
            'linked' => $scalar('SELECT COUNT(*) FROM github_profiles'),
            'stale'  => $scalar(
                'SELECT COUNT(*) FROM github_profiles
                 WHERE last_synced IS NULL OR last_synced < (NOW() - INTERVAL :hours HOUR)',
                [':hours' => $hours]
            ),
            'repos'  => $scalar('SELECT COUNT(*) FROM github_repositories'),
        ],
        'skills' => [
            'catalog' => $scalar('SELECT COUNT(*) FROM skills'),
            'assigned' => $scalar('SELECT COUNT(*) FROM user_skills'),
            'top' => $db->query(
                'SELECT s.name, COUNT(us.userid) AS developers
                 FROM skills s JOIN user_skills us ON us.skillid = s.skillid
                 GROUP BY s.skillid, s.name
                 ORDER BY developers DESC, s.name ASC
                 LIMIT 8'
            )->fetchAll(),
        ],
        'languages' => $db->query(
            "SELECT gr.language, COUNT(DISTINCT g.userid) AS developers
             FROM github_repositories gr JOIN github_profiles g ON g.githubid = gr.githubid
             WHERE gr.language IS NOT NULL AND gr.language <> ''
             GROUP BY gr.language
             ORDER BY developers DESC, gr.language ASC
             LIMIT 8"
        )->fetchAll(),
        'social' => [
            'links' => $scalar('SELECT COUNT(*) FROM social_links'),
        ],
        'messaging' => [
            'conversations' => $scalar('SELECT COUNT(*) FROM conversations'),
            'messages'      => $scalar('SELECT COUNT(*) FROM messages'),
        ],
        'recentSignups' => $db->query(
            'SELECT userid, loginuid, displayname, email, isactive, created_at
             FROM users
             ORDER BY created_at DESC, userid DESC
             LIMIT 8'
        )->fetchAll(),
    ]]);
}

/**
 * Lists users with optional search and status filtering.
 *
 * @param PDO $db
 */
function listUsers($db) {
    $q      = isset($_GET['q']) ? clean($_GET['q']) : '';
    $status = isset($_GET['status']) ? clean($_GET['status']) : '';
    $page   = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit  = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 25;
    $offset = ($page - 1) * $limit;

    $where  = [];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(u.loginuid LIKE :q1 OR u.email LIKE :q2
                     OR u.displayname LIKE :q3 OR u.firstname LIKE :q4 OR u.lastname LIKE :q5)';
        $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
    }

    if ($status === 'active') {
        $where[] = 'u.isactive = 1';
    } elseif ($status === 'inactive') {
        $where[] = 'u.isactive = 0';
    } elseif ($status === 'admin') {
        $where[] = 'u.isadmin = 1';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare('SELECT COUNT(*) FROM users u' . $whereSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, u.email, u.firstname, u.lastname,
                u.displayname, u.isactive, u.isadmin, u.created_at,
                gp.username AS github_username,
                (SELECT COUNT(*) FROM contacts c WHERE c.userid = u.userid) AS contact_count
         FROM users u
         LEFT JOIN github_profiles gp ON gp.userid = u.userid' .
        $whereSql .
        ' ORDER BY u.created_at DESC, u.userid DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = array_map(function ($row) {
        return [
            'userid'        => (int) $row['userid'],
            'login'         => $row['loginuid'],
            'email'         => $row['email'],
            'firstName'     => $row['firstname'],
            'lastName'      => $row['lastname'],
            'displayName'   => $row['displayname'],
            'isActive'      => (int) $row['isactive'] === 1,
            'isAdmin'       => (int) $row['isadmin'] === 1,
            'githubUsername' => $row['github_username'],
            'contactCount'  => (int) $row['contact_count'],
            'createdAt'     => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    respond(200, ['data' => $data, 'meta' => [
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
    ]]);
}

/**
 * Enables or disables an account, purging sessions when disabling.
 *
 * @param PDO $db
 * @param int $adminId
 * @param int $targetId
 * @param bool $active
 */
function setAccountActive($db, $adminId, $targetId, $active) {
    if (!$active && $targetId === $adminId) {
        respond(400, ['error' => 'You cannot disable your own account']);
    }

    $exists = $db->prepare('SELECT userid FROM users WHERE userid = :id');
    $exists->execute([':id' => $targetId]);
    if (!$exists->fetch()) {
        respond(404, ['error' => 'User not found']);
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare('UPDATE users SET isactive = :active WHERE userid = :id');
        $stmt->execute([':active' => $active ? 1 : 0, ':id' => $targetId]);

        if (!$active) {
            $db->prepare('DELETE FROM sessions WHERE userid = :id')->execute([':id' => $targetId]);
            $db->prepare('DELETE FROM password_resets WHERE userid = :id')->execute([':id' => $targetId]);
        }

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Set account active error: ' . $e->getMessage());
        respond(500, ['error' => 'Could not update account']);
    }

    respond(200, ['data' => ['userid' => $targetId, 'isActive' => (bool) $active]]);
}

/**
 * Issues a single-use password reset link for a user.
 *
 * @param PDO $db
 * @param int $adminId
 * @param int $targetId
 */
function issuePasswordReset($db, $adminId, $targetId) {
    $exists = $db->prepare('SELECT userid FROM users WHERE userid = :id');
    $exists->execute([':id' => $targetId]);
    if (!$exists->fetch()) {
        respond(404, ['error' => 'User not found']);
    }

    $token = bin2hex(random_bytes(32));

    try {
        $db->beginTransaction();

        $db->prepare('DELETE FROM password_resets WHERE userid = :id')->execute([':id' => $targetId]);

        $stmt = $db->prepare(
            'INSERT INTO password_resets (token_hash, userid, expires_at, created_by)
             VALUES (:hash, :userid, DATE_ADD(NOW(), INTERVAL 1 HOUR), :admin)'
        );
        $stmt->execute([
            ':hash'   => hashToken($token),
            ':userid' => $targetId,
            ':admin'  => $adminId,
        ]);

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Issue reset error: ' . $e->getMessage());
        respond(500, ['error' => 'Could not issue reset link']);
    }

    respond(201, ['data' => [
        'userid'   => $targetId,
        'resetUrl' => appBaseUrl() . '/index.html#/reset?token=' . $token,
        'expiresInMinutes' => 60,
    ]]);
}
