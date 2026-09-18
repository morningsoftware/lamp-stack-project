<?php
// ============================================================
//  api/handlers/github.php — GitHub profile/repository sync & read
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/github.php';

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
 * A user may always refresh their own data; other users only when stale.
 *
 * @param PDO $db
 */
function syncGithub($db) {
    $userid = requireAuth();

    if (!function_exists('curl_init')) {
        respond(500, ['error' => 'The PHP cURL extension is required for GitHub sync']);
    }

    $body     = getRequestBody();
    $targetId = isset($body['userid']) ? (int) $body['userid'] : $userid;
    $username = isset($body['username']) ? clean($body['username']) : '';

    $stmt = $db->prepare('SELECT username, last_synced FROM github_profiles WHERE userid = :userid');
    $stmt->execute([':userid' => $targetId]);
    $row = $stmt->fetch();

    if ($username === '') {
        $username = $row ? $row['username'] : '';
    }
    if ($username === '') {
        respond(400, ['error' => 'A GitHub username is required']);
    }

    // Only the owner can force a refresh; refreshing someone else is
    // allowed solely to replace stale cached data.
    if ($targetId !== $userid && $row && !isGithubStale($row['last_synced'])) {
        respond(200, ['data' => [
            'username'   => $row['username'],
            'reposCount' => 0,
            'lastSynced' => $row['last_synced'],
            'skipped'    => true,
        ]]);
    }

    try {
        $result = syncGithubForUser($db, $targetId, $username);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'GitHub user not found') {
            respond(400, ['error' => 'GitHub user not found']);
        }
        error_log('GitHub sync error: ' . $e->getMessage());
        respond(502, ['error' => 'GitHub sync failed']);
    }

    respond(200, ['data' => $result + ['skipped' => false]]);
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
        'stale'       => isGithubStale($profile['last_synced']),
        'repositories' => $repoStmt->fetchAll(),
    ]]);
}
