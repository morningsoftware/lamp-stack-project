<?php
// ============================================================
//  api/index.php — Front controller / router
// ============================================================

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';
setCORSHeaders();

$segments = pathSegments();
$resource = $segments[0] ?? '';

switch ($resource) {
    case 'ping':
        respond(200, ['data' => ['status' => 'OK']]);
        break;

    case 'auth':
        require __DIR__ . '/handlers/auth.php';
        break;

    case 'profiles':
        require __DIR__ . '/handlers/profiles.php';
        break;

    case 'compare':
        require __DIR__ . '/handlers/compare.php';
        break;

    case 'contacts':
        require __DIR__ . '/handlers/contacts.php';
        break;

    case 'admin':
        require __DIR__ . '/handlers/admin.php';
        break;

    case 'skills':
        require __DIR__ . '/handlers/skills.php';
        break;

    case 'github':
        require __DIR__ . '/handlers/github.php';
        break;

    case 'conversations':
    case 'messages':
        require __DIR__ . '/handlers/conversations.php';
        break;

    default:
        respond(404, ['error' => 'Not found']);
}
