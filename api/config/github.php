<?php
// ============================================================
//  api/config/github.php — Shared GitHub API sync helpers
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Number of hours before cached GitHub data is considered stale.
 *
 * @return int
 */
function githubRefreshHours() {
    $hours = (int) (getenv('GITHUB_REFRESH_HOURS') ?: 24);
    return $hours > 0 ? $hours : 24;
}

/**
 * Whether a last_synced timestamp is missing or older than the TTL.
 *
 * @param string|null $lastSynced
 * @return bool
 */
function isGithubStale($lastSynced) {
    if (empty($lastSynced)) {
        return true;
    }
    $ts = strtotime((string) $lastSynced);
    if ($ts === false) {
        return true;
    }
    return (time() - $ts) > githubRefreshHours() * 3600;
}

/**
 * Validates a GitHub username format.
 *
 * @param string $username
 * @return bool
 */
function githubUsernameValid($username) {
    return (bool) preg_match('/^[a-z\d](?:[a-z\d]|-(?=[a-z\d])){0,38}$/i', (string) $username);
}

/**
 * Performs a GitHub API GET request and returns status + decoded data.
 *
 * @param string $url
 * @return array{status:int,data:array|null}
 */
function githubFetch($url) {
    $token = getenv('GITHUB_TOKEN') ?: '';

    $headers = [
        'User-Agent: collab.dev',
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
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        return ['status' => 0, 'data' => null];
    }
    $decoded = json_decode($response, true);
    return ['status' => $status, 'data' => is_array($decoded) ? $decoded : null];
}

/**
 * Performs a GitHub API GET and returns decoded data, or null on failure.
 *
 * @param string $url
 * @return array|null
 */
function githubRequest($url) {
    $result = githubFetch($url);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log("GitHub API request to {$url} failed with status {$result['status']}");
        return null;
    }
    return $result['data'];
}

/**
 * Fetches per-week commit activity and derives weekly/daily series plus
 * the commit count for the last 30 days.
 *
 * @param string $username Owner of the repository
 * @param array $repo Repository data from the GitHub API
 * @return array{0:int,1:string|null,2:string|null}
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
 * Fetches and stores a user's GitHub profile and repositories.
 *
 * @param PDO $db
 * @param int $userid
 * @param string $username
 * @param array|null $profile Pre-fetched GitHub profile (optional)
 * @param array|null $repos Pre-fetched repositories (optional)
 * @return array Summary of the sync
 * @throws RuntimeException when the GitHub profile cannot be fetched
 */
function syncGithubForUser($db, $userid, $username, $profile = null, $repos = null) {
    if ($profile === null) {
        $result = githubFetch("https://api.github.com/users/{$username}");
        if ($result['status'] === 404) {
            throw new RuntimeException('GitHub user not found');
        }
        if ($result['status'] < 200 || $result['status'] >= 300 || !isset($result['data']['login'])) {
            throw new RuntimeException('Could not fetch GitHub profile');
        }
        $profile = $result['data'];
    }

    $resolvedUsername = $profile['login'];

    if ($repos === null) {
        $repos = githubRequest("https://api.github.com/users/{$resolvedUsername}/repos?per_page=100&sort=updated");
        if (!is_array($repos)) {
            $repos = [];
        }
    }

    $db->beginTransaction();

    try {
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
        ':username'  => $resolvedUsername,
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
        [$commits30d, $weekly, $daily] = fetchCommitActivity($resolvedUsername, $repo);
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
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        throw new RuntimeException('GitHub sync failed: ' . $e->getMessage());
    }

    return [
        'username'   => $resolvedUsername,
        'reposCount' => count($repos),
        'lastSynced' => date('c'),
    ];
}
