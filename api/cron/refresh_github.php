<?php
// ============================================================
//  api/cron/refresh_github.php — refresh stale GitHub data (CLI)
//
//  Usage:  php api/cron/refresh_github.php [limit]
//  Run on a schedule (cron/launchd) to keep cached GitHub data fresh
//  even when profiles are not being viewed.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/github.php';

$db    = getDB();
$limit = isset($argv[1]) ? (int) $argv[1] : 20;
if ($limit <= 0) {
    $limit = 20;
}

$hours = githubRefreshHours();

$stmt = $db->prepare(
    'SELECT g.userid, g.username
     FROM github_profiles g
     WHERE g.last_synced IS NULL OR g.last_synced < (NOW() - INTERVAL :hours HOUR)
     ORDER BY g.last_synced IS NULL DESC, g.last_synced ASC
     LIMIT :limit'
);
$stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$ok   = 0;
$fail = 0;

foreach ($rows as $row) {
    try {
        syncGithubForUser($db, (int) $row['userid'], $row['username']);
        $ok++;
        fwrite(STDOUT, "synced {$row['username']}\n");
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, "failed {$row['username']}: {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, "done: {$ok} synced, {$fail} failed\n");
