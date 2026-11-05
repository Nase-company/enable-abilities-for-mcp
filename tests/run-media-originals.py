#!/usr/bin/env python3
"""Isolated native WordPress acceptance; uses existing Docker images, never production.
Run: python tests/run-media-originals.py --evidence /path/to/owned/receipts
"""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

args = argparse.ArgumentParser()
args.add_argument('--evidence', type=Path)
args.add_argument('--package', type=Path, help='Test a built release ZIP, with fixtures mounted separately')
opts = args.parse_args()
repo = Path(__file__).resolve().parent.parent
scratch = Path(os.environ.get('TMPDIR', str(Path.home() / '.cache')))
scratch.mkdir(parents=True, exist_ok=True)
receipts = opts.evidence or Path(tempfile.mkdtemp(prefix='ewpa-originals-', dir=scratch))
receipts.mkdir(parents=True, exist_ok=True)
run_id = secrets.token_hex(5)
db, wp = 'ewpa-originals-db-' + run_id, 'ewpa-originals-wp-' + run_id
owned = []
mounts = []
plugin_path = '/var/www/html/wp-content/plugins/enable-abilities-for-mcp'
source = repo
if opts.package:
    unpacked = receipts / ('package-' + run_id)
    with zipfile.ZipFile(opts.package) as archive:
        for member in archive.infolist():
            parts = Path(member.filename).parts
            if not parts or parts[0] != 'enable-abilities-for-mcp' or '..' in parts or '\\' in member.filename or (member.external_attr >> 16) & 0o170000 == 0o120000:
                raise RuntimeError('unsafe package entry')
        archive.extractall(unpacked)
    source = unpacked / 'enable-abilities-for-mcp'
    if (source / 'tests').exists():
        raise RuntimeError('release ZIP must not contain test fixtures')

def command(argv):
    result = subprocess.run(argv, capture_output=True, text=True, timeout=120)
    if result.returncode:
        if any(value.endswith('/media-original-wordpress.php') or value.endswith('/media-original-fixtures.php') for value in argv):
            (receipts / 'fixture-failure.log').write_text(result.stdout + result.stderr)
        # Never dump arbitrary Docker/PHP stderr, which could contain credentials.
        raise RuntimeError('command failed: ' + argv[0] + ' (exit ' + str(result.returncode) + ')')
    return result.stdout

def php_fixture(name, env=None, flags=None):
    argv = ['docker', 'exec', '-u', 'www-data']
    for key, value in (env or {}).items():
        argv += ['-e', key + '=' + str(value)]
    argv += [wp, 'php', '-d', 'memory_limit=128M'] + (flags or []) + ['/ewpa-tests/' + name]
    return command(argv)

def require_marker(output, marker, name):
    (receipts / name).write_text(output)
    if marker not in output:
        raise RuntimeError('missing ' + marker + '; see ' + str(receipts / name))
    print(marker)

def http(route, payload, auth=None, method='POST'):
    url = origin + '/?rest_route=/wp-abilities/v1/abilities/ewpa/' + route + '/run'
    data = None
    if method == 'GET':
        url += '&' + urllib.parse.urlencode({'input[attachment_id]': payload['attachment_id']})
    else:
        data = json.dumps({'input': payload}).encode()
    headers = {'Content-Type': 'application/json'}
    if auth:
        headers['Authorization'] = 'Basic ' + base64.b64encode((auth['login'] + ':' + auth['password']).encode()).decode()
    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)

