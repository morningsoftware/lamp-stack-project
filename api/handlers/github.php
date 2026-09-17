<?php
// ============================================================
//  api/handlers/github.php — GitHub profile/repository sync & read
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$db       = getDB();
$segments = pathSegments();
$target   = $segments[1] ?? '';

if ($target === 'sync') {
    requireMethod('POST');
    syncGithub($db);
}

if ($target === '') {
    respond(404, ['error' => 'Not found']);
}

requireMethod('GET');
requireAuth();
getGithub($db, requireId($target, 'user id'));

/**
 * Fetches a developer's GitHub profile and repositories and stores them.
 *
 * @param PDO $db
 */
function syncGithub($db) {
    $userid = requireAuth();

    if (!function_exists('curl_init')) {
        respond(500, ['error' => 'The PHP cURL extension is required for GitHub sync']);
    }

    $body     = getRequestBody();
    $username = isset($body['username']) ? clean($body['username']) : '';

    if ($username === '') {
        $stmt = $db->prepare('SELECT username FROM github_profiles WHERE userid = :userid');
        $stmt->execute([':userid' => $userid]);
        $row = $stmt->fetch();
        $username = $row ? $row['username'] : '';
    }

    if ($username === '') {
        respond(400, ['error' => 'A GitHub username is required']);
    }

    $profile = githubRequest("https://api.github.com/users/{$username}");
    if ($profile === null || !isset($profile['login'])) {
        respond(502, ['error' => 'Could not fetch GitHub profile']);
    }

    $repos = githubRequest("https://api.github.com/users/{$username}/repos?per_page=100&sort=updated");
    if (!is_array($repos)) {
        $repos = [];
    }

    try {
        $db->beginTransaction();

        $upsertProfile = $db->prepare(
            'INSERT INTO github_profiles
                (userid, username, avatar_url, profile_url, bio, followers, following,
                 public_repos, public_gists, last_synced)
             VALUES
                (:userid, :username, :avatar, :url, :bio, :followers, :following,
                 :repos, :gists, NOW())
             ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                avatar_url = VALUES(avatar_url),
                profile_url = VALUES(profile_url),
                bio = VALUES(bio),
                followers = VALUES(followers),
                following = VALUES(following),
                public_repos = VALUES(public_repos),
                public_gists = VALUES(public_gists),
                last_synced = NOW()'
        );
        $upsertProfile->execute([
            ':userid'    => $userid,
            ':username'  => $profile['login'],
            ':avatar'    => $profile['avatar_url'] ?? null,
            ':url'       => $profile['html_url'] ?? null,
            ':bio'       => $profile['bio'] ?? null,
            ':followers' => (int) ($profile['followers'] ?? 0),
            ':following' => (int) ($profile['following'] ?? 0),
            ':repos'     => (int) ($profile['public_repos'] ?? 0),
            ':gists'     => (int) ($profile['public_gists'] ?? 0),
        ]);

        $stmt = $db->prepare('SELECT githubid FROM github_profiles WHERE userid = :userid');
        $stmt->execute([':userid' => $userid]);
        $githubid = (int) $stmt->fetch()['githubid'];

        $upsertRepo = $db->prepare(
            'INSERT INTO github_repositories
                (githubid, github_repo_id, name, description, url, language, stars, forks,
                 is_fork, commits_30d, weekly_commits, daily_commits)
             VALUES
                (:githubid, :repo_id, :name, :description, :url, :language, :stars, :forks,
                 :is_fork, :commits_30d, :weekly_commits, :daily_commits)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                url = VALUES(url),
                language = VALUES(language),
                stars = VALUES(stars),
                forks = VALUES(forks),
                is_fork = VALUES(is_fork),
                commits_30d = VALUES(commits_30d),
                weekly_commits = VALUES(weekly_commits),
                daily_commits = VALUES(daily_commits)'
        );

        // Commit activity is fetched only for the top repositories to avoid
        // exhausting the GitHub API rate limit.
        $topRepos = array_values(array_filter($repos, function ($repo) {
            return isset($repo['id'], $repo['name']);
        }));
        usort($topRepos, function ($a, $b) {
            return (int) ($b['stargazers_count'] ?? 0) <=> (int) ($a['stargazers_count'] ?? 0);
        });
        $topRepos = array_slice($topRepos, 0, 10);

        foreach ($topRepos as $repo) {
            [$commits30d, $weekly, $daily] = fetchCommitActivity($username, $repo);
            $upsertRepo->execute([
                ':githubid'       => $githubid,
                ':repo_id'        => (int) $repo['id'],
                ':name'           => clean($repo['name']),
                ':description'    => isset($repo['description']) ? clean($repo['description']) : null,
                ':url'            => $repo['html_url'] ?? null,
                ':language'       => $repo['language'] ?? null,
                ':stars'          => (int) ($repo['stargazers_count'] ?? 0),
                ':forks'          => (int) ($repo['forks_count'] ?? 0),
                ':is_fork'        => !empty($repo['fork']) ? 1 : 0,
                ':commits_30d'    => $commits30d,
                ':weekly_commits' => $weekly,
                ':daily_commits'  => $daily,
            ]);
        }

        // Any repos beyond the top set are still stored (without commit stats).
        $upsertNoStats = $db->prepare(
            'INSERT INTO github_repositories
                (githubid, github_repo_id, name, description, url, language, stars, forks, is_fork)
             VALUES
                (:githubid, :repo_id, :name, :description, :url, :language, :stars, :forks, :is_fork)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                url = VALUES(url),
                language = VALUES(language),
                stars = VALUES(stars),
                forks = VALUES(forks),
                is_fork = VALUES(is_fork)'
        );
        $ids = array_column($topRepos, 'id');
        foreach ($repos as $repo) {
            if (!isset($repo['id'], $repo['name']) || in_array($repo['id'], $ids, true)) {
                continue;
            }
            $upsertNoStats->execute([
                ':githubid'    => $githubid,
                ':repo_id'     => (int) $repo['id'],
                ':name'        => clean($repo['name']),
                ':description' => isset($repo['description']) ? clean($repo['description']) : null,
                ':url'         => $repo['html_url'] ?? null,
                ':language'    => $repo['language'] ?? null,
                ':stars'       => (int) ($repo['stargazers_count'] ?? 0),
                ':forks'       => (int) ($repo['forks_count'] ?? 0),
                ':is_fork'     => !empty($repo['fork']) ? 1 : 0,
            ]);
        }

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('GitHub sync error: ' . $e->getMessage());
        respond(500, ['error' => 'GitHub sync failed']);
    }

    respond(200, ['data' => [
        'username'   => $profile['login'],
        'reposCount' => count($repos),
        'lastSynced' => date('c'),
    ]]);
}

