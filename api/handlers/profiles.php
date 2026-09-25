<?php
// ============================================================
//  api/handlers/profiles.php — Profiles, discovery, skills,
//  social links and follows
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

if ($userid === 'facets') {
    requireMethod('GET');
    profileFacets($db);
}

if ($userid === 'suggestions') {
    requireMethod('GET');
    suggestDevelopers($db);
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

// /profiles/{userid}/follow
if ($sub === 'follow') {
    $targetId = requireId($userid, 'user id');
    if (requestMethod() === 'POST') {
        followDeveloper($db, $targetId);
    }
    if (requestMethod() === 'DELETE') {
        unfollowDeveloper($db, $targetId);
    }
    header('Allow: POST, DELETE');
    respond(405, ['error' => 'Method not allowed']);
}

if ($sub !== null) {
    respond(404, ['error' => 'Not found']);
}

switch (requestMethod()) {
    case 'GET':
        // Accept either a numeric user id or a unique login handle.
        getProfile($db, $userid);
        break;
    case 'PUT':
        updateProfile($db, requireId($userid, 'user id'));
        break;
    default:
        header('Allow: GET, PUT');
        respond(405, ['error' => 'Method not allowed']);
}

/**
 * Splits a comma-separated query value into a unique list.
 *
 * @param mixed $value
 * @return string[]
 */
function splitCsv($value) {
    $parts = array_map(function ($part) {
        return trim($part);
    }, explode(',', (string) $value));
    $parts = array_filter($parts, function ($part) {
        return $part !== '';
    });
    return array_values(array_unique($parts));
}

/**
 * Lists and filters public profiles for the discovery directory.
 *
 * @param PDO $db
 */
function listProfiles($db) {
    $q         = isset($_GET['q']) ? clean($_GET['q']) : '';
    $skills    = splitCsv($_GET['skill'] ?? '');
    $skillMode = (($_GET['skillMode'] ?? 'any') === 'all') ? 'all' : 'any';
    $languages = splitCsv($_GET['language'] ?? '');
    $location  = isset($_GET['location']) ? clean($_GET['location']) : '';
    $jobtitle  = isset($_GET['jobtitle']) ? clean($_GET['jobtitle']) : '';
    $minStars  = isset($_GET['minStars']) ? max(0, (int) $_GET['minStars']) : 0;
    $hasGithub = !empty($_GET['hasGithub']);
    $following = !empty($_GET['following']);
    $sort      = isset($_GET['sort']) ? clean($_GET['sort']) : 'name';
    $limit     = isset($_GET['limit']) ? (int) $_GET['limit'] : 24;
    $limit     = max(1, min(100, $limit));
    $offset    = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    $me = requireAuth();

    $sql = 'SELECT u.userid, u.loginuid, u.firstname, u.lastname, u.displayname,
                   u.bio, u.location, u.jobtitle, u.avatar, u.resume,
                   gp.githubid, gp.username AS github_username,
                   gp.avatar_url AS github_avatar_url,
                   gp.followers, gp.following, gp.public_repos,
                   (SELECT COALESCE(SUM(gr.stars), 0) FROM github_repositories gr
                     WHERE gr.githubid = gp.githubid) AS total_stars
            FROM users u
            LEFT JOIN github_profiles gp ON gp.userid = u.userid';

    $where  = [];
    $params = [];

    // A user's own profile never appears in directory/search results.
    $where[] = 'u.userid <> :exclude_me';
    $params[':exclude_me'] = $me;

    // Site/admin accounts are staff, not developers, so they never appear.
    $where[] = 'u.isadmin = 0';

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(u.displayname LIKE :q1 OR u.firstname LIKE :q2
                     OR u.lastname LIKE :q3 OR u.bio LIKE :q4 OR u.loginuid LIKE :q5
                     OR EXISTS (SELECT 1 FROM user_skills us
                                JOIN skills s ON s.skillid = us.skillid
                                WHERE us.userid = u.userid AND s.name LIKE :q6)
                     OR EXISTS (SELECT 1 FROM github_repositories gr
                                WHERE gr.githubid = gp.githubid AND gr.language LIKE :q7))';
        $params += [
            ':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like,
            ':q5' => $like, ':q6' => $like, ':q7' => $like,
        ];
    }

    if ($skills) {
        $ph = [];
        foreach ($skills as $i => $skill) {
            $ph[] = ":sk{$i}";
            $params[":sk{$i}"] = $skill;
        }
        $in = implode(',', $ph);
        if ($skillMode === 'all') {
            $where[] = "(SELECT COUNT(DISTINCT s.skillid)
                         FROM user_skills us JOIN skills s ON s.skillid = us.skillid
                         WHERE us.userid = u.userid AND s.name IN ({$in})) = " . count($skills);
        } else {
            $where[] = "EXISTS (SELECT 1 FROM user_skills us JOIN skills s ON s.skillid = us.skillid
                         WHERE us.userid = u.userid AND s.name IN ({$in}))";
        }
    }

    if ($languages) {
        $ph = [];
        foreach ($languages as $i => $lang) {
            $ph[] = ":lg{$i}";
            $params[":lg{$i}"] = $lang;
        }
        $in = implode(',', $ph);
        $where[] = "EXISTS (SELECT 1 FROM github_repositories gr
                     WHERE gr.githubid = gp.githubid AND gr.language IN ({$in}))";
    }

    if ($location !== '') {
        $where[] = 'u.location LIKE :location';
        $params[':location'] = '%' . $location . '%';
    }

    if ($jobtitle !== '') {
        $roles = splitCsv($jobtitle);
        $ph = [];
        foreach ($roles as $i => $role) {
            $ph[] = ":job{$i}";
            $params[":job{$i}"] = '%' . $role . '%';
        }
        $where[] = '(' . implode(' OR ', array_map(function ($key) {
            return "u.jobtitle LIKE {$key}";
        }, $ph)) . ')';
    }

    if ($minStars > 0) {
        $where[] = '(SELECT COALESCE(SUM(gr.stars), 0) FROM github_repositories gr
                      WHERE gr.githubid = gp.githubid) >= :minStars';
        $params[':minStars'] = $minStars;
    }

    if ($hasGithub) {
        $where[] = 'gp.githubid IS NOT NULL';
    }

    if ($following) {
        $where[] = 'EXISTS (SELECT 1 FROM contacts c
                     WHERE c.userid = :me AND c.contact_userid = u.userid)';
        $params[':me'] = $me;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare(
        'SELECT COUNT(*) FROM users u
         LEFT JOIN github_profiles gp ON gp.userid = u.userid' . $whereSql
    );
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $paginate = ($sort !== 'match');
    $sql .= $whereSql;

    if ($paginate) {
        $orderMap = [
            'stars'     => 'total_stars DESC, u.displayname ASC',
            'followers' => 'gp.followers DESC, u.displayname ASC',
            'repos'     => 'gp.public_repos DESC, u.displayname ASC',
            'recent'    => 'u.created_at DESC, u.userid DESC',
            'name'      => 'u.displayname ASC, u.loginuid ASC',
        ];
        $order = $orderMap[$sort] ?? $orderMap['name'];
        $sql .= ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset';
    } else {
        $sql .= ' ORDER BY u.userid ASC';
    }

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    if ($paginate) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    attachListSkills($db, $rows);
    attachListLanguages($db, $rows);
    attachAffinity($db, $rows, $me);

    if ($sort === 'match') {
        usort($rows, function ($a, $b) {
            return ($b['matchScore'] <=> $a['matchScore'])
                ?: strcmp((string) $a['displayname'], (string) $b['displayname']);
        });
        $rows = array_slice($rows, $offset, $limit);
    }

    $data = array_map(function ($row) use ($me) {
        return [
            'userid'          => (int) $row['userid'],
            'login'           => $row['loginuid'],
            'firstName'       => $row['firstname'],
            'lastName'        => $row['lastname'],
            'displayName'     => $row['displayname'],
            'bio'             => $row['bio'],
            'location'        => $row['location'],
            'jobTitle'        => $row['jobtitle'],
            'avatarUrl'       => $row['github_avatar_url'] ?: $row['avatar'],
            'resumeUrl'       => $row['resume'],
            'githubUsername'  => $row['github_username'],
            'followers'       => (int) $row['followers'],
            'following'       => (int) $row['following'],
            'publicRepos'     => (int) $row['public_repos'],
            'totalStars'      => (int) $row['totalStars'],
            'skills'          => $row['skills'],
            'languages'       => $row['languages'],
            'isFollowing'     => $row['isFollowing'],
            'isSelf'          => (int) $row['userid'] === $me,
            'sharedSkills'    => $row['sharedSkills'],
            'sharedLanguages' => $row['sharedLanguages'],
            'matchScore'      => $row['matchScore'],
        ];
    }, $rows);

    respond(200, ['data' => $data, 'meta' => [
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]]);
}

