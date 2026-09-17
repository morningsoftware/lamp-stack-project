<?php
// ============================================================
//  api/handlers/profiles.php — Public profiles, skills & social links
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$db       = getDB();
requireAuth();
$segments = pathSegments();
$userid   = $segments[1] ?? null;
$sub      = $segments[2] ?? null;
$subId    = $segments[3] ?? null;

if ($userid === null) {
    requireMethod('GET');
    listProfiles($db);
}

// /profiles/{userid}/skills
if ($sub === 'skills') {
    requireMethod('GET', 'PUT');
    if (requestMethod() === 'PUT') {
        updateSkills($db, requireId($userid, 'user id'));
    } else {
        getSkills($db, requireId($userid, 'user id'));
    }
}

// /profiles/{userid}/social_links[/{linkid}]
if ($sub === 'social_links') {
    handleSocialLinks($db, requireId($userid, 'user id'), $subId);
}

if ($sub !== null) {
    respond(404, ['error' => 'Not found']);
}

$id = requireId($userid, 'user id');

switch (requestMethod()) {
    case 'GET':
        getProfile($db, $id);
        break;
    case 'PUT':
        updateProfile($db, $id);
        break;
    default:
        header('Allow: GET, PUT');
        respond(405, ['error' => 'Method not allowed']);
}

/**
 * Lists public profiles, optionally filtered with ?q= search term.
 *
 * @param PDO $db
 */
function listProfiles($db) {
    $q     = isset($_GET['q']) ? clean($_GET['q']) : '';
    $skill = isset($_GET['skill']) ? clean($_GET['skill']) : '';

    $sql = 'SELECT u.userid, u.loginuid, p.firstname, p.lastname, p.display_name,
                   p.bio, p.location, p.job_title, p.avatar_url, p.resume_url,
                   gp.username AS github_username, gp.avatar_url AS github_avatar_url,
                   gp.followers, gp.following,
                   gp.public_repos,
                   (SELECT COALESCE(SUM(gr.stars), 0) FROM github_repositories gr
                     WHERE gr.githubid = gp.githubid) AS total_stars
            FROM users u
            JOIN profiles p ON p.userid = u.userid
            LEFT JOIN github_profiles gp ON gp.userid = u.userid';
    $where  = [];
    $params = [];

    if ($skill !== '') {
        $sql .= ' JOIN user_skills usf ON usf.userid = u.userid
                  JOIN skills sf ON sf.skillid = usf.skillid';
        $where[] = 'sf.name = :skill';
        $params[':skill'] = $skill;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.display_name LIKE :q1 OR p.firstname LIKE :q2
                     OR p.lastname LIKE :q3 OR p.bio LIKE :q4 OR u.loginuid LIKE :q5)';
        $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY p.display_name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    attachListSkills($db, $rows);

    $data = array_map(function ($row) {
        return [
            'userid'         => (int) $row['userid'],
            'login'          => $row['loginuid'],
            'firstName'      => $row['firstname'],
            'lastName'       => $row['lastname'],
            'displayName'    => $row['display_name'],
            'bio'            => $row['bio'],
            'location'       => $row['location'],
            'jobTitle'       => $row['job_title'],
            'avatarUrl'      => $row['github_avatar_url'] ?: $row['avatar_url'],
            'resumeUrl'      => $row['resume_url'],
            'githubUsername' => $row['github_username'],
            'followers'      => (int) $row['followers'],
            'following'      => (int) $row['following'],
            'publicRepos'    => (int) $row['public_repos'],
            'totalStars'     => (int) $row['totalStars'],
            'skills'         => $row['skills'],
        ];
    }, $rows);

    respond(200, ['data' => $data]);
}

/**
 * Attaches a skills array and a normalized totalStars field to profile rows.
 *
 * @param PDO $db
 * @param array $rows
 */
