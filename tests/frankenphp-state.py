#!/usr/bin/env python3
"""Verify the bridge under FrankenPHP: on in classic mode with fresh state per request, off in worker mode."""
import json
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

repo = Path(__file__).resolve().parents[1]
frankenphp = sys.argv[1] if len(sys.argv) > 1 else 'frankenphp'


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def serve(root, arguments):
    port = free_port()
    environment = {'PATH': '/usr/bin:/bin', 'HOME': str(root), 'XDG_CONFIG_HOME': str(root), 'XDG_DATA_HOME': str(root), 'PHP_INI_SCAN_DIR': str(root / 'conf.d')}
    process = subprocess.Popen([frankenphp, 'php-server', '--listen', f'127.0.0.1:{port}', '--root', str(root / 'public'), *arguments], env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    def request(path):
        with urllib.request.urlopen(f'http://127.0.0.1:{port}{path}', timeout=5) as response:
            return json.loads(response.read())

    for _ in range(100):
        if process.poll() is not None:
            raise RuntimeError(process.communicate())
        try:
            request('/ready')
            return process, request
        except OSError:
            time.sleep(.05)
    process.terminate()
    raise RuntimeError(process.communicate(timeout=10))


with tempfile.TemporaryDirectory(prefix='bridge-frankenphp-', dir='/tmp') as temporary:
    root = Path(temporary)
    (root / 'public').mkdir()
    (root / 'conf.d').mkdir()
    (root / 'conf.d' / 'bridge.ini').write_text(f'auto_prepend_file = {repo}/bridge/prepend.php\n')
    report = '''json_encode([
    'sapi' => PHP_SAPI,
    'hook' => function_exists('OpenTelemetry\\\\Instrumentation\\\\hook'),
    'bridge' => defined('PHP_DATA_BRIDGE_VERSION'),
    'points' => class_exists('PhpDataBridge\\\\Recorder', false) ? count(PhpDataBridge\\Recorder::$points) : null,
    'seen' => RequestState::$seen,
])'''
    state = "class_exists('RequestState', false) || eval('class RequestState { public static array $seen = []; }');\nRequestState::$seen[] = $_SERVER['REQUEST_URI'];\n"
    (root / 'public' / 'index.php').write_text(f'<?php\n{state}echo {report};\n')
    (root / 'public' / 'worker.php').write_text(f'<?php\nwhile (frankenphp_handle_request(static function (): void {{\n{state}echo {report};\n}})) {{}}\n')

    process, request = serve(root, [])
    try:
        first, second = request('/first'), request('/second')
    finally:
        process.terminate(); process.communicate(timeout=10)
    assert first['sapi'] == 'frankenphp', first
    assert first['seen'] == ['/first'] and second['seen'] == ['/second'], (first, second)
    if first['hook']:
        assert first['bridge'] and second['bridge'], (first, second)
        assert first['points'] == second['points'], (first, second)
        print('PASS: classic mode loads the bridge and resets its state for each request')
    else:
        assert not first['bridge'], first
        print('PASS: classic mode resets state for each request (no OpenTelemetry extension, so the bridge stayed off)')

    process, request = serve(root, ['--worker', f"{root / 'public' / 'worker.php'},1"])
    try:
        first, second = request('/worker.php?first'), request('/worker.php?second')
    finally:
        process.terminate(); process.communicate(timeout=10)
    assert second['seen'][-2:] == ['/worker.php?first', '/worker.php?second'], second
    assert not first['bridge'] and not second['bridge'], (first, second)
    print('PASS: worker mode keeps state between requests, so the bridge stays off')