/**
 * Returns filter option counts for the discovery UI.
 *
 * @param PDO $db
 */
function profileFacets($db) {
    $skills = $db->query(
        'SELECT s.skillid, s.name, s.category,
                COUNT(CASE WHEN u.isadmin = 0 THEN us.userid END) AS developers
         FROM skills s
         LEFT JOIN user_skills us ON us.skillid = s.skillid
         LEFT JOIN users u ON u.userid = us.userid
         GROUP BY s.skillid, s.name, s.category
         ORDER BY developers DESC, s.name ASC'
    )->fetchAll();

    $languageRows = $db->query(
        "SELECT gr.language, COUNT(DISTINCT g.userid) AS developers
         FROM github_repositories gr
         JOIN github_profiles g ON g.githubid = gr.githubid
         JOIN users u ON u.userid = g.userid
         WHERE u.isadmin = 0 AND gr.language IS NOT NULL AND gr.language <> ''
         GROUP BY gr.language
         ORDER BY developers DESC, gr.language ASC"
    )->fetchAll();

    // Offer a broad, familiar language list even before it appears in repos.
    $common = [
        'JavaScript', 'TypeScript', 'Python', 'Java', 'C', 'C++', 'C#', 'Go', 'Rust',
        'Ruby', 'PHP', 'Swift', 'Kotlin', 'Scala', 'R', 'Dart', 'Perl', 'Haskell',
        'Lua', 'Elixir', 'Objective-C', 'Shell', 'HTML', 'CSS', 'SCSS', 'Vue',
        'Svelte', 'SQL', 'MATLAB', 'Groovy', 'Erlang', 'Julia', 'Zig', 'Assembly',
    ];
    $seen = [];
    $languages = [];
    foreach ($languageRows as $row) {
        $seen[$row['language']] = true;
        $languages[] = ['language' => $row['language'], 'developers' => (int) $row['developers']];
    }
    foreach ($common as $lang) {
        if (!isset($seen[$lang])) {
            $languages[] = ['language' => $lang, 'developers' => 0];
        }
    }

    $locations = $db->query(
        "SELECT location, COUNT(*) AS developers
         FROM users
         WHERE location IS NOT NULL AND location <> '' AND isadmin = 0
         GROUP BY location
         ORDER BY developers DESC, location ASC
         LIMIT 100"
    )->fetchAll();

    $jobTitleRows = $db->query(
        "SELECT jobtitle, COUNT(*) AS developers
         FROM users
         WHERE jobtitle IS NOT NULL AND jobtitle <> '' AND isadmin = 0
         GROUP BY jobtitle
         ORDER BY developers DESC, jobtitle ASC
         LIMIT 100"
    )->fetchAll();

    // Offer familiar roles even before they appear in profiles.
    $commonRoles = [
        'Software Engineer', 'Frontend Developer', 'Backend Developer',
        'Full-Stack Developer', 'Mobile Developer', 'DevOps Engineer',
        'Data Engineer', 'Data Scientist', 'Machine Learning Engineer',
        'QA Engineer', 'Product Manager', 'Engineering Manager',
        'UI/UX Designer', 'Security Engineer', 'Site Reliability Engineer',
        'Platform Engineer', 'Systems Engineer', 'Cloud Architect',
        'Technical Lead', 'Solutions Architect', 'Game Developer',
        'Embedded Engineer',
    ];
    $seenRoles = [];
    $jobTitles = [];
    foreach ($jobTitleRows as $row) {
        $seenRoles[$row['jobtitle']] = true;
        $jobTitles[] = ['jobtitle' => $row['jobtitle'], 'developers' => (int) $row['developers']];
    }
    foreach ($commonRoles as $role) {
        if (!isset($seenRoles[$role])) {
            $jobTitles[] = ['jobtitle' => $role, 'developers' => 0];
        }
    }

    respond(200, ['data' => [
        'skills'    => $skills,
        'languages' => $languages,
        'locations' => $locations,
        'jobTitles' => $jobTitles,
    ]]);
}

