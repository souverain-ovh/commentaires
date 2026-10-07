<?php
// Souverain.ovh

declare(strict_types=1);

require_once __DIR__ . '/securite.php';

function fc_https_origin(string $url, string $label, bool $allowCustomPort = true): string
{
    $url = rtrim(trim($url), '/');
    $parts = parse_url($url);

    if (
        !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) ||
        strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) ||
        !in_array((string)($parts['path'] ?? ''), ['', '/'], true) ||
        isset($parts['query']) || isset($parts['fragment']) ||
        (isset($parts['port']) && (int)$parts['port'] < 1) ||
        (!$allowCustomPort && isset($parts['port']) && (int)$parts['port'] !== 443)
    ) {
        throw new RuntimeException(
            $label . ' doit être une origine HTTPS' .
            ($allowCustomPort ? '.' : ' sur le port 443.')
        );
    }

    $port = isset($parts['port']) && (int)$parts['port'] !== 443
        ? ':' . (int)$parts['port'] : '';

    return 'https://' . strtolower((string)$parts['host']) . $port;
}


function fc_validate_config(array $loaded): array
{
    if (!is_array($loaded['mastodon'] ?? null) || !is_array($loaded['bluesky'] ?? null)) {
        throw new RuntimeException('mastodon et bluesky doivent être des tableaux dans config.php.');
    }

    $siteUrl = fc_https_origin((string)($loaded['site_url'] ?? ''), 'site_url');
    $rawBasePath = trim((string)($loaded['base_path'] ?? '/_commentaires'));
    $basePath = '/' . trim($rawBasePath, '/');

    if (
        $basePath === '/' || str_contains($rawBasePath, '//') ||
        preg_match('#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#', $basePath) !== 1 ||
        preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $basePath) === 1
    ) {
        throw new RuntimeException(
            'base_path doit être un chemin de dossier sans espaces, encodage %, segments . ou .., ni double slash.'
        );
    }

    /* Mastodon network requests are DNS-pinned to HTTPS port 443. */
    $mastodonBase = fc_https_origin(
        (string)($loaded['mastodon']['instance'] ?? ''), 'mastodon.instance', false
    );
    $username = trim((string)($loaded['mastodon']['username'] ?? ''));

    if (preg_match('/^[A-Za-z0-9_]{1,100}$/', $username) !== 1) {
        throw new RuntimeException('mastodon.username doit être un nom de compte sans @ ni nom d’instance.');
    }

    $blueskyHandle = strtolower(trim((string)($loaded['bluesky']['handle'] ?? '')));

    if (
        $blueskyHandle === '' || strlen($blueskyHandle) > 253 ||
        !str_contains($blueskyHandle, '.') ||
        filter_var($blueskyHandle, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
    ) {
        throw new RuntimeException('bluesky.handle est invalide dans config.php.');
    }

    $blueskyApi = fc_https_origin(
        (string)($loaded['bluesky']['api'] ?? 'https://public.api.bsky.app'), 'bluesky.api'
    );
    $demoArticleUrl = trim((string)($loaded['demo_article_url'] ?? ''));

    if ($demoArticleUrl !== '') {
        $demoParts = parse_url($demoArticleUrl);

        if (
            !filter_var($demoArticleUrl, FILTER_VALIDATE_URL) || !is_array($demoParts) ||
            isset($demoParts['user']) || isset($demoParts['pass']) ||
            fc_https_origin(
                (string)($demoParts['scheme'] ?? '') . '://' . (string)($demoParts['host'] ?? '') .
                (isset($demoParts['port']) ? ':' . (int)$demoParts['port'] : ''),
                'demo_article_url'
            ) !== $siteUrl ||
            fc_return_to((string)($demoParts['path'] ?? '/')) === null
        ) {
            throw new RuntimeException('demo_article_url doit être une URL HTTPS d’article sur site_url.');
        }
    }

    $appName = trim((string)($loaded['app_name'] ?? 'Federated Comments')) ?: 'Federated Comments';
    $sessionName = (string)($loaded['session_name'] ?? 'federated_comments');

    if (strlen($appName) > 200 || preg_match('/[\x00-\x1f\x7f]/', $appName) === 1) {
        throw new RuntimeException('app_name est invalide dans config.php.');
    }

    if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $sessionName) !== 1) {
        throw new RuntimeException('session_name doit commencer par une lettre et contenir au plus 64 lettres, chiffres, _ ou -.');
    }

    $loaded['site_url'] = $siteUrl;
    $loaded['base_path'] = $basePath;
    $loaded['mastodon']['instance'] = $mastodonBase;
    $loaded['mastodon']['username'] = $username;
    $loaded['bluesky']['handle'] = $blueskyHandle;
    $loaded['bluesky']['api'] = $blueskyApi;
    $loaded['demo_article_url'] = $demoArticleUrl;
    $loaded['max_posts_to_search'] = max(1, min(10000, (int)($loaded['max_posts_to_search'] ?? 1000)));
    $loaded['bluesky']['page_size'] = max(1, min(100, (int)($loaded['bluesky']['page_size'] ?? 100)));
    $loaded['app_name'] = $appName;
    $loaded['session_name'] = $sessionName;

    return $loaded;
}


function fc_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $loaded = require __DIR__ . '/config.php';

    if (!is_array($loaded)) {
        throw new RuntimeException('Le fichier config.php doit retourner un tableau.');
    }

    $config = fc_validate_config($loaded);

    return $config;
}


function fc_site_origin(): string
{
    return (string)fc_config()['site_url'];
}


function fc_site_host(): string
{
    return strtolower(
        (string)parse_url(
            fc_site_origin(),
            PHP_URL_HOST
        )
    );
}


function fc_site_port(): ?int
{
    $port = parse_url(
        fc_site_origin(),
        PHP_URL_PORT
    );

    return $port === null
        ? null
        : (int)$port;
}


function fc_base_path(): string
{
    return (string)fc_config()['base_path'];
}


function fc_cookie_path(): string
{
    return rtrim(
        fc_base_path(),
        '/'
    ) . '/';
}


function fc_url(string $path = ''): string
{
    $base =
        fc_site_origin() .
        fc_base_path();

    if ($path === '') {
        return $base . '/';
    }

    return $base . '/' . ltrim(
        $path,
        '/'
    );
}


function fc_app_name(): string
{
    return (string)fc_config()['app_name'];
}


function fc_session_name(): string
{
    return (string)fc_config()['session_name'];
}


function fc_mastodon_base_url(): string
{
    return (string)fc_config()['mastodon']['instance'];
}


function fc_mastodon_host(): string
{
    return strtolower(
        (string)parse_url(
            fc_mastodon_base_url(),
            PHP_URL_HOST
        )
    );
}


function fc_mastodon_username(): string
{
    return (string)fc_config()['mastodon']['username'];
}


function fc_bluesky_handle(): string
{
    return (string)fc_config()['bluesky']['handle'];
}


function fc_bluesky_api(): string
{
    return (string)fc_config()['bluesky']['api'];
}


function fc_demo_article_url(): string
{
    return (string)fc_config()['demo_article_url'];
}


function fc_max_posts_to_search(): int
{
    return (int)fc_config()['max_posts_to_search'];
}


function fc_user_agent(
    string $component = 'FederatedComments'
): string
{
    return
        $component .
        '/0.2.1 (+' .
        fc_site_origin() .
        '/)';
}
