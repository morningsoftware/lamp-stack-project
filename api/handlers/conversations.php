<?php
// ============================================================
//  api/handlers/conversations.php — Direct & group messaging
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$db       = getDB();
$segments = pathSegments();
$resource = $segments[0] ?? '';
$id       = $segments[1] ?? null;
$sub      = $segments[2] ?? null;

if ($resource === 'messages') {
    requireMethod('DELETE');
    deleteMessage($db, requireId($id, 'message id'));
}

$userid = requireAuth();

if ($id === null) {
    if (requestMethod() === 'GET') {
        listConversations($db, $userid);
    }
    if (requestMethod() === 'POST') {
        createConversation($db, $userid);
    }
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed']);
}

$conversationid = requireId($id, 'conversation id');

if ($sub === 'messages') {
    if (requestMethod() === 'GET') {
        listMessages($db, $conversationid, $userid);
    }
    if (requestMethod() === 'POST') {
        sendMessage($db, $conversationid, $userid);
    }
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed']);
}

if ($sub === 'read') {
    requireMethod('PUT');
    markRead($db, $conversationid, $userid);
}

if ($sub === 'participants') {
    $memberId = $segments[3] ?? null;

    if ($memberId === null) {
        if (requestMethod() === 'GET') {
            getConversation($db, $conversationid, $userid);
        }
        if (requestMethod() === 'POST') {
            addParticipants($db, $conversationid, $userid);
        }
        header('Allow: GET, POST');
        respond(405, ['error' => 'Method not allowed']);
    }

    if (requestMethod() === 'DELETE') {
        removeParticipant($db, $conversationid, $userid, requireId($memberId, 'user id'));
    }
    header('Allow: DELETE');
    respond(405, ['error' => 'Method not allowed']);
}

if ($sub === null) {
    if (requestMethod() === 'GET') {
        getConversation($db, $conversationid, $userid);
    }
    if (requestMethod() === 'PUT') {
        renameConversation($db, $conversationid, $userid);
    }
    header('Allow: GET, PUT');
    respond(405, ['error' => 'Method not allowed']);
}

respond(404, ['error' => 'Not found']);

/**
 * Lists the current user's conversations with last message and unread count.
 *
 * @param PDO $db
 * @param int $userid
 */
function listConversations($db, $userid) {
    $stmt = $db->prepare(
        "SELECT c.conversationid, c.name, c.last_message_at,
                (SELECT m.body FROM messages m
                  WHERE m.conversationid = c.conversationid
                  ORDER BY m.messageid DESC LIMIT 1) AS last_message,
                (SELECT m.sender_userid FROM messages m
                  WHERE m.conversationid = c.conversationid
                  ORDER BY m.messageid DESC LIMIT 1) AS last_sender,
                (SELECT COUNT(*) FROM messages m
                  WHERE m.conversationid = c.conversationid
                    AND m.sender_userid <> :me_unread
                    AND m.created_at > COALESCE(cp.last_read_at, '1970-01-01')) AS unread_count
         FROM conversation_participants cp
         JOIN conversations c ON c.conversationid = cp.conversationid
         WHERE cp.userid = :me_outer
         ORDER BY COALESCE(c.last_message_at, c.created_at) DESC"
    );
    $stmt->execute([':me_unread' => $userid, ':me_outer' => $userid]);
    $rows = $stmt->fetchAll();

    $ids = array_map(function ($row) {
        return (int) $row['conversationid'];
    }, $rows);
    $participants = fetchParticipants($db, $ids);

    $conversations = [];
    foreach ($rows as $row) {
        $cid = (int) $row['conversationid'];
        $others = array_values(array_filter(
            $participants[$cid] ?? [],
            function ($p) use ($userid) {
                return (int) $p['userid'] !== $userid;
            }
        ));
        $conversations[] = [
            'conversationid' => $cid,
            'name'           => $row['name'],
            'isGroup'        => count($others) > 1,
            'lastMessageAt'  => $row['last_message_at'],
            'lastMessage'    => $row['last_message'],
            'lastSender'     => $row['last_sender'] !== null ? (int) $row['last_sender'] : null,
            'unreadCount'    => (int) $row['unread_count'],
            'participants'   => $others,
        ];
    }

    respond(200, ['data' => $conversations]);
}

/**
 * Returns a single conversation with its participants.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 */
