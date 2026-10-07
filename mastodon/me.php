<?php
// Souverain.ovh

declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

function startSecureSession(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name(fc_session_name());

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => fc_cookie_path(),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}


function sendSecurityHeaders(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
}


startSecureSession();
sendSecurityHeaders();


header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

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

    /*
     * Si une seule des réponses DNS pointe vers une adresse
     * privée ou réservée, le nom est refusé intégralement.
     */
    foreach ($addresses as $ip) {
        if (!publicIp($ip)) {
            return null;
        }
    }

    /* Préférence IPv4 pour la compatibilité, sinon IPv6. */
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


function pinnedCurlOptions(string $host, string $ip): array
{
    $options = [
        CURLOPT_RESOLVE => [
            curlResolveEntry($host, $ip)
        ],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => fc_user_agent()
    ];

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    return $options;
}


if (
    empty($_SESSION['mastodon_auth']) ||
    !is_array($_SESSION['mastodon_auth'])
) {
    respond([
        'ok' => true,
        'connected' => false
    ]);
}

$auth = $_SESSION['mastodon_auth'];
$instance = (string)($auth['instance'] ?? '');
$accessToken = (string)($auth['access_token'] ?? '');

if (
    $instance === '' ||
    $accessToken === ''
) {
    unset($_SESSION['mastodon_auth']);

    respond([
        'ok' => true,
        'connected' => false
    ]);
}

$resolvedIp = resolvePublicIp($instance);

if ($resolvedIp === null) {
    unset($_SESSION['mastodon_auth']);

    respond([
        'ok' => false,
        'connected' => false,
        'error' => 'Cette instance Mastodon ne peut pas être contactée de manière sûre.'
    ], 502);
}

$url =
    'https://' .
    $instance .
    '/api/v1/accounts/verify_credentials';

$ch = curl_init($url);

$options = pinnedCurlOptions($instance, $resolvedIp) + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken
    ]
];

curl_setopt_array($ch, $options);

$response = fc_curl_exec_bounded($ch, 1_000_000);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false) {
    error_log(
        'Mastodon me network error for ' .
        $instance . ': ' . $curlError
    );

    respond([
        'ok' => false,
        'connected' => true,
        'error' => 'Impossible de contacter l’instance Mastodon.'
    ], 502);
}

if ($status === 401 || $status === 403) {
    unset($_SESSION['mastodon_auth']);

    respond([
        'ok' => true,
        'connected' => false
    ]);
}

if ($status < 200 || $status >= 300) {
    error_log(
        'Mastodon me HTTP error for ' .
        $instance . ': ' . $status
    );

    respond([
        'ok' => false,
        'connected' => true,
        'error' => 'L’instance Mastodon a refusé la vérification du compte.'
    ], 502);
}

$account = json_decode($response, true);

if (
    !is_array($account) ||
    empty($account['id']) ||
    empty($account['username'])
) {
    respond([
        'ok' => false,
        'connected' => true,
        'error' => 'La réponse de l’instance Mastodon est incomplète.'
    ], 502);
}

$username = (string)$account['username'];
$federatedHandle =
    '@' . $username . '@' . $instance;

respond([
    'ok' => true,
    'connected' => true,
    'account' => [
        'id' => (string)$account['id'],
        'username' => $username,
        'handle' => $federatedHandle,
        'display_name' => (string)($account['display_name'] ?? ''),
        'avatar' => (string)($account['avatar'] ?? ''),
        'url' => (string)($account['url'] ?? ''),
        'instance' => $instance
    ]
]);
