<?php
// ============================================================
//  api/handlers/skills.php — Skill catalog
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$db     = getDB();
$method = requestMethod();

switch ($method) {
    case 'GET':
        $q = isset($_GET['q']) ? clean($_GET['q']) : '';
        if ($q !== '') {
            $stmt = $db->prepare(
                'SELECT skillid, name, category FROM skills WHERE name LIKE :q ORDER BY name ASC'
            );
            $stmt->execute([':q' => '%' . $q . '%']);
        } else {
            $stmt = $db->query('SELECT skillid, name, category FROM skills ORDER BY name ASC');
        }
        respond(200, ['data' => $stmt->fetchAll()]);
        break;

    case 'POST':
        requireAuth();
        $body = getRequestBody();
        requireFields($body, ['name']);

        try {
            $stmt = $db->prepare('INSERT INTO skills (name, category) VALUES (:name, :category)');
            $stmt->execute([
                ':name'     => clean($body['name']),
                ':category' => isset($body['category']) ? clean($body['category']) : null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respond(409, ['error' => 'Skill already exists']);
            }
            error_log('Create skill error: ' . $e->getMessage());
            respond(500, ['error' => 'Could not create skill']);
        }

        respond(201, ['data' => ['skillid' => (int) $db->lastInsertId()]]);
        break;

    default:
        header('Allow: GET, POST');
        respond(405, ['error' => 'Method not allowed']);
}
