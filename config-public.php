<?php
// Souverain.ovh

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header(
    'Content-Type: application/javascript; charset=utf-8'
);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$config = fc_config();

$public = [
    'siteUrl' => fc_site_origin(),
    'basePath' => fc_base_path(),
    'baseUrl' => fc_url(),
    'demoArticleUrl' => fc_demo_article_url(),
    'maxPostsToSearch' =>
        fc_max_posts_to_search(),
    'bluesky' => [
        'handle' =>
            fc_bluesky_handle(),
        'api' =>
            fc_bluesky_api(),
        'pageSize' =>
            (int)$config['bluesky']['page_size']
    ]
];

echo
    'window.FederatedCommentsConfig = Object.freeze(' .
    json_encode(
        $public,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) .
    ');';
