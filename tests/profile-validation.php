<?php
require __DIR__ . '/../api/config/validation.php';
function clean($v) { return trim(strip_tags($v)); }
function respond($status, $body) { throw new RuntimeException($body['error'], $status); }
if (validatedText(str_repeat('é',50),50,'Name') !== str_repeat('é',50)) throw new RuntimeException('Unicode boundary failed');
if (validatedWebUrl('https://example.com/path') !== 'https://example.com/path') throw new RuntimeException('URL rejected');
foreach ([fn()=>validatedText([],50,'Name'), fn()=>validatedText(str_repeat('a',51),50,'Name'), fn()=>validatedWebUrl('javascript:void(0)'), fn()=>validatedWebUrl('https://user:pass@example.com'), fn()=>validatedLogin('qa" data-audit="x')] as $test) {
    try { $test(); } catch (RuntimeException $e) { if ($e->getCode()===400) continue; throw $e; }
    throw new RuntimeException('Invalid input accepted');
}
echo "PASS: typed profile fields, Unicode limits, URL schemes, username validation\n";
