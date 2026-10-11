#!/usr/bin/env python3
import io
import os
from pathlib import Path
import shlex
import subprocess
import tarfile
import tempfile

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='bridge-installer-') as temporary:
    root = Path(temporary)
    prefix = root / 'installed'
    def run(body, success=True, extra=''):
        work = Path(tempfile.mkdtemp(dir=root))
        script = f'''set -euo pipefail
export PHP_DATA_BRIDGE_INSTALL_TEST=1
source {shlex.quote(str(repo / 'install.sh'))}
PREFIX={shlex.quote(str(prefix))}
WORK={shlex.quote(str(work))}
source_dir={shlex.quote(str(repo))}
run() {{ "$@"; }}
{extra}
{body}
'''
        result = subprocess.run(['bash', '-c', script], text=True, capture_output=True)
        assert (result.returncode == 0) == success, (result.returncode, result.stdout, result.stderr)
        return result
    run('install_files')
    original = (prefix / 'current').resolve()
    run('install_files')
    current = (prefix / 'current').resolve()
    assert original != current and (original / 'bridge/prepend.php').is_file()
    assert (current / 'bridge/prepend.php').is_file()
    run('install_files', False, 'run() { if [ "$1" = cp ]; then return 42; fi; "$@"; }')
    assert (prefix / 'current').resolve() == current
    bad = root / 'bad'; (bad / 'bridge').mkdir(parents=True)
    (bad / 'bridge/prepend.php').write_text('<?php')
    (bad / 'bridge/VERSION').write_text('../../../escape')
    run(f'source_dir={shlex.quote(str(bad))}\ninstall_files', False)
    assert (prefix / 'current').resolve() == current
    for name, kind in [('bridge/../outside', 'file'), ('/tmp/outside', 'file'), ('bridge/link', 'link'), ('bridge/ok.php', 'file')]:
        archive = root / 'archive.tar.gz'
        with tarfile.open(archive, 'w:gz') as tar:
            item = tarfile.TarInfo(name)
            if kind == 'link': item.type = tarfile.SYMTYPE; item.linkname = '/tmp'
            else: item.size = 2
            tar.addfile(item, None if kind == 'link' else io.BytesIO(b'ok'))
        run(f'validate_archive {shlex.quote(str(archive))}', name == 'bridge/ok.php')
    # Force the macOS shasum fallback even if GNU coreutils is installed.
    run('''printf 'test' > "$WORK/bridge.tar.gz"
shasum -a 256 "$WORK/bridge.tar.gz" > "$WORK/bridge.tar.gz.sha256"
verify_checksum "$WORK"''', extra='command() { if [ "$*" = "-v sha256sum" ]; then return 1; fi; builtin command "$@"; }')
    run('''printf 'test' > "$WORK/bridge.tar.gz"
printf '%064d' 0 > "$WORK/bridge.tar.gz.sha256"
verify_checksum "$WORK"''', False)
    for formula in ['php', 'php@8.5']:
        keg = root / formula; (keg / 'sbin').mkdir(parents=True)
        binary = keg / 'sbin/php-fpm'; binary.write_text('#!/bin/sh\nexit 0\n'); binary.chmod(0o755)
    binary = root / 'php/sbin/php-fpm'
    result = run(f'reload_fpm {shlex.quote(str(binary))} 8.5', extra=f'''
systemctl() {{ return 1; }}
brew() {{
  if [ "$1" = --prefix ]; then printf '%s/%s\\n' {shlex.quote(str(root))} "$2"
  elif [ "$1 $2" = 'services list' ]; then printf 'php started user\\nphp@8.5 started user\\n'
  else printf 'RESTART %s\\n' "$3"; fi
}}
''')
    assert 'RESTART php\n' in result.stdout and 'RESTART php@' not in result.stdout, result.stdout
print('PASS: atomic reinstall, copy failure, version/archive rejection, checksum fallback, exact Homebrew service')
