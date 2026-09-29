<?php
// ============================================================
//  api/handlers/organizations.php — Organizations and members
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$db       = getDB();
$segments = pathSegments();
$key      = $segments[1] ?? null;
$sub      = $segments[2] ?? null;
$subId    = $segments[3] ?? null;
$userid   = requireAuth();

if ($key === null) {
    if (requestMethod() === 'GET') {
        listOrganizations($db, $userid);
    }
    if (requestMethod() === 'POST') {
        createOrganization($db, $userid);
    }
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed']);
}

if ($key === 'invitations') {
    if ($sub === null) {
        requireMethod('GET');
        listMyInvitations($db, $userid);
    }
    $invitationId = requireId($sub, 'invitation id');
    if ($subId === 'accept') {
        requireMethod('POST');
        acceptInvitation($db, $invitationId, $userid);
    }
    if ($subId === 'decline') {
        requireMethod('POST');
        declineInvitation($db, $invitationId, $userid);
    }
    respond(404, ['error' => 'Not found']);
}

$org = findOrganization($db, $key);

if ($sub === null) {
    if (requestMethod() === 'GET') {
        getOrganization($db, $org, $userid);
    }
    if (requestMethod() === 'PUT') {
        updateOrganization($db, $org, $userid);
    }
    if (requestMethod() === 'DELETE') {
        deleteOrganization($db, $org, $userid);
    }
    header('Allow: GET, PUT, DELETE');
    respond(405, ['error' => 'Method not allowed']);
}

if ($sub === 'members') {
    if ($subId === null) {
        requireMethod('POST');
        addOrganizationMember($db, $org, $userid);
    }
    requireMethod('DELETE');
    removeOrganizationMember($db, $org, $userid, requireId($subId, 'user id'));
}

respond(404, ['error' => 'Not found']);

function listOrganizations($db, $userid) {
    $mine = isset($_GET['mine']) && $_GET['mine'] === '1';
    $sql = 'SELECT o.organizationid, o.name, o.slug, o.description, o.location, o.website,
                   o.created_at,
                   (SELECT COUNT(*) FROM organization_members m
                     WHERE m.organizationid = o.organizationid) AS member_count,
                   (SELECT COUNT(*) FROM roles r
                     WHERE r.organizationid = o.organizationid AND r.status = \'open\') AS open_roles,
                   (SELECT m.membership FROM organization_members m
                     WHERE m.organizationid = o.organizationid AND m.userid = :me) AS membership
            FROM organizations o';
    if ($mine) {
        $sql .= ' WHERE EXISTS (
                    SELECT 1 FROM organization_members mine
                    WHERE mine.organizationid = o.organizationid AND mine.userid = :me_filter)';
    }
    $sql .= ' ORDER BY o.name ASC';

    try {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':me', $userid, PDO::PARAM_INT);
        if ($mine) {
            $stmt->bindValue(':me_filter', $userid, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        organizationFail($e, 'Could not load organizations');
    }

    $orgs = [];
    foreach ($rows as $row) {
        $orgs[] = shapeOrganization($row);
    }
    respond(200, ['data' => $orgs]);
}

function createOrganization($db, $userid) {
    $body = getRequestBody();
    requireFields($body, ['name']);

    $name = clean($body['name']);
    if (strlen($name) > 100) {
        respond(400, ['error' => 'Organization name must be 100 characters or fewer']);
    }

    $slug = isset($body['slug']) && clean($body['slug']) !== ''
        ? slugify(clean($body['slug']))
        : slugify($name);
    $description = isset($body['description']) ? clean($body['description']) : '';
    $location = isset($body['location']) ? clean($body['location']) : '';
    $website = isset($body['website']) ? clean($body['website']) : '';

    try {
        $slug = uniqueSlug($db, $slug, !empty($body['slug']));
        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO organizations (name, slug, description, location, website, created_by)
             VALUES (:name, :slug, :description, :location, :website, :created_by)'
        );
        $stmt->execute([
            ':name'        => $name,
            ':slug'        => $slug,
            ':description' => $description !== '' ? $description : null,
            ':location'    => $location !== '' ? $location : null,
            ':website'     => $website !== '' ? $website : null,
            ':created_by'  => $userid,
        ]);
        $organizationid = (int) $db->lastInsertId();
        $member = $db->prepare(
            'INSERT INTO organization_members (organizationid, userid, membership)
             VALUES (:org, :userid, \'owner\')'
        );
        $member->execute([':org' => $organizationid, ':userid' => $userid]);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e->getCode() === '23000') {
            respond(409, ['error' => 'That organization address is already taken']);
        }
        organizationFail($e, 'Could not create organization');
    }

    respond(201, ['data' => ['organizationid' => $organizationid, 'slug' => $slug]]);
}