function attachListSkills($db, &$rows) {
    if (!$rows) {
        return;
    }

    $ids = array_map(function ($row) {
        return (int) $row['userid'];
    }, $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $db->prepare(
        "SELECT us.userid, s.skillid, s.name, s.category, us.proficiency
         FROM user_skills us
         JOIN skills s ON s.skillid = us.skillid
         WHERE us.userid IN ({$placeholders})
         ORDER BY us.display_order ASC, s.name ASC"
    );
    $stmt->execute($ids);

    $byUser = [];
    foreach ($stmt->fetchAll() as $row) {
        $byUser[(int) $row['userid']][] = [
            'skillid'     => (int) $row['skillid'],
            'name'        => $row['name'],
            'category'    => $row['category'],
            'proficiency' => $row['proficiency'],
        ];
    }

    foreach ($rows as &$row) {
        $row['skills']     = $byUser[(int) $row['userid']] ?? [];
        $row['totalStars'] = (int) ($row['total_stars'] ?? 0);
        unset($row['total_stars']);
    }
}

/**
 * Returns the full public profile for one developer.
 *
 * @param PDO $db
 * @param int $userid
 */
function getProfile($db, $userid) {
    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, p.profileid, p.firstname, p.lastname,
                p.display_name, p.bio, p.location, p.job_title, p.avatar_url,
                p.resume_url, gp.githubid, gp.username AS github_username,
                gp.avatar_url AS github_avatar_url,
                gp.profile_url AS github_profile_url, gp.followers, gp.following,
                gp.public_repos, gp.public_gists, gp.last_synced
         FROM users u
         LEFT JOIN profiles p ON p.userid = u.userid
         LEFT JOIN github_profiles gp ON gp.userid = u.userid
         WHERE u.userid = :userid'
    );
    $stmt->execute([':userid' => $userid]);
    $profile = $stmt->fetch();

    if (!$profile) {
        respond(404, ['error' => 'Profile not found']);
    }

    $repos = [];
    if ($profile['githubid'] !== null) {
        $repoStmt = $db->prepare(
            'SELECT repo_id, name, description, url, language, stars, forks
             FROM github_repositories
             WHERE githubid = :githubid AND is_featured = 1
             ORDER BY stars DESC, name ASC'
        );
        $repoStmt->execute([':githubid' => (int) $profile['githubid']]);
        $repos = $repoStmt->fetchAll();
    }

    respond(200, ['data' => [
        'userid'      => (int) $profile['userid'],
        'login'       => $profile['loginuid'],
        'firstName'   => $profile['firstname'],
        'lastName'    => $profile['lastname'],
        'displayName' => $profile['display_name'],
        'bio'         => $profile['bio'],
        'location'    => $profile['location'],
        'jobTitle'    => $profile['job_title'],
        'avatarUrl'   => $profile['avatar_url'],
        'resumeUrl'   => $profile['resume_url'],
        'skills'      => fetchSkills($db, $userid),
        'socialLinks' => fetchSocialLinks($db, $userid),
        'github'      => $profile['githubid'] !== null ? [
            'username'    => $profile['github_username'],
            'avatarUrl'   => $profile['github_avatar_url'],
            'profileUrl'  => $profile['github_profile_url'],
            'followers'   => (int) $profile['followers'],
            'following'   => (int) $profile['following'],
            'publicRepos' => (int) $profile['public_repos'],
            'publicGists' => (int) $profile['public_gists'],
            'lastSynced'  => $profile['last_synced'],
            'featuredRepositories' => $repos,
        ] : null,
    ]]);
}

/**
 * Updates the authenticated user's profile.
 *
 * @param PDO $db
 * @param int $userid
 */
function updateProfile($db, $userid) {
    requireOwnership($userid);

    $body = getRequestBody();
    $columns = [
        'firstname'    => 'firstName',
        'lastname'     => 'lastName',
        'display_name' => 'displayName',
        'bio'          => 'bio',
        'location'     => 'location',
        'job_title'    => 'jobTitle',
        'avatar_url'   => 'avatarUrl',
        'resume_url'   => 'resumeUrl',
    ];

    $sets = [];
    $params = [':userid' => $userid];

    foreach ($columns as $column => $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = "{$column} = :{$field}";
            $params[":{$field}"] = clean($body[$field]);
        }
    }

    if (!$sets) {
        respond(400, ['error' => 'No profile fields provided']);
    }

    $stmt = $db->prepare(
        'UPDATE profiles SET ' . implode(', ', $sets) . ' WHERE userid = :userid'
    );
    $stmt->execute($params);

    getProfile($db, $userid);
}

/**
 * Returns a developer's skills.
 *
 * @param PDO $db
 * @param int $userid
 */
function getSkills($db, $userid) {
    respond(200, ['data' => fetchSkills($db, $userid)]);
}

/**
 * Replaces a developer's skill set.
 *
 * @param PDO $db
 * @param int $userid
 */
function updateSkills($db, $userid) {
    requireOwnership($userid);

    $body = getRequestBody();
    if (!isset($body['skills']) || !is_array($body['skills'])) {
        respond(400, ['error' => 'A skills array is required']);
    }

    try {
        $db->beginTransaction();

        $delete = $db->prepare('DELETE FROM user_skills WHERE userid = :userid');
        $delete->execute([':userid' => $userid]);

        $insert = $db->prepare(
            'INSERT INTO user_skills (userid, skillid, proficiency, display_order)
             VALUES (:userid, :skillid, :proficiency, :display_order)'
        );

        $order = 0;
        foreach ($body['skills'] as $skill) {
            $skillid = isset($skill['skillid']) ? (int) $skill['skillid'] : findOrCreateSkill($db, $skill);
            if ($skillid <= 0) {
                continue;
            }
            $insert->execute([
                ':userid'        => $userid,
                ':skillid'       => $skillid,
                ':proficiency'   => isset($skill['proficiency']) ? clean($skill['proficiency']) : null,
                ':display_order' => isset($skill['displayOrder']) ? (int) $skill['displayOrder'] : $order,
            ]);
            $order++;
        }

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Update skills error: ' . $e->getMessage());
        respond(500, ['error' => 'Could not update skills']);
    }

    respond(200, ['data' => fetchSkills($db, $userid)]);
}

