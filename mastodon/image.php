<?php
// Souverain.ovh

declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/*
 * Proxy d'images Mastodon.
 *
 * Objectif : le navigateur ne contacte jamais directement les hôtes
 * des avatars Mastodon. Cela évite notamment la demande d'accès au
 * réseau local lorsque l’instance Mastodon est résolue localement en
 * 192.168.1.120.
 *
 * Le proxy n'accepte que HTTPS, refuse toutes les IP privées/réservées,
 * verrouille cURL sur l'IP DNS validée, ne suit pas les redirections et
 * limite strictement la taille et les types d'images acceptés.
 */

const MAX_IMAGE_BYTES = 2_000_000;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'");
header('Cross-Origin-Resource-Policy: same-origin');


function fail(int $status = 404): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    exit;
}


function publicIp(string $ip): bool
{
    return fc_public_ip($ip);
}


function resolvePublicIp(string $host): ?string
{
    $type = DNS_A;

    if (defined('DNS_AAAA')) {
        $type |= DNS_AAAA;
    }

    $records = @dns_get_record($host, $type);
    $addresses = [];

    if (is_array($records)) {
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $addresses[] = (string)$record['ip'];
            }

            if (!empty($record['ipv6'])) {
                $addresses[] = (string)$record['ipv6'];
            }
        }
    }

    if (!$addresses) {
        $ipv4 = @gethostbynamel($host);

        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }
    }

    $addresses = array_values(array_unique($addresses));

    if (!$addresses) {
        return null;
    }

    /* Refus total si une réponse DNS est privée ou réservée. */
    foreach ($addresses as $ip) {
        if (!publicIp($ip)) {
            return null;
        }
    }

    /* Préférence IPv4 pour la compatibilité. */
    foreach ($addresses as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
    }

    return $addresses[0];
}


function curlResolveEntry(string $host, string $ip): string
{
    $address = str_contains($ip, ':')
        ? '[' . $ip . ']'
        : $ip;

    return $host . ':443:' . $address;
}


function sameOriginRequest(): bool
{
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));

    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
        return false;
    }

    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');

    if ($referer !== '' && !str_starts_with($referer, fc_site_origin() . '/')) {
        return false;
    }

    return true;
}


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405);
}

if (!sameOriginRequest()) {
    fail(403);
}

$rawUrl = trim((string)($_GET['url'] ?? ''));

if ($rawUrl === '' || strlen($rawUrl) > 4096) {
    fail(400);
}

$parts = @parse_url($rawUrl);

if (!is_array($parts)) {
    fail(400);
}

$scheme = strtolower((string)($parts['scheme'] ?? ''));
$host = strtolower((string)($parts['host'] ?? ''));
$port = isset($parts['port']) ? (int)$parts['port'] : 443;

if (
    $scheme !== 'https' ||
    $host === '' ||
    $port !== 443 ||
    isset($parts['user']) ||
    isset($parts['pass'])
) {
    fail(400);
}

/* Les adresses IP littérales ne sont pas nécessaires ici. */
if (filter_var($host, FILTER_VALIDATE_IP)) {
    fail(400);
}

$ip = resolvePublicIp($host);

if ($ip === null) {
    fail(403);
}

$body = '';
$tooLarge = false;

$curl = curl_init($rawUrl);

if ($curl === false) {
    fail(502);
}

$options = [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => fc_user_agent('FederatedCommentsImageProxy'),
    CURLOPT_RESOLVE => [curlResolveEntry($host, $ip)],
    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
        if (strlen($body) + strlen($chunk) > MAX_IMAGE_BYTES) {
            $tooLarge = true;
            return 0;
        }

        $body .= $chunk;
        return strlen($chunk);
    }
];

if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
    $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
}

curl_setopt_array($curl, $options);
$ok = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$contentType = strtolower(trim((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
$error = curl_error($curl);
curl_close($curl);

if ($ok === false || $tooLarge || $status !== 200) {
    if ($error !== '') {
        error_log('FederatedComments image proxy: ' . $error);
    }

    fail(502);
}

/* Retirer d'éventuels paramètres du Content-Type. */
$contentType = trim(explode(';', $contentType, 2)[0]);

$allowedTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/avif',
    'image/x-icon',
    'image/vnd.microsoft.icon'
];

if (!in_array($contentType, $allowedTypes, true)) {
    fail(415);
}

if ($body === '') {
    fail(502);
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=86400, immutable');

echo $body;