function getOrganization($db, $org, $userid) {
    $membership = membershipOf($db, (int) $org['organizationid'], $userid);
    $shaped = shapeOrganization($org, $membership);
    $shaped['memberCount'] = countMembers($db, (int) $org['organizationid']);
    $shaped['openRoleCount'] = countOpenRoles($db, (int) $org['organizationid']);
    $shaped['members'] = organizationMembers($db, (int) $org['organizationid']);
    $shaped['roles'] = organizationRoles($db, (int) $org['organizationid'], $userid);
    respond(200, ['data' => $shaped]);
}

function updateOrganization($db, $org, $userid) {
    requireOwner($db, (int) $org['organizationid'], $userid);
    $body = getRequestBody();
    requireFields($body, ['name']);

    $name = clean($body['name']);
    $description = isset($body['description']) ? clean($body['description']) : '';
    $location = isset($body['location']) ? clean($body['location']) : '';
    $website = isset($body['website']) ? clean($body['website']) : '';
    $slug = $org['slug'];
    if (isset($body['slug']) && clean($body['slug']) !== '') {
        $next = slugify(clean($body['slug']));
        if ($next !== $org['slug']) {
            $slug = uniqueSlug($db, $next, true);
        }
    }

    try {
        $stmt = $db->prepare(
            'UPDATE organizations
             SET name = :name, slug = :slug, description = :description, location = :location, website = :website
             WHERE organizationid = :id'
        );
        $stmt->execute([
            ':name'        => $name,
            ':slug'        => $slug,
            ':description' => $description !== '' ? $description : null,
            ':location'    => $location !== '' ? $location : null,
            ':website'     => $website !== '' ? $website : null,
            ':id'          => (int) $org['organizationid'],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            respond(409, ['error' => 'That organization address is already taken']);
        }
        organizationFail($e, 'Could not update organization');
    }

    respond(200, ['data' => ['organizationid' => (int) $org['organizationid'], 'slug' => $slug]]);
}

function deleteOrganization($db, $org, $userid) {
    requireOwner($db, (int) $org['organizationid'], $userid);
    try {
        $stmt = $db->prepare('DELETE FROM organizations WHERE organizationid = :id');
        $stmt->execute([':id' => (int) $org['organizationid']]);
    } catch (PDOException $e) {
        organizationFail($e, 'Could not delete organization');
    }
    respond(200, ['data' => ['message' => 'Organization deleted']]);
}

function addOrganizationMember($db, $org, $userid) {
    $organizationid = (int) $org['organizationid'];
    requireOwner($db, $organizationid, $userid);
    $body = getRequestBody();
    requireFields($body, ['login']);

    $lookup = $db->prepare('SELECT userid FROM users WHERE loginuid = :login AND isactive = 1 LIMIT 1');
    $lookup->execute([':login' => clean($body['login'])]);
    $user = $lookup->fetch();
    if (!$user) {
        respond(404, ['error' => 'No active user with that username']);
    }

    $memberId = (int) $user['userid'];
    if (membershipOf($db, $organizationid, $memberId)) {
        respond(409, ['error' => 'That user is already a member']);
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO organization_members (organizationid, userid, membership)
             VALUES (:org, :userid, \'member\')'
        );
        $stmt->execute([':org' => $organizationid, ':userid' => $memberId]);
    } catch (PDOException $e) {
        organizationFail($e, 'Could not add member');
    }

    respond(201, ['data' => ['userid' => $memberId]]);
}

function removeOrganizationMember($db, $org, $userid, $memberId) {
    $organizationid = (int) $org['organizationid'];
    requireOwner($db, $organizationid, $userid);

    $membership = membershipOf($db, $organizationid, $memberId);
    if (!$membership) {
        respond(404, ['error' => 'Member not found']);
    }
    if ($membership === 'owner' && countOwners($db, $organizationid) <= 1) {
        respond(400, ['error' => 'An organization needs at least one owner']);
    }

    $stmt = $db->prepare(
        'DELETE FROM organization_members WHERE organizationid = :org AND userid = :userid'
    );
    $stmt->execute([':org' => $organizationid, ':userid' => $memberId]);
    respond(200, ['data' => ['message' => 'Member removed']]);
}

function findOrganization($db, $key) {
    $key = rawurldecode((string) $key);
    try {
        if (ctype_digit($key)) {
            $stmt = $db->prepare(
                'SELECT * FROM organizations WHERE organizationid = :id OR slug = :slug LIMIT 1'
            );
            $stmt->execute([':id' => (int) $key, ':slug' => $key]);
        } else {
            $stmt = $db->prepare('SELECT * FROM organizations WHERE slug = :slug LIMIT 1');
            $stmt->execute([':slug' => $key]);
        }
        $org = $stmt->fetch();
    } catch (PDOException $e) {
        organizationFail($e, 'Could not load organization');
    }

    if (!$org) {
        respond(404, ['error' => 'Organization not found']);
    }
    return $org;
}

function shapeOrganization($row, $membership = null) {
    if ($membership === null && array_key_exists('membership', $row)) {
        $membership = $row['membership'];
    }
    $shaped = [
        'organizationid' => (int) $row['organizationid'],
        'name'           => $row['name'],
        'slug'           => $row['slug'],
        'description'    => $row['description'],
        'location'       => $row['location'],
        'website'        => $row['website'],
        'createdAt'      => $row['created_at'],
        'membership'     => $membership ?: null,
    ];
    if (isset($row['member_count'])) {
        $shaped['memberCount'] = (int) $row['member_count'];
    }
    if (isset($row['open_roles'])) {
        $shaped['openRoleCount'] = (int) $row['open_roles'];
    }
    return $shaped;
}

function organizationMembers($db, $organizationid) {
    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, u.displayname, u.firstname, u.lastname, m.membership
         FROM organization_members m
         JOIN users u ON u.userid = m.userid
         WHERE m.organizationid = :id
         ORDER BY m.membership = \'owner\' DESC, u.displayname, u.loginuid'
    );
    $stmt->execute([':id' => $organizationid]);
    $members = [];
    foreach ($stmt->fetchAll() as $row) {
        $members[] = [
            'userid'      => (int) $row['userid'],
            'login'       => $row['loginuid'],
            'displayName' => $row['displayname'] ?: trim($row['firstname'] . ' ' . $row['lastname']),
            'membership'  => $row['membership'],
        ];
    }
    return $members;
}