/**
 * Suggests collaborators ranked by shared skills and GitHub languages.
 *
 * @param PDO $db
 */
function suggestDevelopers($db) {
    $me    = requireAuth();
    $limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 6;

    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, u.firstname, u.lastname, u.displayname,
                u.bio, u.location, u.jobtitle, u.avatar, u.resume,
                gp.githubid, gp.username AS github_username,
                gp.avatar_url AS github_avatar_url,
                gp.followers, gp.following, gp.public_repos,
                (SELECT COALESCE(SUM(gr.stars), 0) FROM github_repositories gr
                  WHERE gr.githubid = gp.githubid) AS total_stars
         FROM users u
         LEFT JOIN github_profiles gp ON gp.userid = u.userid
         WHERE u.userid <> :me AND u.isadmin = 0'
    );
    $stmt->execute([':me' => $me]);
    $rows = $stmt->fetchAll();

    attachListSkills($db, $rows);
    attachListLanguages($db, $rows);
    attachAffinity($db, $rows, $me);

    $rows = array_values(array_filter($rows, function ($row) {
        return $row['matchScore'] > 0;
    }));
    usort($rows, function ($a, $b) {
        return ($b['matchScore'] <=> $a['matchScore'])
            ?: strcmp((string) $a['displayname'], (string) $b['displayname']);
    });
    $rows = array_slice($rows, 0, $limit);

    $following = fetchFollowingSet($db, $me);

    $data = array_map(function ($row) use ($following) {
        return [
            'userid'          => (int) $row['userid'],
            'login'           => $row['loginuid'],
            'displayName'     => $row['displayname'],
            'jobTitle'        => $row['jobtitle'],
            'location'        => $row['location'],
            'avatarUrl'       => $row['github_avatar_url'] ?: $row['avatar'],
            'skills'          => $row['skills'],
            'languages'       => $row['languages'],
            'isFollowing'     => isset($following[(int) $row['userid']]),
            'sharedSkills'    => $row['sharedSkills'],
            'sharedLanguages' => $row['sharedLanguages'],
            'matchScore'      => $row['matchScore'],
        ];
    }, $rows);

    respond(200, ['data' => $data]);
}

