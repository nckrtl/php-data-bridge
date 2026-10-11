#!/usr/bin/env python3
"""Verify PHP request lifetime in an isolated FPM pool (no installed service changes)."""
import getpass
import grp
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

repo = Path(__file__).resolve().parents[1]
fpm = sys.argv[1] if len(sys.argv) > 1 else 'php-fpm'
with tempfile.TemporaryDirectory(prefix='bridge-fpm-', dir='/tmp') as temporary:
    root = Path(temporary)
    socket = root / 'fpm.sock'
    (root / 'prepend.php').write_text('''<?php
require_once __DIR__.'/state.php';
define('REQUEST_CONSTANT', true);
RequestState::$queries[] = $_SERVER['REQUEST_URI'];
''')
    (root / 'state.php').write_text('<?php class RequestState { public static array $queries = []; }')
    (root / 'index.php').write_text('''<?php
if ($_SERVER['REQUEST_URI'] === '/abort') { exit('aborted'); }
echo json_encode(['pid' => getmypid(), 'queries' => RequestState::$queries]);
''')
    (root / 'fpm.conf').write_text(f'''[global]
pid = {root}/fpm.pid
error_log = {root}/error.log
daemonize = no
[bridge-test]
user = {getpass.getuser()}
group = {grp.getgrgid(os.getgid()).gr_name}
listen = {socket}
pm = static
pm.max_children = 1
php_admin_value[auto_prepend_file] = {root}/prepend.php
''')
    process = subprocess.Popen([fpm, '-n', '-F', '-y', str(root / 'fpm.conf')], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    try:
        for _ in range(100):
            if socket.exists(): break
            if process.poll() is not None: raise RuntimeError(process.communicate())
            time.sleep(.02)
        def request(path):
            response = subprocess.run(['php', str(repo / 'tools/fcgi.php'), str(socket), str(root), path], capture_output=True, text=True, check=True)
            assert not response.stderr, response.stderr
            return response.stdout.split('\n\n', 1)[1]
        first = json.loads(request('/first'))
        second = json.loads(request('/second'))
        assert first['pid'] == second['pid']
        assert first['queries'] == ['/first'] and second['queries'] == ['/second']
        assert request('/abort') == 'aborted'
        third = json.loads(request('/after-abort'))
        assert third['pid'] == first['pid'] and third['queries'] == ['/after-abort']
        print('PASS: same FPM worker resets classes, require_once state, constants and statics, including after exit')
    finally:
        process.terminate(); process.communicate(timeout=10)
