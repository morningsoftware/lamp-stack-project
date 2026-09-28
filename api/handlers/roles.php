<?php
// ============================================================
//  api/handlers/roles.php — Job listings and applications
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$db       = getDB();
$segments = pathSegments();
$id       = $segments[1] ?? null;
$sub      = $segments[2] ?? null;
$userid   = requireAuth();

if ($id === null) {
    if (requestMethod() === 'GET') {
        listRoles($db, $userid);
    }
    if (requestMethod() === 'POST') {
        createRole($db, $userid);
    }
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed']);
}

$roleid = requireId($id, 'role id');

if ($sub === null) {
    if (requestMethod() === 'GET') {
        getRole($db, $roleid, $userid);
    }
    if (requestMethod() === 'PUT') {
        updateRole($db, $roleid, $userid);
    }
    header('Allow: GET, PUT');
    respond(405, ['error' => 'Method not allowed']);
}

if ($sub === 'apply') {
    if (requestMethod() === 'POST') {
        applyToRole($db, $roleid, $userid);
    }
    if (requestMethod() === 'DELETE') {
        withdrawApplication($db, $roleid, $userid);
    }
    header('Allow: POST, DELETE');
    respond(405, ['error' => 'Method not allowed']);
}

if ($sub === 'applicants') {
    requireMethod('GET');
    listApplicants($db, $roleid, $userid);
}

respond(404, ['error' => 'Not found']);

function listRoles($db, $userid) {
    $status = isset($_GET['status']) ? clean($_GET['status']) : '';
    $q = isset($_GET['q']) ? clean($_GET['q']) : '';
    $organizationid = isset($_GET['organizationid']) ? (int) $_GET['organizationid'] : 0;

    if ($status !== '' && $status !== 'open' && $status !== 'closed') {
        respond(400, ['error' => 'Status must be open or closed']);
    }

    $sql = roleSelectSql() . ' WHERE 1 = 1';
    $params = [':me' => $userid, ':me_member' => $userid];
    if ($status !== '') {
        $sql .= ' AND r.status = :status';
        $params[':status'] = $status;
    }
    if ($organizationid > 0) {
        $sql .= ' AND r.organizationid = :org';
        $params[':org'] = $organizationid;
    }
    if ($q !== '') {
        $sql .= ' AND (r.name LIKE :q_name OR o.name LIKE :q_org OR r.description LIKE :q_desc)';
        $like = '%' . $q . '%';
        $params[':q_name'] = $like;
        $params[':q_org'] = $like;
        $params[':q_desc'] = $like;
    }
    $sql .= ' ORDER BY r.status = \'open\' DESC, r.created_at DESC LIMIT 100';

    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        roleFail($e, 'Could not load roles');
    }

    respond(200, ['data' => shapeRoles($db, $rows)]);
}

function createRole($db, $userid) {
    $body = getRequestBody();
    requireFields($body, ['organizationid', 'name']);
    $organizationid = requireId($body['organizationid'], 'organization id');
    requireMember($db, $organizationid, $userid);

    $name = clean($body['name']);
    if (strlen($name) > 100) {
        respond(400, ['error' => 'Role name must be 100 characters or fewer']);
    }
    $description = isset($body['description']) ? clean($body['description']) : '';
    $skillIds = skillIdsFromBody($db, $body);

    try {
        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO roles (organizationid, name, description, status, created_by)
             VALUES (:org, :name, :description, \'open\', :created_by)'
        );
        $stmt->execute([
            ':org'         => $organizationid,
            ':name'        => $name,
            ':description' => $description !== '' ? $description : null,
            ':created_by'  => $userid,
        ]);
        $roleid = (int) $db->lastInsertId();
        replaceRoleSkills($db, $roleid, $skillIds);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        roleFail($e, 'Could not post role');
    }

    respond(201, ['data' => ['roleid' => $roleid]]);
}

function getRole($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    $shaped = shapeRoles($db, [$row])[0];
    if ($shaped['canManage']) {
        $shaped['applicants'] = applicantRows($db, $roleid);
    }
    respond(200, ['data' => $shaped]);
}

