<?php
// Souverain.ovh

declare(strict_types=1);

/* Shared bounds and validation for the public comments endpoints. */

function fc_ip_in_range(string $packed, string $network, int $prefix): bool
{
    $base = inet_pton($network);

    if ($base === false || strlen($base) !== strlen($packed)) {
        return false;
    }

    $bytes = intdiv($prefix, 8);
    $bits = $prefix % 8;

    if (substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) {
        return false;
    }

    return $bits === 0 ||
        (ord($packed[$bytes]) & (0xff << (8 - $bits))) ===
        (ord($base[$bytes]) & (0xff << (8 - $bits)));
}


function fc_public_ip(string $ip): bool
{
    $packed = @inet_pton($ip);

    if ($packed === false) {
        return false;
    }

    /* Apply the IPv4 rules to IPv4-mapped IPv6 addresses as well. */
    if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $packed = substr($packed, 12);
    }

    if (strlen($packed) === 4) {
        $excluded = [
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10],
            ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
            ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24],
            ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
            ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]
        ];

        foreach ($excluded as [$network, $prefix]) {
            if (fc_ip_in_range($packed, $network, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /* Native global unicast only; exclude transition and special-use ranges. */
    if (!fc_ip_in_range($packed, '2000::', 3)) {
        return false;
    }

    foreach ([['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20]] as [$network, $prefix]) {
        if (fc_ip_in_range($packed, $network, $prefix)) {
            return false;
        }
    }

    return true;
}


function fc_read_bounded_stream($stream, int $maxBytes): string
{
    $body = stream_get_contents($stream, $maxBytes + 1);

    if ($body === false) {
        throw new RuntimeException('Impossible de lire la requête.', 400);
    }

    if (strlen($body) > $maxBytes) {
        throw new LengthException('Requête trop volumineuse.', 413);
    }

    return $body;
}


function fc_read_json_request(int $maxBytes): array
{
    $type = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));

    if ($type !== 'application/json') {
        throw new InvalidArgumentException('Type de contenu non pris en charge.', 415);
    }

    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        throw new LengthException('Requête trop volumineuse.', 413);
    }

    $stream = fopen('php://input', 'rb');

    if ($stream === false) {
        throw new RuntimeException('Impossible de lire la requête.', 400);
    }

    try {
        $body = fc_read_bounded_stream($stream, $maxBytes);
    } finally {
        fclose($stream);
    }

    $input = json_decode($body, true);

    if (!is_array($input)) {
        throw new InvalidArgumentException('Requête JSON invalide.', 400);
    }

    return $input;
}


function fc_curl_exec_bounded($curl, int $maxBytes = 1_000_000): string|false
{
    $body = '';
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$body, $maxBytes): int {
        if (strlen($body) + strlen($chunk) > $maxBytes) {
            return 0;
        }

        $body .= $chunk;
        return strlen($chunk);
    });

    return curl_exec($curl) === false ? false : $body;
}


function fc_utf8_length(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }

    $count = preg_match_all('/./us', $text);

    if ($count === false) {
        throw new InvalidArgumentException('Texte UTF-8 invalide.');
    }

    return $count;
}


function fc_utf8_prefix(string $text, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $length, 'UTF-8');
    }

    if (preg_match_all('/./us', $text, $characters) === false) {
        throw new InvalidArgumentException('Texte UTF-8 invalide.');
    }

    return implode('', array_slice($characters[0], 0, $length));
}