function getConversation($db, $conversationid, $userid) {
    requireParticipant($db, $conversationid, $userid);

    $stmt = $db->prepare(
        'SELECT conversationid, name, created_by, created_at, last_message_at
         FROM conversations WHERE conversationid = :cid'
    );
    $stmt->execute([':cid' => $conversationid]);
    $convo = $stmt->fetch();
    if (!$convo) {
        respond(404, ['error' => 'Conversation not found']);
    }

    $participants = fetchParticipants($db, [$conversationid]);

    respond(200, ['data' => [
        'conversationid' => (int) $convo['conversationid'],
        'name'           => $convo['name'],
        'createdBy'      => $convo['created_by'] !== null ? (int) $convo['created_by'] : null,
        'createdAt'      => $convo['created_at'],
        'lastMessageAt'  => $convo['last_message_at'],
        'isGroup'        => count($participants[$conversationid] ?? []) > 2,
        'participants'   => $participants[$conversationid] ?? [],
    ]]);
}

/**
 * Creates a direct conversation (one recipient) or a group (many recipients
 * and/or a name).
 *
 * @param PDO $db
 * @param int $userid
 */
function createConversation($db, $userid) {
    $body = getRequestBody();

    $raw = [];
    if (isset($body['participantIds']) && is_array($body['participantIds'])) {
        $raw = $body['participantIds'];
    } elseif (isset($body['recipientIds']) && is_array($body['recipientIds'])) {
        $raw = $body['recipientIds'];
    } elseif (isset($body['recipientId'])) {
        $raw = [$body['recipientId']];
    }

    $ids = [];
    foreach ($raw as $rid) {
        $rid = (int) $rid;
        if ($rid > 0 && $rid !== $userid) {
            $ids[$rid] = $rid;
        }
    }

    if (!$ids && isset($body['recipientLogin'])) {
        $lookup = $db->prepare('SELECT userid FROM users WHERE loginuid = :login LIMIT 1');
        $lookup->execute([':login' => clean($body['recipientLogin'])]);
        $row = $lookup->fetch();
        if ($row && (int) $row['userid'] !== $userid) {
            $ids[(int) $row['userid']] = (int) $row['userid'];
        }
    }

    if (!$ids) {
        respond(400, ['error' => 'At least one other participant is required']);
    }

    $ids = array_values($ids);
    ensureUsersExist($db, $ids);

    $name    = isset($body['name']) ? clean($body['name']) : '';
    $isGroup = count($ids) > 1 || $name !== '';

    if (!$isGroup) {
        $recipientId = $ids[0];

        $find = $db->prepare(
            'SELECT cp1.conversationid
             FROM conversation_participants cp1
             JOIN conversation_participants cp2
               ON cp2.conversationid = cp1.conversationid AND cp2.userid = :recipient
             WHERE cp1.userid = :userid
               AND (SELECT COUNT(*) FROM conversation_participants cp3
                     WHERE cp3.conversationid = cp1.conversationid) = 2
             LIMIT 1'
        );
        $find->execute([':userid' => $userid, ':recipient' => $recipientId]);
        $existing = $find->fetch();

        if ($existing) {
            $conversationid = (int) $existing['conversationid'];
            $status = 200;
        } else {
            try {
                $db->beginTransaction();
                $ins = $db->prepare('INSERT INTO conversations (created_by) VALUES (:me)');
                $ins->execute([':me' => $userid]);
                $conversationid = (int) $db->lastInsertId();
                insertParticipants($db, $conversationid, array_merge([$userid], $ids));
                $db->commit();
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Create conversation error: ' . $e->getMessage());
                respond(500, ['error' => 'Could not create conversation']);
            }
            $status = 201;
        }
    } else {
        try {
            $db->beginTransaction();
            $ins = $db->prepare('INSERT INTO conversations (name, created_by) VALUES (:name, :me)');
            $ins->execute([':name' => $name !== '' ? $name : null, ':me' => $userid]);
            $conversationid = (int) $db->lastInsertId();
            insertParticipants($db, $conversationid, array_merge([$userid], $ids));
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Create group error: ' . $e->getMessage());
            respond(500, ['error' => 'Could not create conversation']);
        }
        $status = 201;
    }

    if (isset($body['message']) && clean($body['message']) !== '') {
        insertMessage($db, $conversationid, $userid, clean($body['message']));
    }

    respond($status, ['data' => ['conversationid' => $conversationid]]);
}

