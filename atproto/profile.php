<?php
// Souverain.ovh

declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/*
 * Lecture publique d'un profil ATProto directement depuis son PDS.
 *
 * POST JSON {"did":"did:..."} :
 *   - résout le DID ;
 *   - découvre le PDS ;
 *   - lit app.bsky.actor.profile/self ;
 *   - renvoie handle, displayName et une URL d'avatar same-origin.
 *
 * GET ?did=...&cid=... :
 *   - proxifie uniquement le blob d'avatar correspondant via le PDS.
 *
 * Toutes les connexions sortantes sont limitées à HTTPS, leurs DNS sont
 * validés contre les adresses privées/réservées et cURL est verrouillé
 * sur l'adresse IP effectivement validée.
 */

const MAX_JSON_BYTES = 1_000_000;
const MAX_IMAGE_BYTES = 2_000_000;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');


function respondJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


function failAsset(int $status = 404): never
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

    $addresses = array_values(
        array_unique($addresses)
    );

    if (!$addresses) {
        return null;
    }

    /*
     * Refus complet si le DNS expose au moins une IP privée/réservée.
     * Cela empêche le serveur d'être utilisé comme relais vers un LAN.
     */
    foreach ($addresses as $ip) {
        if (!publicIp($ip)) {
            return null;
        }
    }

    foreach ($addresses as $ip) {
        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4
            )
        ) {
            return $ip;
        }
    }

    return $addresses[0];
}


function curlResolveEntry(
    string $host,
    string $ip
): string
{
    $address = str_contains($ip, ':')
        ? '[' . $ip . ']'
        : $ip;

    return $host . ':443:' . $address;
}


function parseSafeHttpsUrl(string $url): ?array
{
    $parts = @parse_url($url);

    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower(
        (string)($parts['scheme'] ?? '')
    );

    $host = strtolower(
        (string)($parts['host'] ?? '')
    );

    $port = isset($parts['port'])
        ? (int)$parts['port']
        : 443;

    if (
        $scheme !== 'https' ||
        $host === '' ||
        $port !== 443 ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        filter_var($host, FILTER_VALIDATE_IP)
    ) {
        return null;
    }

    $ip = resolvePublicIp($host);

    if ($ip === null) {
        return null;
    }

    return [
        'url' => $url,
        'host' => $host,
        'ip' => $ip
    ];
}