/**
 * Attaches skills, languages, follow state and affinity to profile rows.
 *
 * @param PDO $db
 * @param array $rows
 * @param int $me
 */
function attachAffinity($db, &$rows, $me) {
    if (!$rows) {
        return;
    }

    $affinity  = myAffinity($db, $me);
    $following = fetchFollowingSet($db, $me);

    foreach ($rows as &$row) {
        [$score, $sharedSkills, $sharedLanguages] =
            affinityFor($row['skills'] ?? [], $row['languages'] ?? [], $affinity);
        $row['isFollowing']     = isset($following[(int) $row['userid']]);
        $row['sharedSkills']    = $sharedSkills;
        $row['sharedLanguages'] = $sharedLanguages;
        $row['matchScore']      = $score;
    }
}

/**
 * Loads the set of user ids the given user follows.
 *
 * @param PDO $db
 * @param int $userid
 * @return array<int,bool>
 */
function fetchFollowingSet($db, $userid) {
    $stmt = $db->prepare(
        'SELECT contact_userid FROM contacts
         WHERE userid = :userid AND contact_userid IS NOT NULL'
    );
    $stmt->execute([':userid' => $userid]);

    $set = [];
    foreach ($stmt->fetchAll() as $row) {
        $set[(int) $row['contact_userid']] = true;
    }
    return $set;
}

/**
 * Loads the current user's skill ids and languages for affinity matching.
 *
 * @param PDO $db
 * @param int $userid
 * @return array{skillIds:array,languages:array}
 */
