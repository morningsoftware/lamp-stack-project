<?php
// ============================================================
//  api/handlers/contacts.php — The current user's saved contacts
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$db       = getDB();
$userid   = requireAuth();
$segments = pathSegments();
$id       = $segments[1] ?? null;
$method   = requestMethod();

switch ($method) {
    case 'GET':
        if ($id !== null) {
            header('Allow: GET');
            respond(405, ['error' => 'Method not allowed']);
        }
        listContacts($db, $userid);
        break;

    case 'DELETE':
        deleteContact($db, $userid, requireId($id, 'contact id'));
        break;

    default:
        header('Allow: GET, DELETE');
        respond(405, ['error' => 'Method not allowed']);
}

/**
 * Lists the current user's contacts, joined to linked app users.
 *
 * @param PDO $db
 * @param int $userid
 */
function listContacts($db, $userid) {
    $stmt = $db->prepare(
        'SELECT c.contactid, c.contact_userid, c.firstname, c.lastname,
                c.description, c.email, c.phone, c.created_at,
                u.loginuid AS linked_login,
                u.displayname AS linked_displayname,
                u.avatar AS linked_avatar,
                u.jobtitle AS linked_jobtitle,
                u.location AS linked_location
         FROM contacts c
         LEFT JOIN users u ON u.userid = c.contact_userid
         WHERE c.userid = :userid
         ORDER BY c.created_at DESC, c.contactid DESC'
    );
    $stmt->execute([':userid' => $userid]);

    $data = array_map(function ($row) {
        return [
            'contactid'     => (int) $row['contactid'],
            'userid'        => $row['contact_userid'] !== null ? (int) $row['contact_userid'] : null,
            'login'         => $row['linked_login'],
            'displayName'   => $row['linked_displayname'] ?: trim("{$row['firstname']} {$row['lastname']}"),
            'avatarUrl'     => $row['linked_avatar'],
            'jobTitle'      => $row['linked_jobtitle'],
            'location'      => $row['linked_location'],
            'description'   => $row['description'],
            'email'         => $row['email'],
            'phone'         => $row['phone'],
            'isDeveloper'   => $row['contact_userid'] !== null,
            'createdAt'     => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    respond(200, ['data' => $data]);
}

/**
 * Deletes one of the current user's contacts.
 *
 * @param PDO $db
 * @param int $userid
 * @param int $contactId
 */
function deleteContact($db, $userid, $contactId) {
    $stmt = $db->prepare('DELETE FROM contacts WHERE contactid = :id AND userid = :userid');
    $stmt->execute([':id' => $contactId, ':userid' => $userid]);

    if ($stmt->rowCount() === 0) {
        respond(404, ['error' => 'Contact not found']);
    }

    respond(200, ['data' => ['message' => 'Contact removed']]);
}