try:
    if shutil.disk_usage(repo).free < 512 * 1024 * 1024:
        raise RuntimeError('insufficient disk headroom for tiny isolated fixtures')
    for image in ('mariadb:11', 'wordpress:php8.3-apache'):
        command(['docker', 'image', 'inspect', '--format', '{{.Id}}', image])
    db_env = receipts / ('db-' + run_id + '.env')
    password = secrets.token_hex(24)
    db_env.write_text('MARIADB_ROOT_PASSWORD=' + secrets.token_hex(24) + '\nMARIADB_DATABASE=wordpress\nMARIADB_USER=wordpress\nMARIADB_PASSWORD=' + password + '\n')
    db_env.chmod(0o600)
    command(['docker', 'run', '-d', '--pull=never', '--name', db, '--memory=256m', '--env-file', str(db_env), '-p', '127.0.0.1::80', 'mariadb:11', '--innodb-buffer-pool-size=64M', '--max-connections=15'])
    owned.append(db)
    origin = 'http://' + command(['docker', 'port', db, '80']).strip()
    wp_env = receipts / ('wp-' + run_id + '.env')
    wp_env.write_text('WORDPRESS_DB_HOST=127.0.0.1\nWORDPRESS_DB_USER=wordpress\nWORDPRESS_DB_NAME=wordpress\nWORDPRESS_DB_PASSWORD=' + password + '\nWP_ENVIRONMENT_TYPE=local\nEWPA_TEST_URL=' + origin + '\n')
    wp_env.chmod(0o600)
    for attempt in range(45):
        result = subprocess.run(['docker', 'exec', db, 'sh', '-c', 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -N -e "SELECT 1"'], capture_output=True, text=True, timeout=10)
        if result.returncode == 0 and result.stdout.strip() == '1':
            break
        time.sleep(1)
    else:
        raise RuntimeError('DB readiness exhausted')
    command(['docker', 'run', '-d', '--pull=never', '--name', wp, '--network', 'container:' + db, '--memory=384m', '--env-file', str(wp_env), '-v', str(source) + ':' + plugin_path + ':ro', '-v', str(repo / 'tests') + ':/ewpa-tests:ro', 'wordpress:php8.3-apache'])
    owned.append(wp)
    for attempt in range(45):
        if subprocess.run(['docker', 'exec', wp, 'test', '-f', '/var/www/html/wp-config.php'], capture_output=True, timeout=10).returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('WordPress config readiness exhausted')
    for name in owned:
        current = json.loads(command(['docker', 'inspect', '--format', '{{json .Mounts}}', name]))
        mounts += [mount['Name'] for mount in current if mount['Type'] == 'volume']
    (receipts / 'owned-mounts.json').write_text(json.dumps({'containers': owned, 'volumes': mounts}, indent=2))
    require_marker(php_fixture('media-original-fixtures.php', {'EWPA_SPIKE_ONLY': '1'}), 'SPIKE_VALIDATED', 'spike.log')
    require_marker(php_fixture('media-original-wordpress.php'), 'MEDIA_ORIGINAL_ACCEPTANCE_OK', 'wordpress.log')
    for function in ('rename', 'fopen', 'chmod', 'fsync'):
        require_marker(php_fixture('media-original-http.php', {'EWPA_HTTP_MODE': 'disabled-function'}, ['-d', 'disable_functions=' + function]), 'DISABLED_FUNCTION_OK', 'disabled-' + function + '.log')
    setup = json.loads(php_fixture('media-original-http.php', {'EWPA_HTTP_MODE': 'prepare'}))
    identity = {'attachment_id': setup['attachment_id']}
    status, denied = http('replace-media-original', identity, setup['admin'])
    assert status == 404 and denied['code'] == 'rest_ability_not_found', (status, denied.get('code'))
    status, denied = http('inspect-media-original', identity, setup['subscriber'], 'GET')
    assert status in (401, 403), (status, denied.get('code'))
    status, inspection = http('inspect-media-original', identity, setup['admin'], 'GET')
    assert status == 200 and inspection['has_byte_benefit'] is True
    payload = dict(identity, expected_original_sha256=inspection['original_sha256'], expected_main_sha256=inspection['main_sha256'], expected_metadata_sha256=inspection['metadata_sha256'], external_archive_sha256=inspection['original_sha256'], confirm_replace=True)
    assert php_fixture('media-original-http.php', {'EWPA_HTTP_MODE': 'enable'}) == 'ENABLED'
    status, result = http('replace-media-original', payload, setup['admin'])
    assert status == 200 and result['status'] == 'replaced' and result['backup_verified'] is False
    status, replay = http('replace-media-original', payload, setup['admin'])
    assert status >= 400 and replay['code'] == 'stale_input'
    readback = json.loads(php_fixture('media-original-http.php', {'EWPA_HTTP_MODE': 'readback', 'EWPA_HTTP_ID': identity['attachment_id']}))
    assert readback['snapshot_sha256'] == setup['snapshot_sha256']
    assert readback['original_sha256'] == readback['main_sha256'] == payload['expected_main_sha256']
    receipt = {'status': 'HTTP_ABILITIES_ACCEPTANCE_OK', 'disabled_ability': 404, 'subscriber_denied': True, 'inspect_http': 200, 'replace_http': 200, 'replay_code': replay['code'], 'raw_attachment_rows_and_urls_unchanged': True, 'readback_hash_equal': True, 'backup_verified': False, 'wordpress': command(['docker', 'exec', wp, 'php', '-r', 'require "/var/www/html/wp-load.php"; echo get_bloginfo("version");']).strip()}
    (receipts / 'http.json').write_text(json.dumps(receipt, indent=2))
    print(json.dumps(receipt))
    print('MEDIA_ORIGINAL_DOCKER_OK')
finally:
    for name in reversed(owned):
        command(['docker', 'rm', '-fv', name])
    for volume in mounts:
        if subprocess.run(['docker', 'volume', 'inspect', volume], capture_output=True, timeout=10).returncode == 0:
            raise RuntimeError('run-owned anonymous volume remains')
    for env in receipts.glob('*-' + run_id + '.env'):
        env.unlink()
    print('OWNED_CONTAINERS_AND_VOLUMES_REMOVED')