function fc_return_to(string $value): ?string
{
    if ($value === '' || strlen($value) > 2048) {
        return null;
    }

    /* Decode for validation only. Keep the original encoding in the redirect. */
    $checked = $value;

    for ($i = 0; $i < 3; $i++) {
        if (
            !str_starts_with($checked, '/') ||
            str_starts_with($checked, '//') ||
            preg_match($i === 0 ? '/[\x00-\x20\x7f\\\\]/' : '/[\x00-\x1f\x7f\\\\]/', $checked) === 1
        ) {
            return null;
        }

        $decoded = rawurldecode($checked);

        if ($decoded === $checked) {
            break;
        }

        $checked = $decoded;
    }

    if (
        !str_starts_with($checked, '/') || str_starts_with($checked, '//') ||
        preg_match('/[\x00-\x1f\x7f\\\\]/', $checked) === 1 || rawurldecode($checked) !== $checked
    ) {
        return null;
    }

    $parts = parse_url($value);

    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $path = (string)($parts['path'] ?? '');

    $decodedPath = (string)(parse_url($checked, PHP_URL_PATH) ?? '');

    if ($path === '' || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decodedPath) === 1) {
        return null;
    }

    return $value;
}


function fc_mastodon_return_url(string $value): string
{
    $safe = fc_return_to($value) ?? fc_base_path() . '/test.html';
    $parts = parse_url($safe);
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $query['mastodon'] = 'connected';

    return fc_site_origin() . $parts['path'] . '?' . http_build_query($query) .
        (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}


function fc_normalize_article_url(string $url): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $parts = parse_url($url);

    if (
        !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
        strtolower((string)($parts['host'] ?? '')) !== fc_site_host() ||
        (int)($parts['port'] ?? 443) !== (fc_site_port() ?? 443) ||
        isset($parts['user']) || isset($parts['pass'])
    ) {
        return null;
    }

    $path = (string)($parts['path'] ?? '/');

    if (fc_return_to($path) === null) {
        return null;
    }

    $path = rtrim($path, '/') . '/';
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);

    foreach (array_keys($query) as $key) {
        if (str_starts_with(strtolower((string)$key), 'utm_') || in_array(strtolower((string)$key), ['fbclid', 'gclid'], true)) {
            unset($query[$key]);
        }
    }

    ksort($query);

    return fc_site_origin() . $path . ($query ? '?' . http_build_query($query) : '');
}


function fc_status_contains_article(array $status, string $articleUrl): bool
{
    $links = [];

    if (is_string($status['card']['url'] ?? null)) {
        $links[] = $status['card']['url'];
    }

    preg_match_all('~href=(?:"([^"]+)"|\'([^\']+)\')~i', (string)($status['content'] ?? ''), $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $links[] = html_entity_decode((string)($match[1] !== '' ? $match[1] : ($match[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    foreach ($links as $link) {
        if (fc_normalize_article_url($link) === $articleUrl) {
            return true;
        }
    }

    return false;
}


function fc_mastodon_status_id(string $url): ?string
{
    $parts = parse_url($url);
    $username = preg_quote(fc_mastodon_username(), '~');
    $pattern = '~^/(?:@' . $username . '/(?:statuses/)?|users/' . $username .
        '/statuses/)([A-Za-z0-9_-]{1,100})/?$~';

    if (
        !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
        strtolower((string)($parts['host'] ?? '')) !== fc_mastodon_host() ||
        (isset($parts['port']) && (int)$parts['port'] !== 443) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
        preg_match($pattern, (string)($parts['path'] ?? ''), $match) !== 1
    ) {
        return null;
    }

    return $match[1];
}


function fc_mastodon_root_matches_article(array $status, string $statusUrl, string $articleUrl): bool
{
    $account = $status['account'] ?? [];

    return fc_mastodon_status_id($statusUrl) !== null &&
        fc_mastodon_status_id((string)($status['url'] ?? '')) === fc_mastodon_status_id($statusUrl) &&
        is_array($account) &&
        ($account['username'] ?? '') === fc_mastodon_username() &&
        in_array($account['acct'] ?? '', [fc_mastodon_username(), fc_mastodon_username() . '@' . fc_mastodon_host()], true) &&
        rtrim((string)($account['url'] ?? ''), '/') === fc_mastodon_base_url() . '/@' . fc_mastodon_username() &&
        empty($status['in_reply_to_id']) && empty($status['reblog']) &&
        in_array($status['visibility'] ?? '', ['public', 'unlisted'], true) &&
        fc_status_contains_article($status, $articleUrl);
}