/**
 * Adds one or more participants to an existing conversation.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 */
function addParticipants($db, $conversationid, $userid) {
    requireParticipant($db, $conversationid, $userid);

    $body = getRequestBody();
    $raw  = [];
    if (isset($body['userIds']) && is_array($body['userIds'])) {
        $raw = $body['userIds'];
    } elseif (isset($body['userId'])) {
        $raw = [$body['userId']];
    }

    $ids = [];
    foreach ($raw as $rid) {
        $rid = (int) $rid;
        if ($rid > 0) {
            $ids[$rid] = $rid;
        }
    }
    if (!$ids) {
        respond(400, ['error' => 'At least one user is required']);
    }
    $ids = array_values($ids);
    ensureUsersExist($db, $ids);

    insertParticipants($db, $conversationid, $ids);

    $participants = fetchParticipants($db, [$conversationid]);
    respond(200, ['data' => [
        'conversationid' => $conversationid,
        'participants'   => $participants[$conversationid] ?? [],
    ]]);
}

/**
 * Removes a participant. A user may always remove themselves; otherwise only
 * the conversation creator can remove others.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 * @param int $targetId
 */
function removeParticipant($db, $conversationid, $userid, $targetId) {
    requireParticipant($db, $conversationid, $userid);

    if ($targetId !== $userid) {
        $stmt = $db->prepare('SELECT created_by FROM conversations WHERE conversationid = :cid');
        $stmt->execute([':cid' => $conversationid]);
        $createdBy = (int) $stmt->fetchColumn();
        if ($createdBy !== $userid) {
            respond(403, ['error' => 'You can only remove yourself from this conversation']);
        }
    }

    $stmt = $db->prepare(
        'DELETE FROM conversation_participants WHERE conversationid = :cid AND userid = :userid'
    );
    $stmt->execute([':cid' => $conversationid, ':userid' => $targetId]);

    if ($stmt->rowCount() === 0) {
        respond(404, ['error' => 'That user is not in this conversation']);
    }

    respond(200, ['data' => ['conversationid' => $conversationid, 'removed' => $targetId]]);
}

/**
 * Renames a group conversation.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 */
function renameConversation($db, $conversationid, $userid) {
    requireParticipant($db, $conversationid, $userid);

    $body = getRequestBody();
    if (!array_key_exists('name', $body)) {
        respond(400, ['error' => 'A name is required']);
    }
    $name = clean($body['name']);

    $stmt = $db->prepare('UPDATE conversations SET name = :name WHERE conversationid = :cid');
    $stmt->execute([':name' => $name !== '' ? $name : null, ':cid' => $conversationid]);

    respond(200, ['data' => ['conversationid' => $conversationid, 'name' => $name !== '' ? $name : null]]);
}

/**
 * Returns paginated messages in a conversation, with sender details.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 */
function listMessages($db, $conversationid, $userid) {
    requireParticipant($db, $conversationid, $userid);

    $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    $limit  = max(1, min(100, $limit));
    $before = isset($_GET['before']) ? (int) $_GET['before'] : 0;

    $sql = 'SELECT m.messageid, m.sender_userid, m.body, m.created_at,
                   u.loginuid AS sender_login, u.displayname AS sender_displayname,
                   COALESCE(gp.avatar_url, u.avatar) AS sender_avatar
            FROM messages m
            JOIN users u ON u.userid = m.sender_userid
            LEFT JOIN github_profiles gp ON gp.userid = u.userid
            WHERE m.conversationid = :cid';
    $params = [':cid' => $conversationid];

    if ($before > 0) {
        $sql .= ' AND m.messageid < :before';
        $params[':before'] = $before;
    }
    $sql .= ' ORDER BY m.messageid DESC LIMIT :limit';

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $messages = array_reverse($stmt->fetchAll());
    respond(200, ['data' => $messages]);
}

/**
 * Sends a message in a conversation.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 */
function sendMessage($db, $conversationid, $userid) {
    requireParticipant($db, $conversationid, $userid);

    $body = getRequestBody();
    requireFields($body, ['body']);

    try {
        $messageid = insertMessage($db, $conversationid, $userid, clean($body['body']));
    } catch (PDOException $e) {
        error_log('Send message error: ' . $e->getMessage());
        respond(500, ['error' => 'Could not send message']);
    }

    respond(201, ['data' => ['messageid' => $messageid]]);
}