function updateRole($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    if (!(int) $row['is_member']) {
        respond(403, ['error' => 'Only organization members can edit this role']);
    }

    $body = getRequestBody();
    $name = isset($body['name']) ? clean($body['name']) : $row['name'];
    if ($name === '') {
        respond(400, ['error' => 'Role name is required']);
    }
    $description = array_key_exists('description', $body) ? clean($body['description']) : (string) $row['description'];
    $status = isset($body['status']) ? clean($body['status']) : $row['status'];
    if ($status !== 'open' && $status !== 'closed') {
        respond(400, ['error' => 'Status must be open or closed']);
    }

    $skillIds = null;
    if (array_key_exists('skills', $body) || array_key_exists('skillIds', $body)) {
        $skillIds = skillIdsFromBody($db, $body);
    }

    try {
        $db->beginTransaction();
        if ($status === 'closed') {
            $stmt = $db->prepare(
                'UPDATE roles
                 SET name = :name, description = :description, status = :status,
                     closed_at = COALESCE(closed_at, NOW())
                 WHERE roleid = :id'
            );
        } else {
            $stmt = $db->prepare(
                'UPDATE roles
                 SET name = :name, description = :description, status = :status, closed_at = NULL
                 WHERE roleid = :id'
            );
        }
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description !== '' ? $description : null,
            ':status'      => $status,
            ':id'          => $roleid,
        ]);
        if ($skillIds !== null) {
            replaceRoleSkills($db, $roleid, $skillIds);
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        roleFail($e, 'Could not update role');
    }

    respond(200, ['data' => ['roleid' => $roleid, 'status' => $status]]);
}

function applyToRole($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    if ($row['status'] !== 'open') {
        respond(400, ['error' => 'This role is not accepting applications']);
    }
    if ((int) $row['is_member']) {
        respond(400, ['error' => 'Organization members cannot apply to their own role']);
    }
    if ((int) $row['applied']) {
        respond(200, ['data' => ['roleid' => $roleid, 'applied' => true]]);
    }

    try {
        $stmt = $db->prepare('INSERT INTO applications (roleid, userid) VALUES (:roleid, :userid)');
        $stmt->execute([':roleid' => $roleid, ':userid' => $userid]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            respond(200, ['data' => ['roleid' => $roleid, 'applied' => true]]);
        }
        roleFail($e, 'Could not apply');
    }

    respond(201, ['data' => ['roleid' => $roleid, 'applied' => true]]);
}

function withdrawApplication($db, $roleid, $userid) {
    fetchRole($db, $roleid, $userid);
    $stmt = $db->prepare('DELETE FROM applications WHERE roleid = :roleid AND userid = :userid');
    $stmt->execute([':roleid' => $roleid, ':userid' => $userid]);
    respond(200, ['data' => ['roleid' => $roleid, 'applied' => false]]);
}

function listApplicants($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    if (!(int) $row['is_member']) {
        respond(403, ['error' => 'Only organization members can view applicants']);
    }
    respond(200, ['data' => applicantRows($db, $roleid)]);
}

function roleSelectSql() {
    return 'SELECT r.roleid, r.organizationid, r.name, r.description, r.status, r.created_at, r.closed_at,
                   o.name AS org_name, o.slug AS org_slug,
                   (SELECT COUNT(*) FROM applications a WHERE a.roleid = r.roleid) AS applicant_count,
                   EXISTS(SELECT 1 FROM applications mine
                          WHERE mine.roleid = r.roleid AND mine.userid = :me) AS applied,
                   EXISTS(SELECT 1 FROM organization_members mem
                          WHERE mem.organizationid = r.organizationid AND mem.userid = :me_member) AS is_member
            FROM roles r
            JOIN organizations o ON o.organizationid = r.organizationid';
}

function fetchRole($db, $roleid, $userid) {
    try {
        $stmt = $db->prepare(roleSelectSql() . ' WHERE r.roleid = :id LIMIT 1');
        $stmt->bindValue(':me', $userid, PDO::PARAM_INT);
        $stmt->bindValue(':me_member', $userid, PDO::PARAM_INT);
        $stmt->bindValue(':id', $roleid, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        roleFail($e, 'Could not load role');
    }
    if (!$row) {
        respond(404, ['error' => 'Role not found']);
    }
    return $row;
}

function shapeRoles($db, $rows) {
    if (!$rows) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) $row['roleid'];
    }
    $skills = skillsForRoles($db, $ids);
    $shaped = [];
    foreach ($rows as $row) {
        $roleid = (int) $row['roleid'];
        $shaped[] = [
            'roleid'         => $roleid,
            'name'           => $row['name'],
            'description'    => $row['description'],
            'status'         => $row['status'],
            'statusLabel'    => $row['status'] === 'open' ? 'accepting applications' : 'closed',
            'createdAt'      => $row['created_at'],
            'closedAt'       => $row['closed_at'],
            'applicantCount' => (int) $row['applicant_count'],
            'applied'        => (int) $row['applied'] === 1,
            'canManage'      => (int) $row['is_member'] === 1,
            'organization'   => [
                'organizationid' => (int) $row['organizationid'],
                'name'           => $row['org_name'],
                'slug'           => $row['org_slug'],
            ],
            'skills'         => $skills[$roleid] ?? [],
        ];
    }
    return $shaped;
}

