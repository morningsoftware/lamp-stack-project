<?php
// ============================================================
//  api/handlers/compare.php — Compare two developers
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

requireMethod('GET');

$db = getDB();
requireAuth();

$param = isset($_GET['users']) ? $_GET['users'] : '';
if ($param === '' && isset($_GET['a'], $_GET['b'])) {
    $param = $_GET['a'] . ',' . $_GET['b'];
}

$ids = array_values(array_unique(array_filter(
    array_map('intval', explode(',', $param)),
    function ($id) {
        return $id > 0;
    }
)));

if (count($ids) !== 2) {
    respond(400, ['error' => 'Provide exactly two distinct user ids, e.g. ?users=1,2']);
}

[$first, $second] = $ids;

$devA = compareUser($db, $first);
$devB = compareUser($db, $second);

if ($devA === null || $devB === null) {
    respond(404, ['error' => 'One or both developers were not found']);
}

$commonSkills = [];
foreach ($devA['skills'] as $skill) {
    foreach ($devB['skills'] as $other) {
        if ($skill['skillid'] === $other['skillid']) {
            $commonSkills[] = [
                'skillid'     => $skill['skillid'],
                'name'        => $skill['name'],
                'category'    => $skill['category'],
                'proficiency' => [
                    $devA['userid'] => $skill['proficiency'],
                    $devB['userid'] => $other['proficiency'],
                ],
            ];
        }
    }
}

$languagesA = array_column($devA['languages'], 'language');
$languagesB = array_column($devB['languages'], 'language');
$commonLanguages = array_values(array_intersect($languagesA, $languagesB));

$sharedProjects = [];
foreach ($devA['repositories'] as $repo) {
    foreach ($devB['repositories'] as $other) {
        if (strcasecmp($repo['name'], $other['name']) === 0) {
            $sharedProjects[] = [
                'name'       => $repo['name'],
                'developers' => [
                    [
                        'userid'   => $devA['userid'],
                        'url'      => $repo['url'],
                        'stars'    => $repo['stars'],
                        'language' => $repo['language'],
                        'featured' => $repo['is_featured'],
                    ],
                    [
                        'userid'   => $devB['userid'],
                        'url'      => $other['url'],
                        'stars'    => $other['stars'],
                        'language' => $other['language'],
                        'featured' => $other['is_featured'],
                    ],
                ],
            ];
        }
    }
}

respond(200, ['data' => [
    'developers'      => [$devA, $devB],
    'commonSkills'    => $commonSkills,
    'commonLanguages' => $commonLanguages,
    'sharedProjects'  => $sharedProjects,
    'combined'        => [
        'totalStars' => $devA['totalStars'] + $devB['totalStars'],
        'repos'      => $devA['publicRepos'] + $devB['publicRepos'],
        'followers'  => $devA['followers'] + $devB['followers'],
    ],
]]);

/**
 * Loads the comparison data for a single developer.
 *
 * @param PDO $db
 * @param int $userid
 * @return array|null
 */
function compareUser($db, $userid) {
    $stmt = $db->prepare(
        'SELECT u.userid, u.loginuid, p.display_name, p.firstname, p.lastname,
                p.avatar_url, p.job_title, p.location, p.bio,
                gp.githubid, gp.username, gp.avatar_url AS github_avatar_url,
                gp.followers, gp.following, gp.public_repos
         FROM users u
         LEFT JOIN profiles p ON p.userid = u.userid
         LEFT JOIN github_profiles gp ON gp.userid = u.userid
         WHERE u.userid = :userid'
    );
    $stmt->execute([':userid' => $userid]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $skills = $db->prepare(
        'SELECT s.skillid, s.name, s.category, us.proficiency, us.display_order
         FROM user_skills us
         JOIN skills s ON s.skillid = us.skillid
         WHERE us.userid = :userid
         ORDER BY us.display_order ASC, s.name ASC'
    );
    $skills->execute([':userid' => $userid]);

    $repositories = [];
    $languages = [];
    $totalStars = 0;

    if ($row['githubid'] !== null) {
        $repos = $db->prepare(
            'SELECT github_repo_id, name, description, url, language, stars, forks, is_featured
             FROM github_repositories
             WHERE githubid = :githubid
             ORDER BY is_featured DESC, stars DESC, name ASC'
        );
        $repos->execute([':githubid' => (int) $row['githubid']]);
        foreach ($repos->fetchAll() as $repo) {
            $repo['stars'] = (int) $repo['stars'];
            $repo['forks'] = (int) $repo['forks'];
            $repo['is_featured'] = (int) $repo['is_featured'];
            $totalStars += $repo['stars'];
            $repositories[] = $repo;
        }

        $langs = $db->prepare(
            "SELECT language, COUNT(*) AS repos, COALESCE(SUM(stars), 0) AS stars
             FROM github_repositories
             WHERE githubid = :githubid AND language IS NOT NULL AND language <> ''
             GROUP BY language
             ORDER BY repos DESC, language ASC"
        );
        $langs->execute([':githubid' => (int) $row['githubid']]);
        foreach ($langs->fetchAll() as $lang) {
            $languages[] = [
                'language' => $lang['language'],
                'repos'    => (int) $lang['repos'],
                'stars'    => (int) $lang['stars'],
            ];
        }
    }

    return [
        'userid'      => (int) $row['userid'],
        'login'       => $row['loginuid'],
        'displayName' => $row['display_name'],
        'firstName'   => $row['firstname'],
        'lastName'    => $row['lastname'],
        'avatarUrl'   => $row['github_avatar_url'] ?: $row['avatar_url'],
        'jobTitle'    => $row['job_title'],
        'location'    => $row['location'],
        'bio'         => $row['bio'],
        'github'      => $row['githubid'] !== null ? $row['username'] : null,
        'followers'   => (int) $row['followers'],
        'following'   => (int) $row['following'],
        'publicRepos' => (int) $row['public_repos'],
        'totalStars'  => $totalStars,
        'skills'      => array_map(function ($skill) {
            return [
                'skillid'     => (int) $skill['skillid'],
                'name'        => $skill['name'],
                'category'    => $skill['category'],
                'proficiency' => $skill['proficiency'],
            ];
        }, $skills->fetchAll()),
        'languages'    => $languages,
        'repositories' => $repositories,
    ];
}
