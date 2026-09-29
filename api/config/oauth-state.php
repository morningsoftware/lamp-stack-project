<?php
function oauthSession() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('collab_oauth');
    session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

function oauthRemember($state, $userid = null) {
    oauthSession();
    session_regenerate_id(true);
    $_SESSION['oauth'] = [
        'state' => $state,
        'userid' => $userid,
        'session_hash' => $userid === null ? null : hashToken(bearerToken()),
        'expires' => time() + 600,
    ];
    session_write_close();
}

function oauthConsume($state) {
    oauthSession();
    $pending = $_SESSION['oauth'] ?? [];
    unset($_SESSION['oauth']);
    session_write_close();
    $expected = (string) ($pending['state'] ?? '');
    if ($state === '' || $expected === '' || !hash_equals($expected, $state) || ($pending['expires'] ?? 0) < time()) {
        return null;
    }
    return $pending;
}
