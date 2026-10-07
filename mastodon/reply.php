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


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'ok' => false,
        'error' => 'Méthode POST requise.'
    ], 405);
}

requireSameOriginAjax();
requireJsonRequest();

if (
    empty($_SESSION['mastodon_auth']) ||
    !is_array($_SESSION['mastodon_auth'])
) {
    respond([
        'ok' => false,
        'connected' => false,
        'error' => 'Aucun compte Mastodon connecté.'
    ], 401);
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
        'ok' => false,
        'connected' => false,
        'error' => 'Session Mastodon invalide. Veuillez vous reconnecter.'
    ], 401);
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

try {
    $input = fc_read_json_request(8192);
} catch (RuntimeException | InvalidArgumentException | LengthException $error) {
    respond([
        'ok' => false,
        'error' => $error->getMessage()
    ], in_array($error->getCode(), [400, 413, 415], true) ? $error->getCode() : 400);
}

$text = trim((string)($input['text'] ?? ''));
$statusUrl = trim((string)($input['status_url'] ?? ''));
$articleUrl = fc_normalize_article_url(trim((string)($input['article_url'] ?? '')));

if ($text === '') {
    respond([
        'ok' => false,
        'error' => 'La réponse est vide.'
    ], 400);
}

if (fc_utf8_length($text) > 500) {
    respond([
        'ok' => false,
        'error' => 'La réponse dépasse 500 caractères.'
    ], 400);
}

$originalRootId = fc_mastodon_status_id($statusUrl);

if ($originalRootId === null || $articleUrl === null) {
    respond([
        'ok' => false,
        'error' => 'URL de l’article ou du message Mastodon racine invalide.'
    ], 400);
}

/* Verify the original on the author's server, independently of the visitor's instance. */
$authorIp = resolvePublicIp(fc_mastodon_host());

if ($authorIp === null) {
    respond([
        'ok' => false,
        'error' => 'Impossible de vérifier la publication Mastodon de cet article.'
    ], 502);
}

$originalCurl = curl_init(
    fc_mastodon_base_url() . '/api/v1/statuses/' . rawurlencode($originalRootId)
);
curl_setopt_array($originalCurl, pinnedCurlOptions(fc_mastodon_host(), $authorIp) + [
    CURLOPT_HTTPHEADER => ['Accept: application/json']
]);
$originalResponse = fc_curl_exec_bounded($originalCurl, 2_000_000);
$originalStatus = (int)curl_getinfo($originalCurl, CURLINFO_HTTP_CODE);
unset($originalCurl);

if ($originalResponse === false || $originalStatus < 200 || $originalStatus >= 300) {
    respond([
        'ok' => false,
        'error' => 'La publication Mastodon de cet article est indisponible.'
    ], 502);
}

$originalRoot = json_decode($originalResponse, true);

if (
    !is_array($originalRoot) ||
    (string)($originalRoot['id'] ?? '') !== $originalRootId ||
    !fc_mastodon_root_matches_article($originalRoot, $statusUrl, $articleUrl)
) {
    respond([
        'ok' => false,
        'error' => 'Cette publication Mastodon ne correspond pas à cet article.'
    ], 400);
}

$searchUrl =
    'https://' .
    $instance .
    '/api/v2/search?' .
    http_build_query([
        'q' => $statusUrl,
        'type' => 'statuses',
        'resolve' => 'true',
        'limit' => 5
    ]);

$ch = curl_init($searchUrl);

$options = pinnedCurlOptions($instance, $resolvedIp) + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken
    ]
];

curl_setopt_array($ch, $options);

$searchResponse = fc_curl_exec_bounded($ch, 2_000_000);
$searchError = curl_error($ch);
$searchStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($searchResponse === false) {
    error_log(
        'Mastodon reply search network error for ' .
        $instance . ': ' . $searchError
    );

    respond([
        'ok' => false,
        'error' => 'Impossible de retrouver le message Mastodon racine.'
    ], 502);
}

$searchData = json_decode($searchResponse, true);

if (
    $searchStatus < 200 ||
    $searchStatus >= 300 ||
    !is_array($searchData)
) {
    error_log(
        'Mastodon reply search HTTP error for ' .
        $instance . ': ' . $searchStatus
    );

    respond([
        'ok' => false,
        'error' => 'Votre instance Mastodon a refusé la recherche du message racine.'
    ], 502);
}

$statuses = $searchData['statuses'] ?? [];

if (!is_array($statuses) || !$statuses) {
    respond([
        'ok' => false,
        'error' => 'Le message racine n’a pas pu être trouvé sur votre instance Mastodon.'
    ], 404);
}

$rootStatus = null;

foreach ($statuses as $status) {
    if (!is_array($status)) {
        continue;
    }

    $candidateUrl = (string)($status['url'] ?? '');

    if (
        $candidateUrl !== '' &&
        fc_mastodon_root_matches_article($status, $statusUrl, $articleUrl)
    ) {
        $rootStatus = $status;
        break;
    }
}

if (!$rootStatus) {
    respond([
        'ok' => false,
        'error' => 'Le message Mastodon racine exact n’a pas été retrouvé.'
    ], 404);
}

$localRootId = (string)($rootStatus['id'] ?? '');

if ($localRootId === '') {
    respond([
        'ok' => false,
        'error' => 'Impossible de déterminer l’identifiant local du message racine.'
    ], 502);
}

$publishUrl =
    'https://' . $instance . '/api/v1/statuses';

$payload = [
    'status' => $text,
    'in_reply_to_id' => $localRootId,
    'visibility' => 'public'
];

$ch = curl_init($publishUrl);

$options = pinnedCurlOptions($instance, $resolvedIp) + [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Bearer ' . $accessToken,
        'Idempotency-Key: ' . bin2hex(random_bytes(16))
    ]
];

curl_setopt_array($ch, $options);

$publishResponse = fc_curl_exec_bounded($ch, 2_000_000);
$publishError = curl_error($ch);
$publishStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($publishResponse === false) {
    error_log(
        'Mastodon reply publish network error for ' .
        $instance . ': ' . $publishError
    );

    respond([
        'ok' => false,
        'error' => 'Impossible de publier la réponse Mastodon.'
    ], 502);
}

$published = json_decode($publishResponse, true);

if (
    $publishStatus < 200 ||
    $publishStatus >= 300 ||
    !is_array($published)
) {
    error_log(
        'Mastodon reply publish HTTP error for ' .
        $instance . ': ' . $publishStatus
    );

    respond([
        'ok' => false,
        'error' => 'Votre instance Mastodon a refusé la publication.'
    ], 502);
}

respond([
    'ok' => true,
    'published' => true,
    'status' => [
        'id' => (string)($published['id'] ?? ''),
        'url' => (string)($published['url'] ?? ''),
        'created_at' => (string)($published['created_at'] ?? '')
    ]
]);
