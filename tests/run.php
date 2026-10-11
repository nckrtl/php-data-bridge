<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
foreach (['Adapter', 'Recorder', 'Config', 'Store', 'Payload', 'Bridge', 'Adapters/Laravel', 'Adapters/Symfony'] as $file) {
    require __DIR__.'/../bridge/src/'.$file.'.php';
}
define('PHP_DATA_BRIDGE_VERSION', 'test');

use PhpDataBridge\{Adapter, Config, Payload, Recorder, Store};
use Symfony\Component\HttpFoundation\{Request, Response, Cookie, StreamedResponse, BinaryFileResponse};

function check(bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
}
final class TestAdapter implements Adapter {
    public static function register(): void {}
    public static function shouldCollect(): bool { return true; }
    public static function stages(): array { return ['start' => ['Start', '#fff'], 'send' => null]; }
    public static function contribute(array $data, object $request, object $response): array { return $data; }
}
$root = sys_get_temp_dir().'/bridge-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
Recorder::$basePath = $root;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
putenv('PHP_DATA_BRIDGE');
Config::load();
check(Config::allowsEnvironment('local'), 'installation enables eligible apps without app config');
file_put_contents($root.'/php-data-bridge.json', '{"collect":{"enabled":false}}');
putenv('PHP_DATA_BRIDGE=on');
Config::load();
check(! Config::allowsEnvironment('local'), 'explicit app opt-out wins over environment on');
file_put_contents($root.'/php-data-bridge.json', json_encode(['collect' => ['enabled' => true, 'ignore_paths' => ['/admin']], 'redact' => ['headers' => ['custom-secret']]]));
putenv('PHP_DATA_BRIDGE=off');
Config::load();
check(! Config::allowsEnvironment('local'), 'environment off wins over explicit app enable');
putenv('PHP_DATA_BRIDGE');
Config::load();
foreach (['prod', 'production', 'Production', 'staging', '', null] as $env) {
    check(! Config::allowsEnvironment($env), 'unknown/production environments must be off');
}
check(Config::allowsEnvironment('dev'), 'enabled dev app');
check(! PhpDataBridge\Adapters\Symfony::shouldCollect(), 'missing Symfony kernel must fail closed');
foreach (['192.168.6.50', '10.44.0.50', '2001:db8::1', '192.0.2.1'] as $client) {
    $_SERVER['REMOTE_ADDR'] = $client;
    check(Config::allowsEnvironment('dev'), 'remote development client must be allowed: '.$client);
    check(! Config::allowsEnvironment('production'), 'remote clients must still pass the environment check');
}
unset($_SERVER['REMOTE_ADDR']);
check(Config::allowsEnvironment('dev'), 'collection must not depend on the client address');
// Keep a non-loopback address for the payload and history tests below.
$_SERVER['REMOTE_ADDR'] = '192.168.6.50';
foreach (['/admin', '/admin/', '/a/../admin', '/%61dmin', '/a/%2e%2e/admin'] as $path) {
    check(Config::ignores($path), 'normalized ignore: '.$path);
}
check(in_array('authorization', Config::$redactHeaders) && in_array('custom-secret', Config::$redactHeaders), 'additive redaction');
$called = false;
$object = new class($called) implements Stringable {
    public function __construct(private bool &$called) {}
    public function __toString(): string { $this->called = true; throw new RuntimeException('side effect'); }
};
Recorder::plain(['object' => $object]);
check(! $called, 'normalization must not call __toString');
$bindings = Recorder::plain(['when' => new DateTimeImmutable('2026-01-01T00:00:00Z'), 'id' => 12]);
check($bindings['when'] === '2026-01-01T00:00:00+00:00' && $bindings['id'] === 12, 'binding types and names');
Recorder::$adapter = TestAdapter::class;
Recorder::$points = ['start' => ['time' => microtime(true) - 0.01, 'memory' => 1], 'send' => ['time' => microtime(true), 'memory' => 10]];
Recorder::$queries = [['sql' => 'select :secret', 'bindings' => ['secret' => 'binding-secret'], 'duration' => 2, 'file' => null, 'line' => null, 'connection' => 'test', 'driver' => 'sqlite']];
$request = Request::create('/private?token=query-secret', server: ['HTTP_AUTHORIZATION' => 'Bearer header-secret', 'HTTP_COOKIE' => 'session=cookie-secret', 'HTTP_REFERER' => 'https://example.test/?token=referer-secret', 'HTTP_X_TOOLBAR' => '1']);
$response = new Response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'public, max-age=3600', 'ETag' => 'old']);
$response->headers->setCookie(Cookie::create('session', 'response-cookie-secret')->withHttpOnly(true));
Payload::attach($request, $response);
$payload = json_decode(base64_decode($response->headers->get('x-toolbar')), true, flags: JSON_THROW_ON_ERROR);
$encoded = json_encode($payload);
foreach (['query-secret', 'header-secret', 'cookie-secret', 'binding-secret', 'referer-secret'] as $secret) {
    check(! str_contains($encoded, $secret), 'default payload leaked '.$secret);
}
check($payload['queries']['queries'][0]['bindings']['secret'] === '[redacted]', 'named redacted binding');
check($response->headers->hasCacheControlDirective('no-store') && $response->headers->hasCacheControlDirective('private') && ! $response->headers->has('ETag'), 'private cache policy');
check($payload['history_available'], 'history saved');
check(isset($payload['profiler']['stages'][0]['memory_delta']), 'accurate memory field');
$id = $payload['request_id'];
$file = Store::directory().'/'.$id.'.json';
check((fileperms($file) & 0777) === 0600, 'private file mode');
$_SERVER['REQUEST_URI'] = '/_toolbar/requests/'.$id;
$_SERVER['REQUEST_METHOD'] = 'GET';
foreach ([false, true] as $allowed) {
    $details = new Response();
    check(Store::serveDetails($details, $allowed), 'details route');
    check($details->getStatusCode() === ($allowed ? 200 : 404), 'details authorization');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$details = new Response(); Store::serveDetails($details, true);
check($details->getStatusCode() === 404, 'details POST rejected');
$_SERVER['REQUEST_METHOD'] = 'HEAD';
$details = new Response(); Store::serveDetails($details, true);
check($details->getStatusCode() === 200 && $details->getContent() === '', 'HEAD has no body');
$_SERVER['REQUEST_URI'] = '/';
$request = Request::create('/');
foreach ([['Content-Security-Policy' => "script-src 'nonce-example'"], []] as $headers) {
    $html = new Response('<html><body>hello</body></html>', headers: $headers);
    Payload::attach($request, $html);
    check($headers ? $html->headers->has('x-toolbar') && ! str_contains($html->getContent(), '<script>') : str_contains($html->getContent(), '<script>'), 'CSP fallback / HTML injection');
    if ($headers) { check($html->headers->get('Content-Security-Policy') === $headers['Content-Security-Policy'], 'CSP unchanged'); }
}
$meta = new Response('<html><meta http-equiv="Content-Security-Policy" content="script-src none"><body>hello</body></html>');
Payload::attach($request, $meta);
check($meta->headers->has('x-toolbar') && ! str_contains($meta->getContent(), '<script>'), 'meta CSP fallback');
foreach ([new StreamedResponse(static fn () => print('stream')), new BinaryFileResponse(__FILE__), new Response('file', headers: ['Content-Disposition' => 'attachment']), new Response('', 304)] as $skip) {
    Payload::attach($request, $skip);
    check(! $skip->headers->has('x-toolbar') && ! $skip->headers->has('x-toolbar-summary'), 'special response untouched');
}
check(! Store::save('aaaaaaaaaaaaaaaa', ['data' => str_repeat('x', Store::MAX_BYTES + 1)]), 'oversized store rejected');
$directory = Store::directory();
chmod($directory, 0777);
check(! Store::save('aaaaaaaaaaaaaaaa', []), 'insecure precreated directory rejected');
chmod($directory, 0700);
$renamed = $directory.'-real'; rename($directory, $renamed); symlink($renamed, $directory);
check(! Store::save('aaaaaaaaaaaaaaaa', []), 'directory symlink rejected');
unlink($directory); rename($renamed, $directory);
file_put_contents($root.'/php-data-bridge.json', '{invalid');
Config::load();
check(Config::$enabled && Config::$redactHeaders === Config::DEFAULT_HEADERS, 'invalid config resets installed defaults');
foreach (glob($directory.'/*') ?: [] as $path) { unlink($path); }
rmdir($directory);
unlink($root.'/php-data-bridge.json'); rmdir($root);
echo "PASS: PHP security, payload, and store boundaries\n";
