<?php

// Sends one request to a PHP-FPM socket and prints the raw response, for testing without a
// web server. Usage: php fcgi.php <socket> <public dir> <uri> [Header: value ...]

[$_, $socket, $root, $uri] = $argv + [null, null, null, '/'];
$headers = array_slice($argv, 4);

$path = (string) parse_url($uri, PHP_URL_PATH);
$params = [
    'GATEWAY_INTERFACE' => 'FastCGI/1.0',
    'REQUEST_METHOD' => 'GET',
    'SCRIPT_FILENAME' => $root.'/index.php',
    'SCRIPT_NAME' => '/index.php',
    'DOCUMENT_ROOT' => $root,
    'REQUEST_URI' => $uri,
    'DOCUMENT_URI' => $path,
    'QUERY_STRING' => (string) parse_url($uri, PHP_URL_QUERY),
    'SERVER_NAME' => 'toolbar-ext-demo.test',
    'HTTP_HOST' => 'toolbar-ext-demo.test',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'REMOTE_ADDR' => '127.0.0.1',
    'SERVER_PORT' => '80',
];

foreach ($headers as $header) {
    [$name, $value] = array_map('trim', explode(':', $header, 2));

    // `env:NAME: value` sets a FastCGI param, which getenv() reads under FPM.
    if ($name === 'env') {
        [$name, $value] = array_map('trim', explode(':', $value, 2));
        $params[$name] = $value;

        continue;
    }

    $params['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}

function record(int $type, string $content): string
{
    return pack('CCnnCx', 1, $type, 1, strlen($content), 0).$content;
}

function length(int $length): string
{
    return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
}

$body = '';
foreach ($params as $name => $value) {
    $body .= length(strlen($name)).length(strlen($value)).$name.$value;
}

$stream = stream_socket_client('unix://'.$socket, $errno, $error, 5) ?: exit("connect: $error\n");
fwrite($stream, record(1, pack('nCxxxxx', 1, 0)).record(4, $body).record(4, '').record(5, ''));

$out = '';
while (! feof($stream)) {
    $head = fread($stream, 8);
    if (strlen($head) < 8) {
        break;
    }
    $h = unpack('Cversion/Ctype/nid/nlength/Cpadding', $head);
    $content = $h['length'] > 0 ? stream_get_contents($stream, $h['length']) : '';
    if ($h['padding'] > 0) {
        fread($stream, $h['padding']);
    }
    if ($h['type'] === 6) {
        $out .= $content;
    } elseif ($h['type'] === 7) {
        fwrite(STDERR, $content);
    } elseif ($h['type'] === 3) {
        break;
    }
}

echo $out;