function organizationRoles($db, $organizationid, $userid) {
    try {
    $stmt = $db->prepare(
        'SELECT r.roleid, r.name, r.description, r.status, r.created_at, r.closed_at,
                (SELECT COUNT(*) FROM applications a WHERE a.roleid = r.roleid) AS applicant_count
         FROM roles r
         WHERE r.organizationid = :id
         ORDER BY r.status = \'open\' DESC, r.created_at DESC'
    );
    $stmt->execute([':id' => $organizationid]);
    $rows = $stmt->fetchAll();
    $people = [];
    if (membershipOf($db, $organizationid, $userid) && $rows) {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['roleid'];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $decision = decisionReady($db) ? 'a.decision' : '\'pending\' AS decision';
        $apps = $db->prepare(
            "SELECT a.roleid, {$decision}, u.userid, u.loginuid, u.displayname, u.firstname, u.lastname, a.created_at
             FROM applications a
             JOIN users u ON u.userid = a.userid
             WHERE a.roleid IN ({$placeholders})
             ORDER BY a.created_at DESC"
        );
        $apps->execute($ids);
        foreach ($apps->fetchAll() as $person) {
            $people[(int) $person['roleid']][] = [
                'userid'      => (int) $person['userid'],
                'roleid'      => (int) $person['roleid'],
                'login'       => $person['loginuid'],
                'displayName' => $person['displayname'] ?: trim($person['firstname'] . ' ' . $person['lastname']),
                'decision'    => $person['decision'] ?: 'pending',
                'appliedAt'   => $person['created_at'],
            ];
        }
    }
    } catch (PDOException $e) {
        organizationFail($e, 'Could not load roles');
    }
    $roles = [];
    foreach ($rows as $row) {
        $roleid = (int) $row['roleid'];
        $roles[] = [
            'roleid'         => $roleid,
            'name'           => $row['name'],
            'description'    => $row['description'],
            'status'         => $row['status'],
            'statusLabel'    => $row['status'] === 'open' ? 'accepting applications' : 'closed',
            'createdAt'      => $row['created_at'],
            'closedAt'       => $row['closed_at'],
            'applicantCount' => (int) $row['applicant_count'],
            'applicants'     => $people[$roleid] ?? [],
        ];
    }
    return $roles;
}