function skillsForRoles($db, $roleIds) {
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $stmt = $db->prepare(
        "SELECT rs.roleid, s.skillid, s.name
         FROM role_skills rs
         JOIN skills s ON s.skillid = rs.skillid
         WHERE rs.roleid IN ({$placeholders})
         ORDER BY s.name"
    );
    $stmt->execute($roleIds);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['roleid']][] = [
            'skillid' => (int) $row['skillid'],
            'name'    => $row['name'],
        ];
    }
    return $grouped;
}

function applicantRows($db, $roleid) {
    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, u.displayname, u.firstname, u.lastname, a.created_at
         FROM applications a
         JOIN users u ON u.userid = a.userid
         WHERE a.roleid = :id
         ORDER BY a.created_at DESC'
    );
    $stmt->execute([':id' => $roleid]);
    $people = [];
    foreach ($stmt->fetchAll() as $row) {
        $people[] = [
            'userid'      => (int) $row['userid'],
            'login'       => $row['loginuid'],
            'displayName' => $row['displayname'] ?: trim($row['firstname'] . ' ' . $row['lastname']),
            'appliedAt'   => $row['created_at'],
        ];
    }
    return $people;
}

function skillIdsFromBody($db, $body) {
    $ids = [];
    if (isset($body['skillIds']) && is_array($body['skillIds'])) {
        foreach ($body['skillIds'] as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    $names = [];
    if (isset($body['skills']) && is_string($body['skills'])) {
        foreach (explode(',', $body['skills']) as $part) {
            $part = clean($part);
            if ($part !== '') {
                $names[] = $part;
            }
        }
    }
    if ($names) {
        $lookup = $db->prepare('SELECT skillid FROM skills WHERE name = :name LIMIT 1');
        foreach ($names as $name) {
            $lookup->execute([':name' => $name]);
            $found = $lookup->fetch();
            if (!$found) {
                respond(400, ['error' => 'Unknown skill: ' . $name]);
            }
            $ids[(int) $found['skillid']] = (int) $found['skillid'];
        }
    }

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $check = $db->prepare("SELECT skillid FROM skills WHERE skillid IN ({$placeholders})");
        $check->execute(array_values($ids));
        $valid = [];
        foreach ($check->fetchAll() as $row) {
            $valid[(int) $row['skillid']] = (int) $row['skillid'];
        }
        if (count($valid) !== count($ids)) {
            respond(400, ['error' => 'One or more skills do not exist']);
        }
        return array_values($valid);
    }
    return [];
}

function replaceRoleSkills($db, $roleid, $skillIds) {
    $delete = $db->prepare('DELETE FROM role_skills WHERE roleid = :id');
    $delete->execute([':id' => $roleid]);
    if (!$skillIds) {
        return;
    }
    $insert = $db->prepare('INSERT INTO role_skills (roleid, skillid) VALUES (:roleid, :skillid)');
    foreach ($skillIds as $skillid) {
        $insert->execute([':roleid' => $roleid, ':skillid' => $skillid]);
    }
}

function requireMember($db, $organizationid, $userid) {
    $stmt = $db->prepare(
        'SELECT 1 FROM organization_members WHERE organizationid = :org AND userid = :userid LIMIT 1'
    );
    $stmt->execute([':org' => $organizationid, ':userid' => $userid]);
    if (!$stmt->fetch()) {
        respond(403, ['error' => 'Only organization members can post roles']);
    }
}

function roleFail($e, $fallback) {
    $missing = $e->getCode() === '42S02' || strpos($e->getMessage(), 'Base table or view not found') !== false;
    if ($missing) {
        respond(503, ['error' => 'Organization tables are not installed yet. Run migrate_organizations.sql on the database.']);
    }
    error_log('Role error: ' . $e->getMessage());
    respond(500, ['error' => $fallback]);
}
