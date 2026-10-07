<?php
// Souverain.ovh

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$sourceRoot = __DIR__ . '/../';
$fixtureRoot = sys_get_temp_dir() . '/federated-backend-test-' . bin2hex(random_bytes(6));
$fixtureFiles = ['lib.php', 'securite.php', 'config-public.php', 'oauth-client-metadata.php'];

if (!mkdir($fixtureRoot, 0700)) {
    throw new RuntimeException('Cannot create the temporary test directory.');
}

register_shutdown_function(static function () use ($fixtureRoot, $fixtureFiles): void {
    foreach ([...$fixtureFiles, 'config.php'] as $file) {
        if (is_file($fixtureRoot . '/' . $file)) {
            unlink($fixtureRoot . '/' . $file);
        }
    }
    if (is_dir($fixtureRoot)) {
        rmdir($fixtureRoot);
    }
});

foreach ($fixtureFiles as $file) {
    if (!copy($sourceRoot . $file, $fixtureRoot . '/' . $file)) {
        throw new RuntimeException('Cannot copy the test source: ' . $file);
    }
}

/* Never load or edit the installation's config.php, even after customization. */
if (!copy($sourceRoot . 'config.example.php', $fixtureRoot . '/config.php')) {
    throw new RuntimeException('Cannot create the example test configuration.');
}

require $fixtureRoot . '/lib.php';

$checks = 0;

