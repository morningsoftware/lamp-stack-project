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
$subId    = $segments[3] ?? null;
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
    if (requestMethod() === 'DELETE') {
        deleteRole($db, $roleid, $userid);
    }
    header('Allow: GET, PUT, DELETE');
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
    if ($subId === null) {
        requireMethod('GET');
        listApplicants($db, $roleid, $userid);
    }
    requireMethod('PUT');
    decideApplication($db, $roleid, requireId($subId, 'user id'), $userid);
}

respond(404, ['error' => 'Not found']);

/**
 * Lists roles with status, search, organization and applied filters.
 *
 * @param PDO $db
 * @param int $userid
 */
function listRoles($db, $userid) {
    $status = isset($_GET['status']) ? clean($_GET['status']) : '';
    $q = isset($_GET['q']) ? clean($_GET['q']) : '';
    $organizationid = isset($_GET['organizationid']) ? (int) $_GET['organizationid'] : 0;

    if ($status !== '' && $status !== 'open' && $status !== 'closed') {
        respond(400, ['error' => 'Status must be open or closed']);
    }

    $ready = decisionReady($db);
    $sql = roleSelectSql($ready) . ' WHERE 1 = 1';
    $params = [':me' => $userid, ':me_member' => $userid];
    if ($ready) {
        $params[':me_invite'] = $userid;
    }
    if (isset($_GET['applied']) && $_GET['applied'] === '1') {
        $sql .= ' AND EXISTS (
                    SELECT 1 FROM applications applied_filter
                    WHERE applied_filter.roleid = r.roleid AND applied_filter.userid = :applied_user)';
        $params[':applied_user'] = $userid;
    }
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
    $skills = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($_GET['skill'] ?? ''))))));
    if ($skills) {
        $holders = [];
        foreach ($skills as $i => $skill) {
            $holders[] = ':skill' . $i;
            $params[':skill' . $i] = $skill;
        }
        $sql .= ' AND (SELECT COUNT(DISTINCT s.skillid) FROM role_skills rs JOIN skills s ON s.skillid=rs.skillid WHERE rs.roleid=r.roleid AND s.name IN (' . implode(',', $holders) . '))';
        $sql .= ($_GET['skillMode'] ?? '') === 'all' ? ' = ' . count($skills) : ' > 0';
    }
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 100)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $sql .= " ORDER BY r.status = 'open' DESC, r.created_at DESC, r.roleid DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

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

    respond(200, ['data' => shapeRoles($db, $rows, $userid)]);
}

/**
 * Posts a role for an organization the user belongs to.
 *
 * @param PDO $db
 * @param int $userid
 */
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

/**
 * Returns a single role, with applicants when the user can manage it.
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $userid
 */
function getRole($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    $shaped = shapeRoles($db, [$row], $userid)[0];
    if ($shaped['canManage']) {
        $shaped['applicants'] = applicantRows($db, $roleid);
    }
    respond(200, ['data' => $shaped]);
}

/**
 * Updates a role's name, description, status and skills (members only).
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $userid
 */
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

/**
 * Deletes a role and its applications (members only).
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $userid
 */
function deleteRole($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    if (!(int) $row['is_member']) {
        respond(403, ['error' => 'Only organization members can delete this role']);
    }
    try {
        $stmt = $db->prepare('DELETE FROM roles WHERE roleid = :id');
        $stmt->execute([':id' => $roleid]);
    } catch (PDOException $e) {
        roleFail($e, 'Could not delete role');
    }
    respond(200, ['data' => ['message' => 'Role deleted']]);
}

/**
 * Submits the current user's application to an open role.
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $userid
 */
function applyToRole($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    if ($row['status'] !== 'open') {
        respond(400, ['error' => 'This role is not accepting applications']);
    }
    if ((int) $row['is_member']) {
        respond(400, ['error' => 'Organization members cannot apply to their own role']);
    }
    if ((int) $row['applied']) {
        if (decisionReady($db) && ($row['application_decision'] ?? '') === 'rejected') {
            $reset = $db->prepare(
                'UPDATE applications
                 SET decision = \'pending\', decided_at = NULL, decided_by = NULL
                 WHERE roleid = :roleid AND userid = :userid AND decision = \'rejected\''
            );
            $reset->execute([':roleid' => $roleid, ':userid' => $userid]);
            respond(200, ['data' => ['roleid' => $roleid, 'applied' => true, 'decision' => 'pending']]);
        }
        respond(200, ['data' => [
            'roleid'   => $roleid,
            'applied'  => true,
            'decision' => $row['application_decision'] ?? 'pending',
        ]]);
    }

    try {
        if (decisionReady($db)) {
            $stmt = $db->prepare(
                'INSERT INTO applications (roleid, userid, decision) VALUES (:roleid, :userid, \'pending\')'
            );
        } else {
            $stmt = $db->prepare('INSERT INTO applications (roleid, userid) VALUES (:roleid, :userid)');
        }
        $stmt->execute([':roleid' => $roleid, ':userid' => $userid]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            respond(200, ['data' => ['roleid' => $roleid, 'applied' => true]]);
        }
        roleFail($e, 'Could not apply');
    }

    respond(201, ['data' => ['roleid' => $roleid, 'applied' => true]]);
}

