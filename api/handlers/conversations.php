<?php
// ============================================================
//  api/handlers/conversations.php — Direct messaging
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

respond(404, ['error' => 'Not found']);

/**
 * Lists the current user's conversations with last message and unread count.
 *
 * @param PDO $db
 * @param int $userid
 */
function listConversations($db, $userid) {
    $stmt = $db->prepare(
        "SELECT c.conversationid, c.last_message_at,
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
 * Finds or creates a direct conversation with another user.
 *
 * @param PDO $db
 * @param int $userid
 */
function createConversation($db, $userid) {
    $body = getRequestBody();

    $recipientId = isset($body['recipientId']) ? (int) $body['recipientId'] : 0;
    if ($recipientId <= 0 && isset($body['recipientLogin'])) {
        $lookup = $db->prepare('SELECT userid FROM users WHERE loginuid = :login LIMIT 1');
        $lookup->execute([':login' => clean($body['recipientLogin'])]);
        $row = $lookup->fetch();
        $recipientId = $row ? (int) $row['userid'] : 0;
    }

    if ($recipientId <= 0) {
        respond(400, ['error' => 'A valid recipient is required']);
    }
    if ($recipientId === $userid) {
        respond(400, ['error' => 'You cannot start a conversation with yourself']);
    }

    $exists = $db->prepare('SELECT userid FROM users WHERE userid = :userid');
    $exists->execute([':userid' => $recipientId]);
    if (!$exists->fetch()) {
        respond(404, ['error' => 'Recipient not found']);
    }

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
            $db->exec('INSERT INTO conversations () VALUES ()');
            $conversationid = (int) $db->lastInsertId();

            $add = $db->prepare(
                'INSERT INTO conversation_participants (conversationid, userid) VALUES (:cid, :userid)'
            );
            $add->execute([':cid' => $conversationid, ':userid' => $userid]);
            $add->execute([':cid' => $conversationid, ':userid' => $recipientId]);
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

    if (isset($body['message']) && clean($body['message']) !== '') {
        insertMessage($db, $conversationid, $userid, clean($body['message']));
    }

    respond($status, ['data' => ['conversationid' => $conversationid]]);
}

/**
 * Returns paginated messages in a conversation.
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

    $sql = 'SELECT messageid, sender_userid, body, created_at
            FROM messages
            WHERE conversationid = :cid';
    $params = [':cid' => $conversationid];

    if ($before > 0) {
        $sql .= ' AND messageid < :before';
        $params[':before'] = $before;
    }
    $sql .= ' ORDER BY messageid DESC LIMIT :limit';

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
        "SELECT cp.conversationid, u.userid, u.loginuid,
                p.display_name, p.avatar_url
         FROM conversation_participants cp
         JOIN users u ON u.userid = cp.userid
         LEFT JOIN profiles p ON p.userid = u.userid
         WHERE cp.conversationid IN ({$placeholders})
         ORDER BY cp.conversationid"
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
