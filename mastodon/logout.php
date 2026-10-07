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


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'ok' => false,
        'error' => 'Méthode POST requise.'
    ], 405);
}

requireSameOriginAjax();

unset(
    $_SESSION['mastodon_auth'],
    $_SESSION['mastodon_oauth']
);

session_regenerate_id(true);

respond([
    'ok' => true,
    'connected' => false
]);
