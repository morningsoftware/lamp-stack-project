<?php
// ============================================================
//  api/handlers/contacts.php — Full CRUD for the current user's
//  saved contacts, with search and pagination
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
            getContact($db, $userid, requireId($id, 'contact id'));
        }
        listContacts($db, $userid);
        break;

    case 'POST':
        createContact($db, $userid);
        break;

    case 'PUT':
        updateContact($db, $userid, requireId($id, 'contact id'));
        break;

    case 'DELETE':
        deleteContact($db, $userid, requireId($id, 'contact id'));
        break;

    default:
        header('Allow: GET, POST, PUT, DELETE');
        respond(405, ['error' => 'Method not allowed']);
}

/**
 * Lists the current user's contacts with SQL search and pagination.
 *
 * @param PDO $db
 * @param int $userid
 */
function listContacts($db, $userid) {
    $q      = isset($_GET['q']) ? clean($_GET['q']) : '';
    $page   = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit  = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $where  = 'c.userid = :userid';
    $params = [':userid' => $userid];

    if ($q !== '') {
        $like   = '%' . $q . '%';
        $where .= ' AND (c.firstname LIKE :q1 OR c.lastname LIKE :q2
                         OR c.email LIKE :q3 OR c.phone LIKE :q4 OR c.description LIKE :q5
                         OR u.loginuid LIKE :q6 OR u.displayname LIKE :q7)';
        $params += [
            ':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like,
            ':q5' => $like, ':q6' => $like, ':q7' => $like,
        ];
    }

    $countStmt = $db->prepare(
        'SELECT COUNT(*)
         FROM contacts c
         LEFT JOIN users u ON u.userid = c.contact_userid
         WHERE ' . $where
    );
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare(
        'SELECT c.contactid, c.contact_userid, c.firstname, c.lastname, c.description,
                c.email, c.phone, c.created_at,
                u.loginuid AS linked_login, u.displayname AS linked_displayname,
                u.avatar AS linked_avatar, u.jobtitle AS linked_jobtitle,
                u.location AS linked_location
         FROM contacts c
         LEFT JOIN users u ON u.userid = c.contact_userid
         WHERE ' . $where . '
         ORDER BY c.created_at DESC, c.contactid DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = array_map(function ($row) {
        $ownName = trim("{$row['firstname']} {$row['lastname']}");
        return [
            'contactid'   => (int) $row['contactid'],
            'userid'      => $row['contact_userid'] !== null ? (int) $row['contact_userid'] : null,
            'firstName'   => $row['firstname'],
            'lastName'    => $row['lastname'],
            'login'       => $row['linked_login'],
            'displayName' => $row['linked_displayname'] ?: $ownName,
            'avatarUrl'   => $row['linked_avatar'],
            'jobTitle'    => $row['linked_jobtitle'],
            'location'    => $row['linked_location'],
            'description' => $row['description'],
            'email'       => $row['email'],
            'phone'       => $row['phone'],
            'isDeveloper' => $row['contact_userid'] !== null,
            'createdAt'   => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    respond(200, ['data' => $data, 'meta' => [
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
    ]]);
}

/**
 * Returns one of the current user's contacts.
 *
 * @param PDO $db
 * @param int $userid
 * @param int $contactId
 */
function getContact($db, $userid, $contactId) {
    $stmt = $db->prepare(
        'SELECT contactid, contact_userid, firstname, lastname, description, email, phone, created_at
         FROM contacts
         WHERE contactid = :id AND userid = :userid'
    );
    $stmt->execute([':id' => $contactId, ':userid' => $userid]);
    $row = $stmt->fetch();

    if (!$row) {
        respond(404, ['error' => 'Contact not found']);
    }

    respond(200, ['data' => contactShape($row)]);
}

/**
 * Creates a new contact for the current user.
 *
 * @param PDO $db
 * @param int $userid
 */
function createContact($db, $userid) {
    $body   = getRequestBody();
    $fields = validateContactFields($body);

    $stmt = $db->prepare(
        'INSERT INTO contacts (userid, firstname, lastname, description, email, phone)
         VALUES (:userid, :firstname, :lastname, :description, :email, :phone)'
    );
    $stmt->execute([
        ':userid'      => $userid,
        ':firstname'   => $fields['firstName'],
        ':lastname'    => $fields['lastName'],
        ':description' => $fields['description'],
        ':email'       => $fields['email'],
        ':phone'       => $fields['phone'],
    ]);

    $contactId = (int) $db->lastInsertId();
    respond(201, ['data' => array_merge(['contactid' => $contactId], $fields)]);
}

/**
 * Updates one of the current user's contacts.
 *
 * @param PDO $db
 * @param int $userid
 * @param int $contactId
 */
function updateContact($db, $userid, $contactId) {
    $exists = $db->prepare('SELECT contactid FROM contacts WHERE contactid = :id AND userid = :userid');
    $exists->execute([':id' => $contactId, ':userid' => $userid]);
    if (!$exists->fetch()) {
        respond(404, ['error' => 'Contact not found']);
    }

    $body   = getRequestBody();
    $fields = validateContactFields($body);

    $stmt = $db->prepare(
        'UPDATE contacts
         SET firstname = :firstname, lastname = :lastname, description = :description,
             email = :email, phone = :phone
         WHERE contactid = :id AND userid = :userid'
    );
    $stmt->execute([
        ':firstname'   => $fields['firstName'],
        ':lastname'    => $fields['lastName'],
        ':description' => $fields['description'],
        ':email'       => $fields['email'],
        ':phone'       => $fields['phone'],
        ':id'          => $contactId,
        ':userid'      => $userid,
    ]);

    respond(200, ['data' => array_merge(['contactid' => $contactId], $fields)]);
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

/**
 * Validates and normalizes contact fields, enforcing length limits and
 * requiring at least a name or email.
 *
 * @param array $body
 * @return array
 */
function validateContactFields($body) {
    $fields = [
        'firstName'   => isset($body['firstName']) ? clean($body['firstName']) : '',
        'lastName'    => isset($body['lastName']) ? clean($body['lastName']) : '',
        'email'       => isset($body['email']) ? clean($body['email']) : '',
        'phone'       => isset($body['phone']) ? clean($body['phone']) : '',
        'description' => isset($body['description']) ? clean($body['description']) : '',
    ];

    if (mb_strlen($fields['firstName']) > 50) {
        respond(400, ['error' => 'First name must be 50 characters or fewer']);
    }
    if (mb_strlen($fields['lastName']) > 50) {
        respond(400, ['error' => 'Last name must be 50 characters or fewer']);
    }
    if (mb_strlen($fields['email']) > 100) {
        respond(400, ['error' => 'Email must be 100 characters or fewer']);
    }
    if (mb_strlen($fields['phone']) > 10) {
        respond(400, ['error' => 'Phone must be 10 characters or fewer']);
    }
    if (mb_strlen($fields['description']) > 100) {
        respond(400, ['error' => 'Notes must be 100 characters or fewer']);
    }

    if ($fields['firstName'] === '' && $fields['lastName'] === '' && $fields['email'] === '') {
        respond(400, ['error' => 'Enter a name or an email address']);
    }

    return $fields;
}

/**
 * Public shape of a contact record.
 *
 * @param array $row
 * @return array
 */
function contactShape($row) {
    return [
        'contactid'   => (int) $row['contactid'],
        'firstName'   => $row['firstname'] ?? '',
        'lastName'    => $row['lastname'] ?? '',
        'email'       => $row['email'] ?? '',
        'phone'       => $row['phone'] ?? '',
        'description' => $row['description'] ?? '',
    ];
}