function myAffinity($db, $userid) {
    $skills = $db->prepare('SELECT skillid FROM user_skills WHERE userid = :userid');
    $skills->execute([':userid' => $userid]);
    $skillIds = array_flip(array_map('intval', array_column($skills->fetchAll(), 'skillid')));

    $langs = $db->prepare(
        "SELECT DISTINCT gr.language
         FROM github_repositories gr
         JOIN github_profiles g ON g.githubid = gr.githubid
         WHERE g.userid = :userid AND gr.language IS NOT NULL AND gr.language <> ''"
    );
    $langs->execute([':userid' => $userid]);
    $languageSet = array_flip(array_map(function ($row) {
        return $row['language'];
    }, $langs->fetchAll()));

    return ['skillIds' => $skillIds, 'languages' => $languageSet];
}

/**
 * Computes the shared-skill/language overlap and score for a candidate.
 *
 * @param array $candidateSkills
 * @param array $candidateLanguages
 * @param array $affinity
 * @return array{0:int,1:string[],2:string[]}
 */
function affinityFor($candidateSkills, $candidateLanguages, $affinity) {
    $sharedSkills = [];
    foreach ($candidateSkills as $skill) {
        if (isset($affinity['skillIds'][(int) $skill['skillid']])) {
            $sharedSkills[] = $skill['name'];
        }
    }

    $sharedLanguages = [];
    foreach ($candidateLanguages as $lang) {
        if (isset($affinity['languages'][$lang['language']])) {
            $sharedLanguages[] = $lang['language'];
        }
    }

    return [count($sharedSkills) * 2 + count($sharedLanguages), $sharedSkills, $sharedLanguages];
}

/**
 * Attaches a skills array to profile rows.
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
 * Attaches aggregated GitHub languages to profile rows.
 *
 * @param PDO $db
 * @param array $rows
 */
