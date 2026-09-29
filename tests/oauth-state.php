<?php
require __DIR__ . '/../api/config/oauth-state.php';
function bearerToken() { return 'test-token'; }
function hashToken($token) { return hash('sha256', $token); }
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
oauthRemember('connect:test', 42);
$_COOKIE['oauth_userid'] = '999';
$p = oauthConsume('connect:test');
check($p['userid'] === 42, 'Client cookie must not select target user');
check($p['session_hash'] === hashToken('test-token'), 'Link must bind original session');
check(oauthConsume('connect:test') === null, 'State must be single use');
oauthRemember('login:valid');
check(oauthConsume('login:wrong') === null, 'Mismatched state must fail');
oauthRemember('login:expired');
oauthSession();
$_SESSION['oauth']['expires'] = time() - 1;
session_write_close();
check(oauthConsume('login:expired') === null, 'Expired state must fail');
oauthRemember('login:good');
check(oauthConsume('login:good')['userid'] === null, 'Login state must not select a user');
echo "PASS: OAuth state binding, tampering, replay, mismatch, expiry, login mode\n";
