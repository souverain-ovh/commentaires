<?php
// Souverain.ovh

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header(
    'Content-Type: application/json; charset=utf-8'
);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$metadata = [
    'client_id' =>
        fc_url('/oauth-client-metadata.php'),
    'client_name' =>
        fc_app_name() . ' — ATProto',
    'client_uri' =>
        fc_site_origin() . '/',
    'redirect_uris' => [
        fc_url('/test.html')
    ],
    'grant_types' => [
        'authorization_code',
        'refresh_token'
    ],
    'response_types' => [
        'code'
    ],
    'scope' =>
        'atproto ' .
        'repo:app.bsky.feed.post?action=create ' .
        'rpc:app.bsky.actor.getProfile?' .
        'aud=did:web:api.bsky.app#bsky_appview',
    'application_type' =>
        'web',
    'token_endpoint_auth_method' =>
        'none',
    'dpop_bound_access_tokens' =>
        true
];

echo json_encode(
    $metadata,
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE |
    JSON_PRETTY_PRINT
);