function attachListLanguages($db, &$rows) {
    if (!$rows) {
        return;
    }

    $ids = array_map(function ($row) {
        return (int) $row['userid'];
    }, $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $db->prepare(
        "SELECT g.userid, gr.language, COUNT(*) AS repos, COALESCE(SUM(gr.stars), 0) AS stars
         FROM github_repositories gr
         JOIN github_profiles g ON g.githubid = gr.githubid
         WHERE g.userid IN ({$placeholders}) AND gr.language IS NOT NULL AND gr.language <> ''
         GROUP BY g.userid, gr.language
         ORDER BY repos DESC, gr.language ASC"
    );
    $stmt->execute($ids);

    $byUser = [];
    foreach ($stmt->fetchAll() as $row) {
        $byUser[(int) $row['userid']][] = [
            'language' => $row['language'],
            'repos'    => (int) $row['repos'],
            'stars'    => (int) $row['stars'],
        ];
    }

    foreach ($rows as &$row) {
        $row['languages'] = $byUser[(int) $row['userid']] ?? [];
    }
}

/**
 * Returns the full public profile for one developer.
 *
 * @param PDO $db
 * @param int $userid
 */
function getProfile($db, $identifier) {
    $numeric = ctype_digit((string) $identifier);
    $where   = $numeric ? 'u.userid = :identifier' : 'u.loginuid = :identifier';

    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, u.firstname, u.lastname,
                u.displayname, u.bio, u.location, u.jobtitle, u.avatar,
                u.resume, u.isadmin, gp.githubid, gp.username AS github_username,
                gp.avatar_url AS github_avatar_url,
                gp.profile_url AS github_profile_url, gp.followers, gp.following,
                gp.public_repos, gp.public_gists, gp.last_synced
         FROM users u
         LEFT JOIN github_profiles gp ON gp.userid = u.userid
         WHERE ' . $where
    );
    $stmt->execute([':identifier' => $numeric ? (int) $identifier : (string) $identifier]);
    $profile = $stmt->fetch();

    if (!$profile) {
        respond(404, ['error' => 'Profile not found']);
    }

    $me = requireAuth();

    // Admin accounts are not part of the developer directory; only the
    // account itself may view its own profile.
    if ((int) $profile['isadmin'] === 1 && (int) $profile['userid'] !== $me) {
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

    $skills    = fetchSkills($db, $userid);
    $languages = fetchLanguages($db, $userid);
    [$matchScore, $sharedSkills, $sharedLanguages] =
        affinityFor($skills, $languages, myAffinity($db, $me));
    $following = fetchFollowingSet($db, $me);

    respond(200, ['data' => [
        'userid'          => (int) $profile['userid'],
        'login'           => $profile['loginuid'],
        'firstName'       => $profile['firstname'],
        'lastName'        => $profile['lastname'],
        'displayName'     => $profile['displayname'],
        'bio'             => $profile['bio'],
        'location'        => $profile['location'],
        'jobTitle'        => $profile['jobtitle'],
        'avatarUrl'       => $profile['github_avatar_url'] ?: $profile['avatar'],
        'resumeUrl'       => $profile['resume'],
        'isSelf'          => (int) $profile['userid'] === $me,
        'isFollowing'     => isset($following[(int) $profile['userid']]),
        'matchScore'      => $matchScore,
        'sharedSkills'    => $sharedSkills,
        'sharedLanguages' => $sharedLanguages,
        'skills'          => $skills,
        'languages'       => $languages,
        'socialLinks'     => fetchSocialLinks($db, $userid),
        'github'          => $profile['githubid'] !== null ? [
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
        'firstname'   => 'firstName',
        'lastname'    => 'lastName',
        'displayname' => 'displayName',
        'bio'         => 'bio',
        'location'    => 'location',
        'jobtitle'    => 'jobTitle',
        'resume'      => 'resumeUrl',
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
        'UPDATE users SET ' . implode(', ', $sets) . ' WHERE userid = :userid'
    );
    $stmt->execute($params);

    getProfile($db, $userid);
}

/**
 * Follows (saves) another developer to the current user's contacts.
 *
 * @param PDO $db
 * @param int $targetId
 */
function followDeveloper($db, $targetId) {
    $me = requireAuth();

    if ($targetId === $me) {
        respond(400, ['error' => 'You cannot follow yourself']);
    }

    $exists = $db->prepare('SELECT userid, firstname, lastname, email, isadmin FROM users WHERE userid = :id');
    $exists->execute([':id' => $targetId]);
    $target = $exists->fetch();
    if (!$target || (int) $target['isadmin'] === 1) {
        respond(404, ['error' => 'Developer not found']);
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO contacts (userid, contact_userid, firstname, lastname, email, description)
             VALUES (:me, :target, :first, :last, :email, :description)'
        );
        $stmt->execute([
            ':me'          => $me,
            ':target'      => $targetId,
            ':first'       => $target['firstname'],
            ':last'        => $target['lastname'],
            ':email'       => $target['email'],
            ':description' => 'collab.dev developer',
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            respond(409, ['error' => 'You are already following this developer']);
        }
        error_log('Follow error: ' . $e->getMessage());
        respond(500, ['error' => 'Could not follow developer']);
    }

    respond(201, ['data' => ['contactid' => (int) $db->lastInsertId(), 'following' => true]]);
}

/**
 * Unfollows a developer.
 *
 * @param PDO $db
 * @param int $targetId
 */
function unfollowDeveloper($db, $targetId) {
    $me = requireAuth();

    $stmt = $db->prepare(
        'DELETE FROM contacts WHERE userid = :me AND contact_userid = :target'
    );
    $stmt->execute([':me' => $me, ':target' => $targetId]);

    if ($stmt->rowCount() === 0) {
        respond(404, ['error' => 'You are not following this developer']);
    }

    respond(200, ['data' => ['following' => false]]);
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
 * Returns aggregated GitHub languages for one developer.
 *
 * @param PDO $db
 * @param int $userid
 * @return array
 */
function fetchLanguages($db, $userid) {
    $stmt = $db->prepare(
        "SELECT gr.language, COUNT(*) AS repos, COALESCE(SUM(gr.stars), 0) AS stars
         FROM github_repositories gr
         JOIN github_profiles g ON g.githubid = gr.githubid
         WHERE g.userid = :userid AND gr.language IS NOT NULL AND gr.language <> ''
         GROUP BY gr.language
         ORDER BY repos DESC, gr.language ASC"
    );
    $stmt->execute([':userid' => $userid]);

    return array_map(function ($row) {
        return [
            'language' => $row['language'],
            'repos'    => (int) $row['repos'],
            'stars'    => (int) $row['stars'],
        ];
    }, $stmt->fetchAll());
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
