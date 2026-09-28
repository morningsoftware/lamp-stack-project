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

$org = findOrganization($db, $key);

if ($sub === null) {
    if (requestMethod() === 'GET') {
        getOrganization($db, $org, $userid);
    }
    if (requestMethod() === 'PUT') {
        updateOrganization($db, $org, $userid);
    }
    header('Allow: GET, PUT');
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

    try {
        $stmt = $db->prepare(
            'UPDATE organizations
             SET name = :name, description = :description, location = :location, website = :website
             WHERE organizationid = :id'
        );
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description !== '' ? $description : null,
            ':location'    => $location !== '' ? $location : null,
            ':website'     => $website !== '' ? $website : null,
            ':id'          => (int) $org['organizationid'],
        ]);
    } catch (PDOException $e) {
        organizationFail($e, 'Could not update organization');
    }

    respond(200, ['data' => ['organizationid' => (int) $org['organizationid'], 'slug' => $org['slug']]]);
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
    $stmt = $db->prepare(
        'SELECT r.roleid, r.name, r.description, r.status, r.created_at, r.closed_at,
                (SELECT COUNT(*) FROM applications a WHERE a.roleid = r.roleid) AS applicant_count
         FROM roles r
         WHERE r.organizationid = :id
         ORDER BY r.status = \'open\' DESC, r.created_at DESC'
    );
    $stmt->execute([':id' => $organizationid]);
    $roles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roles[] = [
            'roleid'         => (int) $row['roleid'],
            'name'           => $row['name'],
            'description'    => $row['description'],
            'status'         => $row['status'],
            'statusLabel'    => $row['status'] === 'open' ? 'accepting applications' : 'closed',
            'createdAt'      => $row['created_at'],
            'closedAt'       => $row['closed_at'],
            'applicantCount' => (int) $row['applicant_count'],
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

function organizationFail($e, $fallback) {
    $missing = $e->getCode() === '42S02' || strpos($e->getMessage(), 'Base table or view not found') !== false;
    if ($missing) {
        respond(503, ['error' => 'Organization tables are not installed yet. Run migrate_organizations.sql on the database.']);
    }
    error_log('Organization error: ' . $e->getMessage());
    respond(500, ['error' => $fallback]);
}
