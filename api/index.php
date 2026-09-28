<?php
// ============================================================
//  api/index.php — Front controller / router
// ============================================================

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';
setCORSHeaders();

// Serve a 503 while MAINTENANCE_MODE is enabled in the environment.
if (isMaintenanceMode()) {
    header('Retry-After: 3600');
    respond(503, [
        'error'       => 'The site is temporarily down for maintenance. Please try again shortly.',
        'maintenance' => true,
    ]);
}

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

    case 'organizations':
        require __DIR__ . '/handlers/organizations.php';
        break;

    case 'roles':
        require __DIR__ . '/handlers/roles.php';
        break;

    default:
        respond(404, ['error' => 'Not found']);
}