function membershipOf($db, $organizationid, $userid) {
    $stmt = $db->prepare(
        'SELECT membership FROM organization_members
         WHERE organizationid = :org AND userid = :userid LIMIT 1'
    );
    $stmt->execute([':org' => $organizationid, ':userid' => $userid]);
    $row = $stmt->fetch();
    return $row ? $row['membership'] : null;
}

function requireOwner($db, $organizationid, $userid) {
    if (membershipOf($db, $organizationid, $userid) !== 'owner') {
        respond(403, ['error' => 'Only an owner can do that']);
    }
}

function countOwners($db, $organizationid) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM organization_members
         WHERE organizationid = :id AND membership = \'owner\''
    );
    $stmt->execute([':id' => $organizationid]);
    return (int) $stmt->fetchColumn();
}

function countMembers($db, $organizationid) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM organization_members WHERE organizationid = :id');
    $stmt->execute([':id' => $organizationid]);
    return (int) $stmt->fetchColumn();
}

function countOpenRoles($db, $organizationid) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM roles WHERE organizationid = :id AND status = \'open\''
    );
    $stmt->execute([':id' => $organizationid]);
    return (int) $stmt->fetchColumn();
}

function slugify($value) {
    $slug = strtolower($value);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'org';
    }
    return substr($slug, 0, 50);
}

