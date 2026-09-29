<?php
/**
 * Validates a UTF-8 string against the database field limit.
 * @param mixed $value Submitted value.
 * @param int $limit Maximum characters.
 * @param string $label Field name for the error.
 * @return string Cleaned text.
 */
function validatedText($value, $limit, $label) {
    if (!is_string($value)) respond(400, ['error' => $label . ' must be text']);
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length > $limit) respond(400, ['error' => $label . ' must be valid text of at most ' . $limit . ' characters']);
    return clean($value);
}

/**
 * Validates an absolute HTTP(S) URL without credentials or control characters.
 * @param mixed $value Submitted URL.
 * @param int $limit Database column length.
 * @param bool $optional Whether an empty URL is accepted.
 * @return string Validated URL.
 */
function validatedWebUrl($value, $limit = 255, $optional = false) {
    $url = validatedText($value, $limit, 'URL');
    if ($optional && $url === '') return '';
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !$parts ||
        !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
        isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
        respond(400, ['error' => 'Enter a valid HTTP or HTTPS URL']);
    }
    return $url;
}

/**
 * Validates a new account handle; existing accounts remain usable.
 * @param mixed $value Submitted username.
 * @return string Validated handle.
 */
function validatedLogin($value) {
    $login = validatedText($value, 50, 'Username');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/D', $login)) {
        respond(400, ['error' => 'Username must be 3–50 letters, numbers, dots, underscores or hyphens']);
    }
    return $login;
}