/**
 * Withdraws the current user's pending application.
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $userid
 */
function withdrawApplication($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    if (decisionReady($db) && (int) $row['applied'] && ($row['application_decision'] ?? 'pending') !== 'pending') {
        respond(400, ['error' => 'This application has already been decided']);
    }
    $pending = decisionReady($db) ? ' AND decision = \'pending\'' : '';
    $stmt = $db->prepare('DELETE FROM applications WHERE roleid = :roleid AND userid = :userid' . $pending);
    $stmt->execute([':roleid' => $roleid, ':userid' => $userid]);
    respond(200, ['data' => ['roleid' => $roleid, 'applied' => false]]);
}

/**
 * Lists a role's applicants (members only).
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $userid
 */
function listApplicants($db, $roleid, $userid) {
    $row = fetchRole($db, $roleid, $userid);
    if (!(int) $row['is_member']) {
        respond(403, ['error' => 'Only organization members can view applicants']);
    }
    respond(200, ['data' => applicantRows($db, $roleid)]);
}

/**
 * Builds the role SELECT query, with decision columns when available.
 *
 * @param bool $withDecision Whether the applications table has decisions.
 * @return string
 */
function roleSelectSql($withDecision = true) {
    $decision = $withDecision
        ? 'applied_row.decision AS application_decision,
                   invite_row.invitationid AS invitationid,
                   invite_row.status AS invitation_status,'
        : '';
    $invite = $withDecision
        ? ' LEFT JOIN organization_invitations invite_row
              ON invite_row.organizationid = r.organizationid AND invite_row.userid = :me_invite'
        : '';
    return 'SELECT r.roleid, r.organizationid, r.name, r.description, r.status, r.created_by,
                   r.created_at, r.closed_at,
                   o.name AS org_name, o.slug AS org_slug,
                   (SELECT COUNT(*) FROM applications a WHERE a.roleid = r.roleid) AS applicant_count,
                   applied_row.created_at AS applied_at,
                   ' . $decision . '
                   applied_row.userid IS NOT NULL AS applied,
                   member_row.userid IS NOT NULL AS is_member
            FROM roles r
            JOIN organizations o ON o.organizationid = r.organizationid
            LEFT JOIN applications applied_row
              ON applied_row.roleid = r.roleid AND applied_row.userid = :me
            LEFT JOIN organization_members member_row
              ON member_row.organizationid = r.organizationid AND member_row.userid = :me_member'
            . $invite;
}

/**
 * Fetches a role row with the user's membership and application state.
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $userid
 * @return array
 */