function uniqueSlug($db, $slug, $exact) {
    $stmt = $db->prepare('SELECT 1 FROM organizations WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    if (!$stmt->fetch()) {
        return $slug;
    }
    if ($exact) {
        respond(409, ['error' => 'That organization address is already taken']);
    }
    $base = substr($slug, 0, 44);
    for ($n = 2; $n < 100; $n++) {
        $candidate = $base . '-' . $n;
        $stmt->execute([':slug' => $candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }
    respond(409, ['error' => 'Could not choose a unique address for this organization']);
}

function listMyInvitations($db, $userid) {
    try {
        $stmt = $db->prepare(
            'SELECT i.invitationid, i.status, i.created_at, i.roleid,
                    o.organizationid, o.name AS org_name, o.slug,
                    r.name AS role_name
             FROM organization_invitations i
             JOIN organizations o ON o.organizationid = i.organizationid
             LEFT JOIN roles r ON r.roleid = i.roleid
             WHERE i.userid = :userid AND i.status = \'pending\'
             ORDER BY i.created_at DESC'
        );
        $stmt->execute([':userid' => $userid]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        organizationFail($e, 'Could not load invitations');
    }

    $invites = [];
    foreach ($rows as $row) {
        $invites[] = [
            'invitationid' => (int) $row['invitationid'],
            'status'       => $row['status'],
            'createdAt'    => $row['created_at'],
            'organization' => [
                'organizationid' => (int) $row['organizationid'],
                'name'           => $row['org_name'],
                'slug'           => $row['slug'],
            ],
            'role'         => $row['roleid'] ? [
                'roleid' => (int) $row['roleid'],
                'name'   => $row['role_name'],
            ] : null,
        ];
    }
    respond(200, ['data' => $invites]);
}

function acceptInvitation($db, $invitationId, $userid) {
    $invite = fetchInvitation($db, $invitationId, $userid);
    try {
        $db->beginTransaction();
        $member = $db->prepare(
            'INSERT IGNORE INTO organization_members (organizationid, userid, membership)
             VALUES (:org, :userid, \'member\')'
        );
        $member->execute([
            ':org'    => (int) $invite['organizationid'],
            ':userid' => $userid,
        ]);
        $update = $db->prepare(
            'UPDATE organization_invitations
             SET status = \'accepted\', responded_at = NOW()
             WHERE invitationid = :id AND userid = :userid AND status = \'pending\''
        );
        $update->execute([':id' => $invitationId, ':userid' => $userid]);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        organizationFail($e, 'Could not join organization');
    }
    respond(200, ['data' => ['invitationid' => $invitationId, 'status' => 'accepted']]);
}

function declineInvitation($db, $invitationId, $userid) {
    fetchInvitation($db, $invitationId, $userid);
    try {
        $update = $db->prepare(
            'UPDATE organization_invitations
             SET status = \'declined\', responded_at = NOW()
             WHERE invitationid = :id AND userid = :userid AND status = \'pending\''
        );
        $update->execute([':id' => $invitationId, ':userid' => $userid]);
    } catch (PDOException $e) {
        organizationFail($e, 'Could not decline invitation');
    }
    if ($update->rowCount() === 0) {
        respond(400, ['error' => 'This invitation is no longer open']);
    }
    respond(200, ['data' => ['invitationid' => $invitationId, 'status' => 'declined']]);
}

function fetchInvitation($db, $invitationId, $userid) {
    try {
        $stmt = $db->prepare(
            'SELECT invitationid, organizationid, userid, status
             FROM organization_invitations WHERE invitationid = :id LIMIT 1'
        );
        $stmt->execute([':id' => $invitationId]);
        $invite = $stmt->fetch();
    } catch (PDOException $e) {
        organizationFail($e, 'Could not load invitation');
    }
    if (!$invite) {
        respond(404, ['error' => 'Invitation not found']);
    }
    if ((int) $invite['userid'] !== $userid) {
        respond(403, ['error' => 'This invitation is not yours']);
    }
    if ($invite['status'] !== 'pending') {
        respond(400, ['error' => 'This invitation is no longer open']);
    }
    return $invite;
}

function organizationFail($e, $fallback) {
    $detail = $e->getMessage();
    $missing = $e->getCode() === '42S02' || strpos($detail, 'Base table or view not found') !== false;
    $needsDecision = strpos($detail, 'organization_invitations') !== false
        || strpos($detail, 'decision') !== false
        || strpos($detail, 'decided_by') !== false;
    if ($needsDecision) {
        respond(503, ['error' => 'Application decisions are not installed yet. Run migrate_applications.sql on the database.']);
    }
    if ($missing) {
        respond(503, ['error' => 'Organization tables are not installed yet. Run migrate.sql on the database.']);
    }
    error_log('Organization error: ' . $e->getMessage());
    respond(500, ['error' => $fallback]);
}