function verify(bool $condition, string $message): void
{
    global $checks;
    $checks++;

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/* No DNS, HTTP requests, tokens or publication: only pure validation and local streams. */
foreach ([
    '0.0.0.0', '10.0.0.1', '100.64.0.1', '100.127.255.255', '127.0.0.1',
    '169.254.169.254', '172.16.0.1', '192.0.0.1', '192.0.2.1', '192.168.0.1',
    '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1', '224.0.0.1',
    '239.255.255.255', '240.0.0.1', '255.255.255.255', '::', '::1', 'fc00::1',
    'fe80::1', 'ff02::1', '2001:db8::1', '2001::1', '2002:7f00:1::', '3fff::1',
    '64:ff9b::7f00:1', '::ffff:127.0.0.1', '::ffff:100.64.0.1',
    '::ffff:c0a8:1', 'fe80::1%eth0', 'not-an-ip'
] as $ip) {
    verify(!fc_public_ip($ip), 'Special-use IP accepted: ' . $ip);
}

foreach (['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.1', '2606:4700:4700::1111', '2001:4860:4860::8888', '::ffff:8.8.8.8'] as $ip) {
    verify(fc_public_ip($ip), 'Public IP rejected: ' . $ip);
}

foreach ([
    '//evil.example/article/', 'https://evil.example/article/', 'article/',
    '/\\evil.example/', '/%2f%2fevil.example/', '/%252f%252fevil.example/',
    '/%2525252f%2525252fevil.example/', "/article/\r\nLocation:x", '/article/%0d%0aX',
    '/article/%250d%250aX', '/article/../', '/article/%252e%252e/', '/article/ space'
] as $value) {
    verify(fc_return_to($value) === null, 'Unsafe return_to accepted: ' . $value);
}

verify(fc_return_to('/article/#discussion') === '/article/#discussion', 'Article return rejected.');
verify(fc_return_to('/mon%20article/#discussion') !== null, 'Encoded space in a path rejected.');
verify(fc_mastodon_return_url('/article/#discussion') === 'https://example.com/article/?mastodon=connected#discussion', 'Article OAuth return changed.');
verify(fc_mastodon_return_url('') === 'https://example.com/_commentaires/test.html?mastodon=connected', 'Legacy OAuth fallback changed.');
verify(fc_mastodon_return_url('/article/?x=1&mastodon=old#discussion') === 'https://example.com/article/?x=1&mastodon=connected#discussion', 'Query or anchor lost on OAuth return.');

verify(fc_normalize_article_url('https://example.com/article?utm_source=test') === 'https://example.com/article/', 'Tracking URL does not match canonical article.');
verify(fc_normalize_article_url('https://example.com/?p=42') !== fc_normalize_article_url('https://example.com/?p=43'), 'Distinct query permalinks collapsed.');
verify(fc_normalize_article_url('https://evil.example/article/') === null, 'External article accepted.');
verify(fc_normalize_article_url('https://user:pass@example.com/article/') === null, 'Credentials in article URL accepted.');

$rootUrl = 'https://mastodon.social/@votre_compte/12345';
$articleUrl = 'https://example.com/article/';
$root = [
    'id' => '12345', 'url' => $rootUrl, 'visibility' => 'public',
    'in_reply_to_id' => null, 'reblog' => null,
    'account' => [
        'username' => 'votre_compte', 'acct' => 'votre_compte',
        'url' => 'https://mastodon.social/@votre_compte'
    ],
    'content' => '<p><a href="https://example.com/article/?utm_source=mastodon">Article</a></p>'
];
verify(fc_mastodon_root_matches_article($root, $rootUrl, $articleUrl), 'Valid article root rejected.');
verify(!fc_mastodon_root_matches_article($root, $rootUrl, 'https://example.com/other/'), 'A root for another article was accepted.');
verify(!fc_mastodon_root_matches_article($root, 'https://mastodon.social/@votre_compte/999', $articleUrl), 'A different root URL was accepted.');
verify(fc_mastodon_status_id('https://mastodon.social/@votre_compte/statuses/01JTESTABC123') === '01JTESTABC123', 'An opaque compatible-server status ID was rejected.');
verify(fc_mastodon_status_id('https://mastodon.social/users/votre_compte/statuses/12345') === '12345', 'Standard ActivityPub status path was rejected.');

foreach ([
    ['account', ['username' => 'intruder', 'acct' => 'intruder', 'url' => 'https://mastodon.social/@intruder']],
    ['in_reply_to_id', '10'], ['reblog', ['id' => '99']], ['visibility', 'private'],
    ['content', '<p>No article link</p>']
] as [$field, $value]) {
    $wrong = $root;
    $wrong[$field] = $value;
    verify(!fc_mastodon_root_matches_article($wrong, $rootUrl, $articleUrl), 'Invalid root accepted: ' . $field);
}

$remoteRoot = $root;
$remoteRoot['id'] = '98765';
$remoteRoot['account']['acct'] = 'votre_compte@mastodon.social';
verify(fc_mastodon_root_matches_article($remoteRoot, $rootUrl, $articleUrl), 'Remote-local ID mapping broke the root validation.');

$stream = fopen('php://temp', 'w+b');
fwrite($stream, '12345678');
rewind($stream);
verify(fc_read_bounded_stream($stream, 8) === '12345678', 'Body exactly at limit rejected.');
rewind($stream);
$rejected = false;
try {
    fc_read_bounded_stream($stream, 7);
} catch (LengthException $error) {
    $rejected = $error->getCode() === 413;
}
fclose($stream);
verify($rejected, 'Oversized input stream accepted without Content-Length.');

$fixture = tempnam(sys_get_temp_dir(), 'federated-response-');
try {
    file_put_contents($fixture, '12345678');
    $curl = curl_init('file://' . $fixture);
    verify(fc_curl_exec_bounded($curl, 8) === '12345678', 'Local response at limit rejected.');
    unset($curl);
    $curl = curl_init('file://' . $fixture);
    verify(fc_curl_exec_bounded($curl, 7) === false, 'Oversized cURL response accepted.');
    unset($curl);
} finally {
    unlink($fixture);
}

verify(fc_utf8_length("Été 👩‍💻\n") === 8, 'UTF-8 length changed.');
verify(fc_utf8_prefix('Été 👩‍💻', 4) === 'Été ', 'UTF-8 prefix split a multibyte code point.');
verify(fc_utf8_length(str_repeat('🦊', 500)) === 500, 'Valid 500-code-point comment rejected.');
verify(fc_utf8_length(str_repeat('🦊', 501)) === 501, 'Overlong comment was not detected.');

$default = fc_config();
verify(fc_site_origin() === 'https://example.com', 'Distribution contains a personal site origin.');
verify(fc_mastodon_username() === 'votre_compte', 'Distribution contains a personal account.');
verify(fc_cookie_path() === '/_commentaires/', 'Default cookie path changed.');
verify(fc_https_origin('https://EXAMPLE.COM:443/', 'test') === 'https://example.com', 'Standard origin was not canonicalized.');

foreach ([
    ['site_url', 'http://example.com'], ['site_url', 'https://example.com/path'],
    ['site_url', 'https://user@example.com'], ['site_url', 'https://example.com?query'],
    ['site_url', 'https://example.com#fragment'], ['site_url', 'https://example.com:0'], ['base_path', '/'],
    ['base_path', '/one//two'], ['base_path', '/one/../two'], ['base_path', '/one/./two'],
    ['base_path', '/one/%2ftwo'], ['base_path', '//evil.example/a'],
    ['base_path', '/one/space here'], ['base_path', '/one\\two'],
    ['session_name', '12345'], ['session_name', 'name;bad'], ['session_name', str_repeat('a', 65)],
    ['demo_article_url', 'https://other.example/article/'],
    ['app_name', "Application\r\nName"]
] as [$field, $value]) {
    $bad = $default;
    $bad[$field] = $value;
    $rejected = false;
    try {
        fc_validate_config($bad);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    verify($rejected, 'Invalid config accepted: ' . $field . '=' . $value);
}

foreach ([
    ['mastodon', 'instance', 'https://social.example:8443'],
    ['mastodon', 'instance', 'http://social.example'],
    ['mastodon', 'instance', 'https://social.example/path'],
    ['mastodon', 'username', '@wrong@social.example'],
    ['bluesky', 'api', 'http://api.example'],
    ['bluesky', 'api', 'https://api.example/path'],
    ['bluesky', 'api', 'https://user@api.example'],
    ['bluesky', 'handle', '.invalid.example'],
    ['bluesky', 'handle', '-invalid.example']
] as [$group, $field, $value]) {
    $bad = $default;
    $bad[$group][$field] = $value;
    $rejected = false;
    try {
        fc_validate_config($bad);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    verify($rejected, 'Invalid nested config accepted: ' . $group . '.' . $field);
}

/* An isolated copy exercises the real cached configuration with another site and nested path. */
$custom = $default;
$custom['site_url'] = 'https://BLOG.EXAMPLE.NET:8443/';
$custom['base_path'] = '/tools/federated/~comments-v2/';
$custom['mastodon']['instance'] = 'https://SOCIAL.EXAMPLE.NET:443/';
$custom['mastodon']['username'] = 'writer_42';
$custom['session_name'] = 'my_comments_2';
$custom['demo_article_url'] = 'https://blog.example.net:8443/article/';
$temp = sys_get_temp_dir() . '/federated-config-test-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
$files = ['lib.php', 'securite.php', 'config-public.php', 'oauth-client-metadata.php'];

try {
    foreach ($files as $file) {
        copy($sourceRoot . $file, $temp . '/' . $file);
    }
    file_put_contents($temp . '/config.php', "<?php\nreturn " . var_export($custom, true) . ";\n");
    $code = <<<'PHP'
require getenv('FEDERATED_TEST_DIRECTORY') . '/lib.php';
ob_start(); require getenv('FEDERATED_TEST_DIRECTORY') . '/config-public.php'; $public = ob_get_clean();
ob_start(); require getenv('FEDERATED_TEST_DIRECTORY') . '/oauth-client-metadata.php'; $metadata = json_decode(ob_get_clean(), true);
$rootUrl = 'https://social.example.net/@writer_42/12345';
$root = ['id'=>'12345','url'=>$rootUrl,'visibility'=>'public','in_reply_to_id'=>null,'reblog'=>null,
'account'=>['username'=>'writer_42','acct'=>'writer_42','url'=>'https://social.example.net/@writer_42'],
'content'=>'<a href="https://blog.example.net:8443/article/">Article</a>'];
echo json_encode([
    'origin'=>fc_site_origin(), 'base'=>fc_url(), 'session'=>fc_session_name(), 'cookie'=>fc_cookie_path(),
    'return'=>fc_mastodon_return_url('/article/#discussion'), 'fallback'=>fc_mastodon_return_url(''),
    'article'=>fc_normalize_article_url('https://blog.example.net:8443/article?utm_source=test'),
    'otherPort'=>fc_normalize_article_url('https://blog.example.net/article/'),
    'rootMatches'=>fc_mastodon_root_matches_article($root,$rootUrl,'https://blog.example.net:8443/article/'),
    'clientId'=>$metadata['client_id'], 'redirect'=>$metadata['redirect_uris'][0],
    'public'=>$public
]);
PHP;
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['FEDERATED_TEST_DIRECTORY' => $temp]);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    verify($status === 0 && $errors === '', 'Custom configuration process failed: ' . $errors);
    $result = json_decode($output, true);
    verify(is_array($result), 'Custom configuration did not return JSON.');
    verify($result['origin'] === 'https://blog.example.net:8443', 'Custom site origin was lost.');
    verify($result['base'] === 'https://blog.example.net:8443/tools/federated/~comments-v2/', 'Custom nested base path was lost.');
    verify($result['session'] === 'my_comments_2', 'Custom session name was lost.');
    verify($result['cookie'] === '/tools/federated/~comments-v2/', 'Custom cookie path was lost.');
    verify($result['return'] === 'https://blog.example.net:8443/article/?mastodon=connected#discussion', 'Custom article return failed.');
    verify($result['fallback'] === 'https://blog.example.net:8443/tools/federated/~comments-v2/test.html?mastodon=connected', 'Custom demo return failed.');
    verify($result['article'] === 'https://blog.example.net:8443/article/', 'Custom article origin failed.');
    verify($result['otherPort'] === null, 'Different article origin port was accepted.');
    verify($result['rootMatches'], 'Custom Mastodon account was not used.');
    verify($result['clientId'] === 'https://blog.example.net:8443/tools/federated/~comments-v2/oauth-client-metadata.php', 'Custom OAuth client_id failed.');
    verify($result['redirect'] === 'https://blog.example.net:8443/tools/federated/~comments-v2/test.html', 'Custom ATProto redirect failed.');
    verify(str_contains($result['public'], 'window.FederatedCommentsConfig'), 'Original config global changed.');
    verify(str_contains($result['public'], 'https://blog.example.net:8443/tools/federated/~comments-v2/'), 'Public configuration lost custom base URL.');
} finally {
    foreach ([...$files, 'config.php'] as $file) {
        if (is_file($temp . '/' . $file)) {
            unlink($temp . '/' . $file);
        }
    }
    rmdir($temp);
}

echo $checks . " distribution PHP regression checks passed.\n";
