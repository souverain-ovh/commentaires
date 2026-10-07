<?php
// Souverain.ovh

declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

define('MASTODON_HOST', fc_mastodon_host());
define('MASTODON_BASE_URL', fc_mastodon_base_url());
define('MASTODON_USERNAME', fc_mastodon_username());
define('MAX_POSTS_TO_SEARCH', fc_max_posts_to_search());
const PAGE_SIZE = 40;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

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

    if ($contentLength > 4096) {
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

    foreach ($addresses as $ip) {
        if (!publicIp($ip)) {
            return null;
        }
    }

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

function mastodonGet(string $path, string $resolvedIp): array
{
    $url = MASTODON_BASE_URL . $path;

    $curl = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_RESOLVE => [
            curlResolveEntry(MASTODON_HOST, $resolvedIp)
        ],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => fc_user_agent(),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ];

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    curl_setopt_array($curl, $options);

    $body = fc_curl_exec_bounded($curl, 8_000_000);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    curl_close($curl);

    if ($body === false || $error !== '') {
        error_log('FederatedComments thread.php cURL: ' . $error);

        throw new RuntimeException(
            'Impossible de contacter Mastodon.'
        );
    }

    if ($status < 200 || $status >= 300) {
        error_log(
            'FederatedComments thread.php HTTP ' .
            $status . ' for ' . $path
        );

        throw new RuntimeException(
            'Mastodon a retourné une réponse inattendue.'
        );
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Réponse Mastodon invalide.'
        );
    }

    return $data;
}

function normalizeArticleUrl(string $url): ?string
{
    return fc_normalize_article_url($url);
}

function statusContainsArticle(array $status, string $articleUrl): bool
{
    return fc_status_contains_article($status, $articleUrl);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'ok' => false,
        'error' => 'Méthode POST requise.'
    ], 405);
}

requireSameOriginAjax();
requireJsonRequest();

try {
    $input = fc_read_json_request(4096);
} catch (RuntimeException | InvalidArgumentException | LengthException $error) {
    respond([
        'ok' => false,
        'error' => $error->getMessage()
    ], in_array($error->getCode(), [400, 413, 415], true) ? $error->getCode() : 400);
}

$articleUrl = normalizeArticleUrl(
    trim((string)($input['article_url'] ?? ''))
);

if ($articleUrl === null) {
    respond([
        'ok' => false,
        'error' => 'URL d’article invalide.'
    ], 400);
}

$resolvedIp = resolvePublicIp(MASTODON_HOST);

if ($resolvedIp === null) {
    respond([
        'ok' => false,
        'error' => 'Le serveur Mastodon ne peut pas être contacté de manière sûre.'
    ], 502);
}

try {
    $account = mastodonGet(
        '/api/v1/accounts/lookup?acct=' .
        rawurlencode(MASTODON_USERNAME),
        $resolvedIp
    );

    $accountId = (string)($account['id'] ?? '');

    if ($accountId === '') {
        throw new RuntimeException(
            'Compte Mastodon introuvable.'
        );
    }

    $maxId = null;
    $examined = 0;
    $root = null;

    while (
        $examined < MAX_POSTS_TO_SEARCH &&
        $root === null
    ) {
        $path =
            '/api/v1/accounts/' .
            rawurlencode($accountId) .
            '/statuses?limit=' .
            PAGE_SIZE .
            '&exclude_replies=true' .
            '&exclude_reblogs=true';

        if ($maxId !== null) {
            $path .= '&max_id=' . rawurlencode($maxId);
        }

        $statuses = mastodonGet(
            $path,
            $resolvedIp
        );

        if (!$statuses) {
            break;
        }

        foreach ($statuses as $status) {
            if (!is_array($status)) {
                continue;
            }

            $examined++;

            if (statusContainsArticle($status, $articleUrl)) {
                $root = $status;
                break;
            }

            if ($examined >= MAX_POSTS_TO_SEARCH) {
                break;
            }
        }

        if ($root !== null) {
            break;
        }

        $last = end($statuses);
        $lastId = is_array($last)
            ? (string)($last['id'] ?? '')
            : '';

        if ($lastId === '' || count($statuses) < PAGE_SIZE) {
            break;
        }

        $maxId = $lastId;
    }

    if ($root === null) {
        respond([
            'ok' => true,
            'found' => false,
            'examined' => $examined
        ]);
    }

    $rootId = (string)($root['id'] ?? '');

    if ($rootId === '') {
        throw new RuntimeException(
            'Publication Mastodon invalide.'
        );
    }

    $context = mastodonGet(
        '/api/v1/statuses/' .
        rawurlencode($rootId) .
        '/context',
        $resolvedIp
    );

    respond([
        'ok' => true,
        'found' => true,
        'examined' => $examined,
        'root' => $root,
        'context' => $context
    ]);

} catch (Throwable $error) {
    error_log(
        'FederatedComments thread.php: ' .
        $error->getMessage()
    );

    respond([
        'ok' => false,
        'error' => 'Impossible de charger la discussion Mastodon.'
    ], 502);
}
