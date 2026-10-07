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


function requireSameOriginAjax(): void
{
    $marker = (string)($_SERVER['HTTP_X_FEDERATED_COMMENTS_REQUEST'] ?? '');

    if (!hash_equals('1', $marker)) {
        respond([
            'ok' => false,
            'error' => 'Requête non autorisée.'
        ], 403);
    }

    $origin = rtrim(
        (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
        '/'
    );

    if ($origin !== '' && $origin !== fc_site_origin()) {
        respond([
            'ok' => false,
            'error' => 'Origine non autorisée.'
        ], 403);
    }

    $fetchSite = strtolower(
        (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')
    );

    if (
        $fetchSite !== '' &&
        !in_array($fetchSite, ['same-origin', 'none'], true)
    ) {
        respond([
            'ok' => false,
            'error' => 'Contexte de requête non autorisé.'
        ], 403);
    }
}


function requireJsonRequest(): void
{
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > 8192) {
        respond([
            'ok' => false,
            'error' => 'Requête trop volumineuse.'
        ], 413);
    }

    $contentType = strtolower(
        trim(
            explode(
                ';',
                (string)($_SERVER['CONTENT_TYPE'] ?? '')
            )[0]
        )
    );

    if ($contentType !== 'application/json') {
        respond([
            'ok' => false,
            'error' => 'Type de contenu non pris en charge.'
        ], 415);
    }
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


function enforceRegistrationRateLimit(): void
{
    $remoteAddress =
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $key = hash(
        'sha256',
        $remoteAddress . '|mastodon-register'
    );

    $file =
        sys_get_temp_dir() .
        '/federated-comments-' .
        $key .
        '.json';

    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        return;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return;
    }

    $now = time();
    $windowStart = $now - 3600;

    rewind($handle);
    $raw = stream_get_contents($handle);
    $events = json_decode($raw ?: '[]', true);

    if (!is_array($events)) {
        $events = [];
    }

    $events = array_values(
        array_filter(
            $events,
            static fn($timestamp): bool =>
                is_int($timestamp) &&
                $timestamp >= $windowStart
        )
    );

    if (count($events) >= 20) {
        flock($handle, LOCK_UN);
        fclose($handle);

        respond([
            'ok' => false,
            'error' => 'Trop de tentatives de connexion. Réessayez plus tard.'
        ], 429);
    }

    $events[] = $now;

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($events));
    fflush($handle);

    flock($handle, LOCK_UN);
    fclose($handle);
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'ok' => false,
        'error' => 'Méthode POST requise.'
    ], 405);
}

requireSameOriginAjax();
requireJsonRequest();
enforceRegistrationRateLimit();

$now = microtime(true);
$lastRegistration = (float)($_SESSION['mastodon_register_at'] ?? 0);

if ($lastRegistration > 0 && ($now - $lastRegistration) < 2.0) {
    respond([
        'ok' => false,
        'error' => 'Veuillez patienter avant une nouvelle tentative.'
    ], 429);
}

$_SESSION['mastodon_register_at'] = $now;

try {
    $input = fc_read_json_request(8192);
} catch (RuntimeException | InvalidArgumentException | LengthException $error) {
    respond([
        'ok' => false,
        'error' => $error->getMessage()
    ], in_array($error->getCode(), [400, 413, 415], true) ? $error->getCode() : 400);
}

$returnTo = $input['return_to'] ?? '';

if (!is_string($returnTo) || ($returnTo !== '' && fc_return_to($returnTo) === null)) {
    respond([
        'ok' => false,
        'error' => 'Adresse de retour invalide.'
    ], 400);
}

$instance = trim((string)($input['instance'] ?? ''));
$instance = preg_replace('#^https?://#i', '', $instance) ?? '';
$instance = ltrim($instance, '@');

if (str_contains($instance, '@')) {
    $parts = explode('@', $instance);
    $instance = (string)end($parts);
}

$instance = preg_replace('#/.*$#', '', $instance) ?? '';
$instance = strtolower(rtrim(trim($instance), '.'));

if (
    $instance === '' ||
    strlen($instance) > 253 ||
    filter_var($instance, FILTER_VALIDATE_IP) !== false ||
    filter_var(
        $instance,
        FILTER_VALIDATE_DOMAIN,
        FILTER_FLAG_HOSTNAME
    ) === false
) {
    respond([
        'ok' => false,
        'error' => 'Instance Mastodon invalide.'
    ], 400);
}

$resolvedIp = resolvePublicIp($instance);

if ($resolvedIp === null) {
    respond([
        'ok' => false,
        'error' => 'Cette instance ne peut pas être contactée de manière sûre.'
    ], 400);
}


$redirectUri =
    fc_url('/mastodon/callback.php');

$scopes =
    'read:accounts read:search write:statuses';

$registrationUrl =
    'https://' . $instance . '/api/v1/apps';

$payload = [
    'client_name' => fc_app_name(),
    'redirect_uris' => $redirectUri,
    'scopes' => $scopes,
    'website' => fc_site_origin() . '/'
];

$ch = curl_init($registrationUrl);

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
        'Mastodon register network error for ' .
        $instance . ': ' . $curlError
    );

    respond([
        'ok' => false,
        'error' => 'Impossible de contacter cette instance Mastodon.'
    ], 502);
}

$data = json_decode($response, true);

if (
    $status < 200 ||
    $status >= 300 ||
    !is_array($data)
) {
    error_log(
        'Mastodon register HTTP error for ' .
        $instance . ': ' . $status
    );

    respond([
        'ok' => false,
        'error' => 'L’instance Mastodon a refusé l’enregistrement de l’application.'
    ], 502);
}

if (
    empty($data['client_id']) ||
    empty($data['client_secret'])
) {
    respond([
        'ok' => false,
        'error' => 'Réponse OAuth Mastodon incomplète.'
    ], 502);
}

$state = bin2hex(random_bytes(32));

$_SESSION['mastodon_oauth'] = [
    'instance' => $instance,
    'client_id' => (string)$data['client_id'],
    'client_secret' => (string)$data['client_secret'],
    'redirect_uri' => $redirectUri,
    'scopes' => $scopes,
    'state' => $state,
    'created_at' => time(),
    'return_to' => $returnTo
];

$authorizeUrl =
    'https://' .
    $instance .
    '/oauth/authorize?' .
    http_build_query([
        'client_id' => $data['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => $scopes,
        'state' => $state
    ]);

respond([
    'ok' => true,
    'instance' => $instance,
    'authorize_url' => $authorizeUrl
]);