/**
 * Finds an existing skill by id/name or creates it.
 *
 * @param PDO $db
 * @param array $skill
 * @return int
 */
function findOrCreateSkill($db, $skill) {
    if (isset($skill['name']) && clean($skill['name']) !== '') {
        $name = clean($skill['name']);

        $find = $db->prepare('SELECT skillid FROM skills WHERE name = :name LIMIT 1');
        $find->execute([':name' => $name]);
        $row = $find->fetch();
        if ($row) {
            return (int) $row['skillid'];
        }

        $create = $db->prepare('INSERT INTO skills (name, category) VALUES (:name, :category)');
        $create->execute([
            ':name'     => $name,
            ':category' => isset($skill['category']) ? clean($skill['category']) : null,
        ]);
        return (int) $db->lastInsertId();
    }
    return 0;
}

/**
 * Dispatches nested social link routes.
 *
 * @param PDO $db
 * @param int $userid
 * @param string|null $linkId
 */
function handleSocialLinks($db, $userid, $linkId) {
    $method = requestMethod();

    if ($linkId === null) {
        if ($method === 'GET') {
            respond(200, ['data' => fetchSocialLinks($db, $userid)]);
        }
        if ($method === 'POST') {
            createSocialLink($db, $userid);
        }
        header('Allow: GET, POST');
        respond(405, ['error' => 'Method not allowed']);
    }

    $linkId = requireId($linkId, 'link id');

    if ($method === 'PUT') {
        updateSocialLink($db, $userid, $linkId);
    }
    if ($method === 'DELETE') {
        deleteSocialLink($db, $userid, $linkId);
    }
    header('Allow: PUT, DELETE');
    respond(405, ['error' => 'Method not allowed']);
}

/**
 * @param PDO $db
 * @param int $userid
 */
function createSocialLink($db, $userid) {
    requireOwnership($userid);
    $body = getRequestBody();
    requireFields($body, ['platform', 'url']);

    $stmt = $db->prepare(
        'INSERT INTO social_links (userid, platform, url, display_order)
         VALUES (:userid, :platform, :url, :display_order)'
    );
    $stmt->execute([
        ':userid'        => $userid,
        ':platform'      => clean($body['platform']),
        ':url'           => clean($body['url']),
        ':display_order' => isset($body['displayOrder']) ? (int) $body['displayOrder'] : 0,
    ]);

    respond(201, ['data' => ['linkid' => (int) $db->lastInsertId()]]);
}

/**
 * @param PDO $db
 * @param int $userid
 * @param int $linkId
 */
function updateSocialLink($db, $userid, $linkId) {
    requireOwnership($userid);
    $body = getRequestBody();

    $sets = [];
    $params = [':linkid' => $linkId, ':userid' => $userid];
    foreach (['platform' => 'platform', 'url' => 'url'] as $column => $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = "{$column} = :{$field}";
            $params[":{$field}"] = clean($body[$field]);
        }
    }
    if (array_key_exists('displayOrder', $body)) {
        $sets[] = 'display_order = :display_order';
        $params[':display_order'] = (int) $body['displayOrder'];
    }
    if (!$sets) {
        respond(400, ['error' => 'No fields provided']);
    }

    $stmt = $db->prepare(
        'UPDATE social_links SET ' . implode(', ', $sets) . ' WHERE linkid = :linkid AND userid = :userid'
    );
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        respond(404, ['error' => 'Social link not found']);
    }
    respond(200, ['data' => ['linkid' => $linkId]]);
}

/**
 * @param PDO $db
 * @param int $userid
 * @param int $linkId
 */
function deleteSocialLink($db, $userid, $linkId) {
    requireOwnership($userid);

    $stmt = $db->prepare('DELETE FROM social_links WHERE linkid = :linkid AND userid = :userid');
    $stmt->execute([':linkid' => $linkId, ':userid' => $userid]);

    if ($stmt->rowCount() === 0) {
        respond(404, ['error' => 'Social link not found']);
    }
    respond(200, ['data' => ['message' => 'Social link deleted']]);
}

/**
 * @param PDO $db
 * @param int $userid
 * @return array
 */
function fetchSkills($db, $userid) {
    $stmt = $db->prepare(
        'SELECT s.skillid, s.name, s.category, us.proficiency, us.display_order
         FROM user_skills us
         JOIN skills s ON s.skillid = us.skillid
         WHERE us.userid = :userid
         ORDER BY us.display_order ASC, s.name ASC'
    );
    $stmt->execute([':userid' => $userid]);
    return $stmt->fetchAll();
}

/**
 * @param PDO $db
 * @param int $userid
 * @return array
 */
function fetchSocialLinks($db, $userid) {
    $stmt = $db->prepare(
        'SELECT linkid, platform, url, display_order
         FROM social_links
         WHERE userid = :userid
         ORDER BY display_order ASC, linkid ASC'
    );
    $stmt->execute([':userid' => $userid]);
    return $stmt->fetchAll();
}
