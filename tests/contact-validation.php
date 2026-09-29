<?php
function clean($value) { return trim($value); }
function respond($status, $body) { throw new RuntimeException($body['error'], $status); }
$source = file_get_contents($argv[1] ?? __DIR__ . '/../api/handlers/contacts.php');
eval(substr($source, strpos($source, 'function validateContactFields(')));
$valid = validateContactFields(['firstName' => str_repeat('é', 50), 'description' => "line\none"]);
if (contactTextLength($valid['firstName']) !== 50) throw new RuntimeException('Unicode length mismatch');
foreach ([['firstName' => str_repeat('é', 51)], ['firstName' => 'QA', 'phone' => '12345678901'], ['firstName' => "\xFF"]] as $fields) {
    try { validateContactFields($fields); } catch (RuntimeException $e) {
        if ($e->getCode() === 400) continue;
        throw $e;
    }
    throw new RuntimeException('Invalid input accepted');
}
echo "PASS: Unicode boundaries, phone length, invalid UTF-8\n";