function fetchHttps(
    string $url,
    int $maxBytes,
    array $acceptedStatus = [200]
): array
{
    $safe = parseSafeHttpsUrl($url);

    if ($safe === null) {
        throw new RuntimeException(
            'Destination distante non autorisée.'
        );
    }

    $body = '';
    $tooLarge = false;

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException(
            'Initialisation réseau impossible.'
        );
    }

    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT =>
            fc_user_agent('FederatedCommentsATProto'),
        CURLOPT_RESOLVE => [
            curlResolveEntry(
                $safe['host'],
                $safe['ip']
            )
        ],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, application/did+json, image/*'
        ],
        CURLOPT_WRITEFUNCTION =>
            static function (
                $ch,
                string $chunk
            ) use (
                &$body,
                &$tooLarge,
                $maxBytes
            ): int {
                if (
                    strlen($body) +
                    strlen($chunk) >
                    $maxBytes
                ) {
                    $tooLarge = true;
                    return 0;
                }

                $body .= $chunk;
                return strlen($chunk);
            }
    ];

    if (
        defined('CURLOPT_PROTOCOLS') &&
        defined('CURLPROTO_HTTPS')
    ) {
        $options[CURLOPT_PROTOCOLS] =
            CURLPROTO_HTTPS;
    }

    curl_setopt_array(
        $curl,
        $options
    );

    $ok = curl_exec($curl);

    $status = (int)curl_getinfo(
        $curl,
        CURLINFO_RESPONSE_CODE
    );

    $contentType = strtolower(
        trim(
            (string)curl_getinfo(
                $curl,
                CURLINFO_CONTENT_TYPE
            )
        )
    );

    $error = curl_error($curl);

    curl_close($curl);

    if (
        $ok === false ||
        $tooLarge ||
        $error !== ''
    ) {
        if ($error !== '') {
            error_log(
                'FederatedComments ATProto profile cURL: ' .
                $error
            );
        }

        throw new RuntimeException(
            'Service ATProto inaccessible.'
        );
    }

    if (
        !in_array(
            $status,
            $acceptedStatus,
            true
        )
    ) {
        throw new RuntimeException(
            'Réponse ATProto inattendue.'
        );
    }

    return [
        'body' => $body,
        'content_type' => $contentType,
        'status' => $status
    ];
}


function fetchJson(string $url): array
{
    $response = fetchHttps(
        $url,
        MAX_JSON_BYTES
    );

    $data = json_decode(
        $response['body'],
        true
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            'Réponse JSON ATProto invalide.'
        );
    }

    return $data;
}


function validateDid(string $did): bool
{
    if (
        strlen($did) < 8 ||
        strlen($did) > 512
    ) {
        return false;
    }

    if (
        preg_match(
            '/^did:plc:[a-z2-7]{24}$/',
            $did
        ) === 1
    ) {
        return true;
    }

    if (
        preg_match(
            '/^did:web:[A-Za-z0-9.%_-]+(?::[A-Za-z0-9._~%-]+)*$/',
            $did
        ) === 1
    ) {
        return true;
    }

    return false;
}


function didWebUrl(string $did): ?string
{
    $methodId = substr(
        $did,
        strlen('did:web:')
    );

    $parts = explode(
        ':',
        $methodId
    );

    if (!$parts) {
        return null;
    }

    $host = rawurldecode(
        array_shift($parts)
    );

    if (
        $host === '' ||
        str_contains($host, ':') ||
        str_contains($host, '/') ||
        !preg_match(
            '/^[A-Za-z0-9.-]+$/',
            $host
        )
    ) {
        return null;
    }

    $host = strtolower($host);

    if (!$parts) {
        return 'https://' .
            $host .
            '/.well-known/did.json';
    }

    $safeSegments = [];

    foreach ($parts as $part) {
        $segment = rawurldecode($part);

        if (
            $segment === '' ||
            $segment === '.' ||
            $segment === '..' ||
            str_contains($segment, '/') ||
            str_contains($segment, '\\')
        ) {
            return null;
        }

        $safeSegments[] =
            rawurlencode($segment);
    }

    return 'https://' .
        $host .
        '/' .
        implode('/', $safeSegments) .
        '/did.json';
}


function resolveDidDocument(string $did): array
{
    if (!validateDid($did)) {
        throw new InvalidArgumentException(
            'DID invalide.'
        );
    }

    if (
        str_starts_with(
            $did,
            'did:plc:'
        )
    ) {
        $url =
            'https://plc.directory/' .
            $did;
    } else {
        $url = didWebUrl($did);

        if ($url === null) {
            throw new InvalidArgumentException(
                'DID web invalide.'
            );
        }
    }

    $document = fetchJson($url);

    if (
        isset($document['id']) &&
        is_string($document['id']) &&
        $document['id'] !== $did
    ) {
        throw new RuntimeException(
            'Document DID incohérent.'
        );
    }

    return $document;
}


function discoverPds(
    array $document
): string
{
    $services = $document['service'] ?? [];

    if (!is_array($services)) {
        throw new RuntimeException(
            'PDS ATProto introuvable.'
        );
    }

    foreach ($services as $service) {
        if (!is_array($service)) {
            continue;
        }

        $type = $service['type'] ?? null;
        $matches = false;

        if (
            is_string($type) &&
            $type === 'AtprotoPersonalDataServer'
        ) {
            $matches = true;
        }

        if (
            is_array($type) &&
            in_array(
                'AtprotoPersonalDataServer',
                $type,
                true
            )
        ) {
            $matches = true;
        }

        if (!$matches) {
            continue;
        }

        $endpoint =
            $service['serviceEndpoint'] ?? null;

        if (
            !is_string($endpoint) ||
            $endpoint === ''
        ) {
            continue;
        }

        $endpoint =
            rtrim($endpoint, '/');

        if (
            parseSafeHttpsUrl($endpoint) ===
            null
        ) {
            throw new RuntimeException(
                'PDS ATProto non autorisé.'
            );
        }

        return $endpoint;
    }

    throw new RuntimeException(
        'PDS ATProto introuvable.'
    );
}


function discoverHandle(
    array $document
): string
{
    $aliases =
        $document['alsoKnownAs'] ?? [];

    if (!is_array($aliases)) {
        return '';
    }

    foreach ($aliases as $alias) {
        if (!is_string($alias)) {
            continue;
        }

        if (
            str_starts_with(
                $alias,
                'at://'
            )
        ) {
            $handle = substr(
                $alias,
                strlen('at://')
            );

            if (
                $handle !== '' &&
                strlen($handle) <= 253 &&
                preg_match(
                    '/^[A-Za-z0-9.-]+$/',
                    $handle
                ) === 1
            ) {
                return strtolower(
                    $handle
                );
            }
        }
    }

    return '';
}


function extractAvatarCid(
    mixed $avatar
): string
{
    if (!is_array($avatar)) {
        return '';
    }

    $ref = $avatar['ref'] ?? null;

    if (
        is_array($ref) &&
        isset($ref['$link']) &&
        is_string($ref['$link'])
    ) {
        $cid = $ref['$link'];
    } elseif (
        isset($avatar['cid']) &&
        is_string($avatar['cid'])
    ) {
        $cid = $avatar['cid'];
    } else {
        return '';
    }

    if (
        preg_match(
            '/^[a-z0-9]{20,200}$/',
            $cid
        ) !== 1
    ) {
        return '';
    }

    return $cid;
}


function requireSameOriginAjax(): void
{
    $marker =
        (string)(
            $_SERVER[
                'HTTP_X_FEDERATED_COMMENTS_REQUEST'
            ] ?? ''
        );

    if (!hash_equals('1', $marker)) {
        respondJson([
            'ok' => false,
            'error' => 'Requête non autorisée.'
        ], 403);
    }

    $origin = rtrim(
        (string)(
            $_SERVER['HTTP_ORIGIN'] ?? ''
        ),
        '/'
    );

    if (
        $origin !== '' &&
        $origin !== fc_site_origin()
    ) {
        respondJson([
            'ok' => false,
            'error' => 'Origine non autorisée.'
        ], 403);
    }

    $fetchSite = strtolower(
        (string)(
            $_SERVER[
                'HTTP_SEC_FETCH_SITE'
            ] ?? ''
        )
    );

    if (
        $fetchSite !== '' &&
        !in_array(
            $fetchSite,
            ['same-origin', 'none'],
            true
        )
    ) {
        respondJson([
            'ok' => false,
            'error' => 'Contexte de requête non autorisé.'
        ], 403);
    }
}


function sameOriginAssetRequest(): bool
{
    $fetchSite = strtolower(
        (string)(
            $_SERVER[
                'HTTP_SEC_FETCH_SITE'
            ] ?? ''
        )
    );

    if (
        $fetchSite !== '' &&
        !in_array(
            $fetchSite,
            ['same-origin', 'none'],
            true
        )
    ) {
        return false;
    }

    $referer =
        (string)(
            $_SERVER[
                'HTTP_REFERER'
            ] ?? ''
        );

    if (
        $referer !== '' &&
        !str_starts_with(
            $referer,
            fc_site_origin() . '/'
        )
    ) {
        return false;
    }

    return true;
}


/* ==========================================================
   MODE AVATAR
   ========================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (!sameOriginAssetRequest()) {
        failAsset(403);
    }

    $did = trim(
        (string)($_GET['did'] ?? '')
    );

    $cid = trim(
        (string)($_GET['cid'] ?? '')
    );

    if (
        !validateDid($did) ||
        preg_match(
            '/^[a-z0-9]{20,200}$/',
            $cid
        ) !== 1
    ) {
        failAsset(400);
    }

    try {
        $document =
            resolveDidDocument($did);

        $pds =
            discoverPds($document);

        $url =
            $pds .
            '/xrpc/com.atproto.sync.getBlob' .
            '?did=' .
            rawurlencode($did) .
            '&cid=' .
            rawurlencode($cid);

        $response =
            fetchHttps(
                $url,
                MAX_IMAGE_BYTES
            );

        $contentType = trim(
            explode(
                ';',
                $response['content_type'],
                2
            )[0]
        );

        $allowed = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif'
        ];

        if (
            !in_array(
                $contentType,
                $allowed,
                true
            )
        ) {
            failAsset(415);
        }

        if ($response['body'] === '') {
            failAsset(502);
        }

        header(
            'Content-Type: ' .
            $contentType
        );

        header(
            'Content-Length: ' .
            strlen($response['body'])
        );

        header(
            'Cache-Control: public, max-age=86400'
        );

        header(
            'Content-Security-Policy: default-src \'none\''
        );

        header(
            'Cross-Origin-Resource-Policy: same-origin'
        );

        echo $response['body'];
        exit;

    } catch (Throwable $error) {
        error_log(
            'FederatedComments ATProto avatar: ' .
            $error->getMessage()
        );

        failAsset(502);
    }
}


/* ==========================================================
   MODE PROFIL JSON
   ========================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson([
        'ok' => false,
        'error' => 'Méthode non autorisée.'
    ], 405);
}

requireSameOriginAjax();

$contentLength =
    (int)(
        $_SERVER['CONTENT_LENGTH'] ?? 0
    );

if ($contentLength > 4096) {
    respondJson([
        'ok' => false,
        'error' => 'Requête trop volumineuse.'
    ], 413);
}

$contentType = strtolower(
    trim(
        explode(
            ';',
            (string)(
                $_SERVER[
                    'CONTENT_TYPE'
                ] ?? ''
            )
        )[0]
    )
);

if (
    $contentType !==
    'application/json'
) {
    respondJson([
        'ok' => false,
        'error' => 'Type de contenu non pris en charge.'
    ], 415);
}

try {
    $input = fc_read_json_request(4096);
} catch (RuntimeException | InvalidArgumentException | LengthException $error) {
    respondJson([
        'ok' => false,
        'error' => $error->getMessage()
    ], in_array($error->getCode(), [400, 413, 415], true) ? $error->getCode() : 400);
}

$did = trim(
    (string)($input['did'] ?? '')
);

if (!validateDid($did)) {
    respondJson([
        'ok' => false,
        'error' => 'DID ATProto invalide.'
    ], 400);
}

try {
    $document =
        resolveDidDocument($did);

    $pds =
        discoverPds($document);

    $handle =
        discoverHandle($document);

    $displayName = '';
    $avatarCid = '';

    /*
     * Un record de profil peut être absent : le DID et le handle
     * restent alors utilisables sans bloquer la session OAuth.
     */
    try {
        $recordUrl =
            $pds .
            '/xrpc/com.atproto.repo.getRecord' .
            '?repo=' .
            rawurlencode($did) .
            '&collection=' .
            rawurlencode(
                'app.bsky.actor.profile'
            ) .
            '&rkey=self';

        $record =
            fetchJson($recordUrl);

        $value =
            $record['value'] ?? [];

        if (is_array($value)) {
            if (
                isset($value['displayName']) &&
                is_string(
                    $value['displayName']
                )
            ) {
                $displayName =
                    trim(
                        $value[
                            'displayName'
                        ]
                    );

                if (
                    fc_utf8_length(
                        $displayName
                    ) > 128
                ) {
                    $displayName =
                        fc_utf8_prefix(
                            $displayName,
                            128
                        );
                }
            }

            $avatarCid =
                extractAvatarCid(
                    $value['avatar'] ??
                    null
                );
        }
    } catch (Throwable $profileError) {
        error_log(
            'FederatedComments ATProto record profile: ' .
            $profileError->getMessage()
        );
    }

    $avatar = '';

    if ($avatarCid !== '') {
        $avatar =
            fc_url('/atproto/profile.php') .
            '?did=' .
            rawurlencode($did) .
            '&cid=' .
            rawurlencode(
                $avatarCid
            );
    }

    respondJson([
        'ok' => true,
        'profile' => [
            'did' => $did,
            'handle' => $handle,
            'displayName' => $displayName,
            'avatar' => $avatar
        ]
    ]);

} catch (Throwable $error) {
    error_log(
        'FederatedComments ATProto profile: ' .
        $error->getMessage()
    );

    respondJson([
        'ok' => false,
        'error' =>
            'Impossible de récupérer le profil ATProto.'
    ], 502);
}
