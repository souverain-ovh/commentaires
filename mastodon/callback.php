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


function fail(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html>
'
        . '<html lang="fr">
'
        . '<head>
'
        . '<meta charset="utf-8">
'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">
'
        . '<title>Erreur OAuth Mastodon</title>
'
        . '</head>
'
        . '<body>
'
        . '<main>
'
        . '<h1>Erreur OAuth Mastodon</h1>
'
        . '<p>'
        . htmlspecialchars(
            $message,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        )
        . '</p>
'
        . '</main>
'
        . '</body>
'
        . '</html>';

    exit;
}

if (
    empty($_SESSION['mastodon_oauth']) ||
    !is_array($_SESSION['mastodon_oauth'])
) {
    fail('Aucune session OAuth Mastodon n’a été trouvée.');
}

$oauth = $_SESSION['mastodon_oauth'];

/* Les tentatives OAuth anciennes sont abandonnées. */
$createdAt = (int)($oauth['created_at'] ?? 0);

if ($createdAt <= 0 || (time() - $createdAt) > 900) {
    unset($_SESSION['mastodon_oauth']);
    fail('La tentative de connexion a expiré. Veuillez recommencer.');
}

if (!empty($_GET['error'])) {
    $error = (string)$_GET['error'];
    $description = (string)($_GET['error_description'] ?? '');

    fail(
        'Autorisation refusée : ' .
        $error .
        ($description ? ' — ' . $description : '')
    );
}

$receivedState = (string)($_GET['state'] ?? '');
$expectedState = (string)($oauth['state'] ?? '');

if (
    $receivedState === '' ||
    $expectedState === '' ||
    !hash_equals($expectedState, $receivedState)
) {
    unset($_SESSION['mastodon_oauth']);
    fail('Le paramètre OAuth state est invalide.');
}

$code = trim((string)($_GET['code'] ?? ''));

if ($code === '') {
    fail('Aucun code d’autorisation n’a été reçu.');
}

$instance = (string)($oauth['instance'] ?? '');
$clientId = (string)($oauth['client_id'] ?? '');
$clientSecret = (string)($oauth['client_secret'] ?? '');
$redirectUri = (string)($oauth['redirect_uri'] ?? '');
$scopes = (string)($oauth['scopes'] ?? '');

if (
    $instance === '' ||
    $clientId === '' ||
    $clientSecret === '' ||
    $redirectUri === '' ||
    $scopes === ''
) {
    unset($_SESSION['mastodon_oauth']);
    fail('La session OAuth Mastodon est incomplète.');
}

$resolvedIp = resolvePublicIp($instance);

if ($resolvedIp === null) {
    unset($_SESSION['mastodon_oauth']);
    fail('Cette instance Mastodon ne peut pas être contactée de manière sûre.', 502);
}

$tokenUrl =
    'https://' . $instance . '/oauth/token';

$payload = [
    'grant_type' => 'authorization_code',
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'scope' => $scopes
];

$ch = curl_init($tokenUrl);

$options = pinnedCurlOptions($instance, $resolvedIp) + [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded'
    ]
];

curl_setopt_array($ch, $options);

$response = fc_curl_exec_bounded($ch, 1_000_000);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false) {
    error_log(
        'Mastodon callback token network error for ' .
        $instance . ': ' . $curlError
    );

    fail('Impossible de contacter l’instance Mastodon.', 502);
}

$data = json_decode($response, true);

if (
    $status < 200 ||
    $status >= 300 ||
    !is_array($data)
) {
    error_log(
        'Mastodon callback token HTTP error for ' .
        $instance . ': ' . $status
    );

    fail('L’instance Mastodon a refusé l’échange OAuth.', 502);
}

$accessToken = (string)($data['access_token'] ?? '');

if ($accessToken === '') {
    fail('Aucun jeton d’accès Mastodon n’a été reçu.', 502);
}

/* Régénération avant de marquer la session comme authentifiée. */
session_regenerate_id(true);

$_SESSION['mastodon_auth'] = [
    'instance' => $instance,
    'access_token' => $accessToken,
    'scope' => (string)($data['scope'] ?? $scopes),
    'created_at' => time()
];

unset($_SESSION['mastodon_oauth']);

header(
    'Location: ' . fc_mastodon_return_url((string)($oauth['return_to'] ?? '')),
    true,
    302
);

exit;