/**
 * Returns the stored GitHub profile and repositories for a developer.
 *
 * @param PDO $db
 * @param int $userid
 */
function getGithub($db, $userid) {
    $stmt = $db->prepare(
        'SELECT githubid, username, avatar_url, profile_url, bio, followers, following,
                public_repos, public_gists, last_synced
         FROM github_profiles
         WHERE userid = :userid'
    );
    $stmt->execute([':userid' => $userid]);
    $profile = $stmt->fetch();

    if (!$profile) {
        respond(404, ['error' => 'No GitHub profile linked']);
    }

    $repoStmt = $db->prepare(
        'SELECT repo_id, github_repo_id, name, description, url, language, stars, forks,
                is_fork, is_featured, commits_30d, weekly_commits, daily_commits
         FROM github_repositories
         WHERE githubid = :githubid
         ORDER BY is_featured DESC, stars DESC, name ASC'
    );
    $repoStmt->execute([':githubid' => (int) $profile['githubid']]);

    respond(200, ['data' => [
        'githubid'    => (int) $profile['githubid'],
        'username'    => $profile['username'],
        'avatarUrl'   => $profile['avatar_url'],
        'profileUrl'  => $profile['profile_url'],
        'bio'         => $profile['bio'],
        'followers'   => (int) $profile['followers'],
        'following'   => (int) $profile['following'],
        'publicRepos' => (int) $profile['public_repos'],
        'publicGists' => (int) $profile['public_gists'],
        'lastSynced'  => $profile['last_synced'],
        'repositories' => $repoStmt->fetchAll(),
    ]]);
}

/**
 * Fetches per-week commit activity for a repository and derives the weekly
 * and daily commit series plus the commit count for the last 30 days.
 *
 * @param string $username Owner of the repository
 * @param array $repo Repository data from the GitHub API
 * @return array{0:int,1:string|null,2:string|null} [commits in 30 days, weekly JSON, daily JSON]
 */
function fetchCommitActivity($username, $repo) {
    $owner = $repo['owner']['login'] ?? $username;
    $name  = $repo['name'];
    $data  = githubRequest("https://api.github.com/repos/{$owner}/{$name}/stats/commit_activity");

    if (!is_array($data)) {
        return [0, null, null];
    }

    $weekly = [];
    $daily  = [];
    foreach (array_slice($data, -52) as $week) {
        $weekly[] = (int) ($week['total'] ?? 0);
        $days = isset($week['days']) && is_array($week['days']) ? $week['days'] : array_fill(0, 7, 0);
        foreach ($days as $count) {
            $daily[] = (int) $count;
        }
    }

    $daily = array_slice($daily, -84);
    $commits30d = array_sum(array_slice($daily, -30));

    return [$commits30d, json_encode($weekly), json_encode($daily)];
}

/**
 * Performs an authenticated GitHub API GET request.
 *
 * @param string $url
 * @return array|null Decoded response, or null on failure
 */
function githubRequest($url) {    $token = getenv('GITHUB_TOKEN') ?: '';

    $headers = [
        'User-Agent: developer-bio-site',
        'Accept: application/vnd.github+json',
    ];
    if ($token !== '') {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log("GitHub API request to {$url} failed with status {$status}");
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}