/**
 * Marks a conversation as read for the current user.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 */
function markRead($db, $conversationid, $userid) {
    requireParticipant($db, $conversationid, $userid);

    $stmt = $db->prepare(
        'UPDATE conversation_participants SET last_read_at = NOW()
         WHERE conversationid = :cid AND userid = :userid'
    );
    $stmt->execute([':cid' => $conversationid, ':userid' => $userid]);

    respond(200, ['data' => ['message' => 'Marked as read']]);
}

/**
 * Deletes a message the current user sent.
 *
 * @param PDO $db
 * @param int $messageid
 */
function deleteMessage($db, $messageid) {
    $userid = requireAuth();

    $stmt = $db->prepare('SELECT sender_userid FROM messages WHERE messageid = :id');
    $stmt->execute([':id' => $messageid]);
    $message = $stmt->fetch();

    if (!$message) {
        respond(404, ['error' => 'Message not found']);
    }
    if ((int) $message['sender_userid'] !== $userid) {
        respond(403, ['error' => 'You can only delete your own messages']);
    }

    $delete = $db->prepare('DELETE FROM messages WHERE messageid = :id');
    $delete->execute([':id' => $messageid]);

    respond(200, ['data' => ['message' => 'Message deleted']]);
}

/**
 * Inserts a message and updates the conversation timestamp.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 * @param string $body
 * @return int New message id
 */
function insertMessage($db, $conversationid, $userid, $body) {
    $stmt = $db->prepare(
        'INSERT INTO messages (conversationid, sender_userid, body)
         VALUES (:cid, :userid, :body)'
    );
    $stmt->execute([':cid' => $conversationid, ':userid' => $userid, ':body' => $body]);
    $messageid = (int) $db->lastInsertId();

    $touch = $db->prepare('UPDATE conversations SET last_message_at = NOW() WHERE conversationid = :cid');
    $touch->execute([':cid' => $conversationid]);

    return $messageid;
}

/**
 * Inserts participant rows (ignoring duplicates).
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int[] $userIds
 */
function insertParticipants($db, $conversationid, $userIds) {
    $stmt = $db->prepare(
        'INSERT IGNORE INTO conversation_participants (conversationid, userid)
         VALUES (:cid, :userid)'
    );
    foreach (array_unique($userIds) as $uid) {
        $stmt->execute([':cid' => $conversationid, ':userid' => (int) $uid]);
    }
}

/**
 * Ensures every id in the list belongs to an existing user.
 *
 * @param PDO $db
 * @param int[] $ids
 */
function ensureUsersExist($db, $ids) {
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT userid FROM users WHERE userid IN ({$placeholders})");
    $stmt->execute($ids);
    $found = array_map('intval', array_column($stmt->fetchAll(), 'userid'));

    if (count($found) !== count($ids)) {
        respond(404, ['error' => 'One or more users were not found']);
    }
}

/**
 * Ensures a user participates in a conversation.
 *
 * @param PDO $db
 * @param int $conversationid
 * @param int $userid
 */
function requireParticipant($db, $conversationid, $userid) {
    $stmt = $db->prepare(
        'SELECT 1 FROM conversation_participants
         WHERE conversationid = :cid AND userid = :userid LIMIT 1'
    );
    $stmt->execute([':cid' => $conversationid, ':userid' => $userid]);

    if (!$stmt->fetch()) {
        respond(403, ['error' => 'You are not a participant in this conversation']);
    }
}

/**
 * Loads participant summaries for a set of conversation ids.
 *
 * @param PDO $db
 * @param int[] $conversationIds
 * @return array<int, array>
 */
function fetchParticipants($db, $conversationIds) {
    if (!$conversationIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));
    $stmt = $db->prepare(
        "SELECT cp.conversationid, u.userid, u.loginuid, u.displayname,
                COALESCE(gp.avatar_url, u.avatar) AS avatar
         FROM conversation_participants cp
         JOIN users u ON u.userid = cp.userid
         LEFT JOIN github_profiles gp ON gp.userid = u.userid
         WHERE cp.conversationid IN ({$placeholders})
         ORDER BY cp.conversationid, u.displayname"
    );
    $stmt->execute($conversationIds);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $cid = (int) $row['conversationid'];
        $row['userid'] = (int) $row['userid'];
        $grouped[$cid][] = $row;
    }
    return $grouped;
}
