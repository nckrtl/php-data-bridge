#!/usr/bin/env python3
"""Real HTTP requests in one PHP built-in-server worker, with the OpenTelemetry extension."""
import base64
import http.client
import json
import os
from pathlib import Path
import socket
import shutil
import subprocess
import tempfile
import time

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='bridge-http-') as temporary:
    root = Path(temporary)
    (root / 'public').mkdir()
    (root / 'public/index.php').write_text((repo / 'tests/fixtures/index.php').read_text())
    (root / 'composer.json').write_text('{}')
    # Simulate the remote peer supplied by Orbit's web server before bridge startup.
    # This override exists only in the temporary test server, never in the bridge.
    (root / 'prepend.php').write_text('<?php\n'
        'if (isset($_SERVER["HTTP_X_TEST_REMOTE_ADDR"])) { $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_X_TEST_REMOTE_ADDR"]; }\n'
        'require ' + json.dumps(str(repo / 'bridge/prepend.php')) + ';\n')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, BRIDGE_TEST_ROOT=str(repo), BRIDGE_TEST_APP=str(root))
    env.pop('PHP_CLI_SERVER_WORKERS', None)
    env.pop('PHP_DATA_BRIDGE', None)
    with (root / 'server.log').open('w+') as log:
        process = subprocess.Popen(['php', '-d', 'auto_prepend_file=' + str(root / 'prepend.php'), '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')], env=env, stdout=log, stderr=log)
        stores = set()
        def request(path, headers=None, method='GET'):
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
            connection.request(method, path, headers=headers or {})
            response = connection.getresponse()
            result = response.status, dict(response.getheaders()), response.read().decode()
            connection.close()
            if 'X-Test-Store' in result[1]: stores.add(result[1]['X-Test-Store'])
            return result[0], {k.lower(): v for k, v in result[1].items()}, result[2]
        try:
            for _ in range(100):
                try:
                    first = json.loads(request('/state')[2]); break
                except ConnectionRefusedError: time.sleep(.02)
            second = json.loads(request('/state')[2])
            assert first == second and first['count'] == 1, (first, second)
            for framework in ['symfony', 'laravel']:
                headers = {'X-Test-Framework': framework, 'X-Test-Remote-Addr': '192.168.1.180', 'X-Toolbar': '1', 'Authorization': 'Bearer private-auth', 'Cookie': 'session=private-cookie'}
                for iteration in range(2):
                    status, response, body = request('/?token=private-query', headers)
                    assert status == 200 and 'x-toolbar' in response, (framework, status, response, body)
                    data = json.loads(base64.b64decode(response['x-toolbar']))
                    assert data['request']['ip_address'] == '192.168.1.180', 'non-loopback caller must receive telemetry'
                    assert len(data['queries']['queries']) == 1, data
                    for secret in ['private-auth', 'private-cookie', 'private-query', 'private-binding', 'private-prop']:
                        assert secret not in json.dumps(data), secret
                    assert data['queries']['queries'][0]['bindings'] == ({'name': '[redacted]'} if framework == 'laravel' else ['[redacted]']), (framework, data['queries']['queries'][0])
                    assert int(response['x-worker-pid']) == first['pid']
                id = data['request_id']
                status, response, body = request('/_toolbar/requests/' + id, headers)
                assert status == 200 and json.loads(body)['raw']['request_id'] == id, (status, body)
                for environment in ['production', 'prod', 'staging', 'Production', 'missing']:
                    denied = dict(headers, **{'X-Test-Env': environment})
                    status, response, body = request('/', denied)
                    assert 'x-toolbar' not in response and response['x-recorded-queries'] == '0', (framework, environment, response, body)
                    assert request('/_toolbar/requests/' + id, denied)[0] == 404
                request('/abort', headers)
                assert 'x-toolbar' in request('/', headers)[1], 'request after exit must still profile'
                status, response, body = request('/many', headers)
                assert response['x-recorded-queries'] == '500', response
                summary = json.loads(base64.b64decode(response['x-toolbar-summary']))
                raw = json.loads(request('/_toolbar/requests/' + summary['request_id'], headers)[2])['raw']
                assert raw['queries']['truncated_count'] == 3, raw['queries']['truncated_count']
                if framework == 'laravel':
                    existing = dict(headers, **{'X-Test-Toolbar': '1'})
                    status, response, body = request('/_toolbar/requests/' + id, existing)
                    assert status == 200 and body == 'existing-toolbar-history', 'bridge must leave the installed toolbar history endpoint alone'
                config = root / 'php-data-bridge.json'
                config.write_text('{"collect":{"enabled":false}}')
                status, response, body = request('/', headers)
                assert 'x-toolbar' not in response and response['x-recorded-queries'] == '0', (framework, response)
                assert 'raw' not in request('/_toolbar/requests/' + id, headers)[2], 'opt-out must disable history'
                config.unlink()
                assert 'x-toolbar' in request('/', headers)[1], 'removing opt-out restores collection'
            print('PASS: installation-only enablement, app opt-out, Laravel/Symfony hooks, production denial, repeated/aborted requests, query limits')
        finally:
            process.terminate(); process.wait(timeout=10)
            for store in stores:
                path = Path(store)
                assert path.parent.name == f'php-data-bridge-{os.geteuid()}' and len(path.name) == 64
                shutil.rmtree(path, ignore_errors=True)
            log.seek(0)
            errors = log.read()
            if any(word in errors for word in ['Fatal', 'Warning', '[php-data-bridge]']):
                raise AssertionError(errors)