function fetchRole($db, $roleid, $userid) {
    try {
        $ready = decisionReady($db);
        $stmt = $db->prepare(roleSelectSql($ready) . ' WHERE r.roleid = :id LIMIT 1');
        $stmt->bindValue(':me', $userid, PDO::PARAM_INT);
        $stmt->bindValue(':me_member', $userid, PDO::PARAM_INT);
        if ($ready) {
            $stmt->bindValue(':me_invite', $userid, PDO::PARAM_INT);
        }
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

/**
 * Shapes role rows for the API, attaching skills and application state.
 *
 * @param PDO $db
 * @param array $rows
 * @param int $userid
 * @return array
 */
function shapeRoles($db, $rows, $userid = 0) {
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
            'appliedAt'      => $row['applied_at'],
            'decision'       => $row['application_decision'] ?? null,
            'invitation'     => !empty($row['invitationid']) ? [
                'invitationid' => (int) $row['invitationid'],
                'status'       => $row['invitation_status'],
            ] : null,
            'canManage'      => (int) $row['is_member'] === 1 || (int) $row['created_by'] === (int) $userid,
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

/**
 * Maps role ids to their skills.
 *
 * @param PDO $db
 * @param int[] $roleIds
 * @return array
 */
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

/**
 * Returns a role's applicants with their decisions.
 *
 * @param PDO $db
 * @param int $roleid
 * @return array
 */
function applicantRows($db, $roleid) {
    $decision = decisionReady($db) ? 'a.decision' : '\'pending\' AS decision';
    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, u.displayname, u.firstname, u.lastname,
                a.roleid, ' . $decision . ', a.created_at
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
            'roleid'      => (int) $row['roleid'],
            'login'       => $row['loginuid'],
            'displayName' => $row['displayname'] ?: trim($row['firstname'] . ' ' . $row['lastname']),
            'decision'    => $row['decision'] ?: 'pending',
            'appliedAt'   => $row['created_at'],
        ];
    }
    return $people;
}

/**
 * Collects skill ids from the request body (skillIds array or skills list).
 *
 * @param PDO $db
 * @param array $body
 * @return int[]
 */
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

/**
 * Replaces a role's skill links.
 *
 * @param PDO $db
 * @param int $roleid
 * @param int[] $skillIds
 */
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

/**
 * Responds 403 unless the user is an organization member.
 *
 * @param PDO $db
 * @param int $organizationid
 * @param int $userid
 */
function requireMember($db, $organizationid, $userid) {
    $stmt = $db->prepare(
        'SELECT 1 FROM organization_members WHERE organizationid = :org AND userid = :userid LIMIT 1'
    );
    $stmt->execute([':org' => $organizationid, ':userid' => $userid]);
    if (!$stmt->fetch()) {
        respond(403, ['error' => 'Only organization members can post roles']);
    }
}

/**
 * Accepts or rejects an application and notifies the applicant.
 *
 * @param PDO $db
 * @param int $roleid
 * @param int $applicantId
 * @param int $userid
 */
function decideApplication($db, $roleid, $applicantId, $userid) {
    if (!decisionReady($db)) {
        respond(503, ['error' => 'Application decisions are not installed yet. Run migrate.sql on the database.']);
    }
    $role = fetchRole($db, $roleid, $userid);
    if (!(int) $role['is_member']) {
        respond(403, ['error' => 'Only organization members can decide applications']);
    }
    $body = getRequestBody();
    $decision = isset($body['decision']) ? clean($body['decision']) : '';
    if ($decision !== 'accepted' && $decision !== 'rejected') {
        respond(400, ['error' => 'Decision must be accepted or rejected']);
    }

    $lookup = $db->prepare(
        'SELECT decision FROM applications WHERE roleid = :roleid AND userid = :userid LIMIT 1'
    );
    $lookup->execute([':roleid' => $roleid, ':userid' => $applicantId]);
    $application = $lookup->fetch();
    if (!$application) {
        respond(404, ['error' => 'Application not found']);
    }

    $organizationid = (int) $role['organizationid'];
    try {
        $db->beginTransaction();
        $update = $db->prepare(
            'UPDATE applications
             SET decision = :decision, decided_at = NOW(), decided_by = :decided_by
             WHERE roleid = :roleid AND userid = :userid AND decision <> :guard'
        );
        $update->execute([
            ':decision'   => $decision,
            ':guard'      => $decision,
            ':decided_by' => $userid,
            ':roleid'     => $roleid,
            ':userid'     => $applicantId,
        ]);
        $changed = $update->rowCount() > 0;
        $alreadyMember = isOrgMember($db, $organizationid, $applicantId);

        if ($decision === 'accepted' && !$alreadyMember) {
            $invite = $db->prepare(
                'INSERT INTO organization_invitations
                   (organizationid, userid, roleid, invited_by, status, responded_at)
                 VALUES (:org, :userid, :roleid, :invited_by, \'pending\', NULL)
                 ON DUPLICATE KEY UPDATE
                   roleid = IF(status = \'accepted\', roleid, VALUES(roleid)),
                   invited_by = IF(status = \'accepted\', invited_by, VALUES(invited_by)),
                   status = IF(status = \'accepted\', status, \'pending\'),
                   responded_at = IF(status = \'accepted\', responded_at, NULL)'
            );
            $invite->execute([
                ':org'        => $organizationid,
                ':userid'     => $applicantId,
                ':roleid'     => $roleid,
                ':invited_by' => $userid,
            ]);
        }

        if ($decision === 'rejected') {
            $other = $db->prepare(
                'SELECT 1
                 FROM applications a
                 JOIN roles r ON r.roleid = a.roleid
                 WHERE r.organizationid = :org AND a.userid = :userid
                   AND a.decision = \'accepted\' AND a.roleid <> :roleid
                 LIMIT 1'
            );
            $other->execute([
                ':org'    => $organizationid,
                ':userid' => $applicantId,
                ':roleid' => $roleid,
            ]);
            if (!$other->fetch()) {
                $clear = $db->prepare(
                    'DELETE FROM organization_invitations
                     WHERE organizationid = :org AND userid = :userid AND status = \'pending\''
                );
                $clear->execute([':org' => $organizationid, ':userid' => $applicantId]);
            }
        }

        if ($changed && $decision === 'accepted') {
            $text = $role['org_name'] . ' accepted your application for ' . $role['name'] . '.';
            if (!$alreadyMember) {
                $text .= ' Join the organization or decline the invitation from Organizations.';
            }
            sendDirectNotice($db, $userid, $applicantId, $text, $organizationid);
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        roleFail($e, 'Could not update application');
    }

    respond(200, ['data' => [
        'roleid'   => $roleid,
        'userid'   => $applicantId,
        'decision' => $decision,
    ]]);
}

/**
 * Returns whether the user is a member of an organization.
 *
 * @param PDO $db
 * @param int $organizationid
 * @param int $userid
 * @return bool
 */
function isOrgMember($db, $organizationid, $userid) {
    $stmt = $db->prepare(
        'SELECT 1 FROM organization_members WHERE organizationid = :org AND userid = :userid LIMIT 1'
    );
    $stmt->execute([':org' => $organizationid, ':userid' => $userid]);
    return (bool) $stmt->fetch();
}

/**
 * Sends a direct message about a role decision to an applicant.
 *
 * @param PDO $db
 * @param int $fromId
 * @param int $toId
 * @param string $body
 * @param int|null $organizationid
 */
function sendDirectNotice($db, $fromId, $toId, $body, $organizationid) {
    if ($fromId === $toId) {
        return;
    }
    $find = $db->prepare(
        'SELECT cp1.conversationid
         FROM conversation_participants cp1
         JOIN conversation_participants cp2
           ON cp2.conversationid = cp1.conversationid AND cp2.userid = :recipient
         WHERE cp1.userid = :userid
           AND (SELECT COUNT(*) FROM conversation_participants cp3
                 WHERE cp3.conversationid = cp1.conversationid) = 2
         LIMIT 1'
    );
    $find->execute([':userid' => $fromId, ':recipient' => $toId]);
    $existing = $find->fetch();
    if ($existing) {
        $conversationid = (int) $existing['conversationid'];
    } else {
        $ins = $db->prepare('INSERT INTO conversations (created_by) VALUES (:me)');
        $ins->execute([':me' => $fromId]);
        $conversationid = (int) $db->lastInsertId();
        $part = $db->prepare(
            'INSERT INTO conversation_participants (conversationid, userid) VALUES (:cid, :userid)'
        );
        $part->execute([':cid' => $conversationid, ':userid' => $fromId]);
        $part->execute([':cid' => $conversationid, ':userid' => $toId]);
    }

    $message = $db->prepare(
        'INSERT INTO messages (conversationid, sender_userid, body, organizationid)
         VALUES (:cid, :userid, :body, :organizationid)'
    );
    $message->execute([
        ':cid'            => $conversationid,
        ':userid'         => $fromId,
        ':body'           => $body,
        ':organizationid' => $organizationid,
    ]);
    $touch = $db->prepare('UPDATE conversations SET last_message_at = NOW() WHERE conversationid = :cid');
    $touch->execute([':cid' => $conversationid]);
}

/**
 * Maps a PDOException to a 503 (missing tables) or 500 response.
 *
 * @param PDOException $e
 * @param string $fallback
 */
function roleFail($e, $fallback) {
    $detail = $e->getMessage();
    $missing = $e->getCode() === '42S02' || strpos($detail, 'Base table or view not found') !== false;
    $needsDecision = strpos($detail, 'organization_invitations') !== false
        || strpos($detail, 'decision') !== false
        || strpos($detail, 'decided_by') !== false;
    if ($needsDecision) {
        respond(503, ['error' => 'Application decisions are not installed yet. Run migrate.sql on the database.']);
    }
    if ($missing) {
        respond(503, ['error' => 'Organization tables are not installed yet. Run migrate.sql on the database.']);
    }
    error_log('Role error: ' . $e->getMessage());
    respond(500, ['error' => $fallback]);
}
