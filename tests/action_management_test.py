"""Real PHP/HTTP/MySQL workflow test in a disposable database, never production."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', r'C:\xampp\php\php.exe' if os.name == 'nt' else 'php')
DB = 'pasig_count_test_' + secrets.token_hex(8)
FLAGS = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0


def fixture(action):
    return subprocess.check_output([PHP, str(ROOT / 'tests/client_count_fixture.php'), action, DB],
                                   cwd=ROOT, creationflags=FLAGS, text=True)


def check(name, condition):
    if not condition:
        raise AssertionError(name)
    print('PASS:', name, flush=True)


class Browser:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, path, data=None):
        payload = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            response = self.opener.open(BASE + path, payload, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8')

    def login(self, username):
        _, page = self.request('/login.php')
        return self.request('/login.php', {'csrf_token': token(page), 'identity': username, 'password': 'Workflow123!'})


def token(page):
    match = re.search(r'name="csrf_token" value="([^"]+)"', page)
    if not match:
        raise AssertionError('CSRF token missing: ' + page[:300])
    return match.group(1)


def actions(mode='inspect_actions'):
    return json.loads(subprocess.check_output(
        [PHP, str(ROOT / 'tests/action_management_fixture.php'), mode, DB],
        cwd=ROOT, creationflags=FLAGS, text=True))


server = None
with tempfile.TemporaryDirectory(prefix='pasig-count-test-') as temp:
    log = open(Path(temp) / 'server.log', 'w+', encoding='utf-8')
    try:
        fixture('setup')
        actions('seed_actions')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        BASE = f'http://127.0.0.1:{port}/{ROOT.name}'
        env = dict(os.environ, PASIG_DB_NAME=DB)
        server = subprocess.Popen([PHP, '-d', 'session.save_path="' + Path(temp).as_posix() + '"', '-S', f'127.0.0.1:{port}', '-t', str(ROOT.parent)],
                                  cwd=ROOT, env=env, stdout=log, stderr=log, creationflags=FLAGS)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        admin, head, staff = Browser(), Browser(), Browser()
        for browser, username in [(admin, 'other_admin'), (head, 'head'), (staff, 'staff')]:
            code, page = browser.login(username)
            check(username + ' authenticated', code == 200 and 'Successfully logged in' in page)
        before = actions()
        code, page = admin.request('/admin/actions.php')
        check('Administrator can open Action Management', code == 200)
        check('opening page leaves all action data unchanged', actions() == before)
        for row in before:
            _, filtered = admin.request('/admin/actions.php?status=' + row['status'])
            forms = re.findall(r'<form method="post".*?</form>', filtered, re.S)
            check(row['status'] + ' filter renders one update form', len(forms) == 1)
            form = forms[0]
            options = re.findall(r'<option value="([^"]*)"([^>]*)>', form)
            selected = [value for value, attrs in options if 'selected' in attrs]
            allowed = [value for value, attrs in options if 'disabled' not in attrs]
            check('allowed update statuses unchanged', allowed == ['needs_action', 'in_progress', 'completed'])
            if row['status'] == 'pending_approval':
                check('pending approval requires an explicit allowed transition', selected == [''] and 'selected disabled' in form)
                continue
            check(row['status'] + ' is selected from database', selected == [row['status']])
            code, saved = admin.request('/admin/actions.php', {
                'csrf_token': token(form), 'action_id': row['id'],
                'status': selected[0], 'resolution_notes': 'Updated notes only'})
            updated = next(item for item in actions() if item['id'] == row['id'])
            check(row['status'] + ' notes-only save preserves status',
                  code == 200 and updated['status'] == row['status'] and updated['resolution_notes'] == 'Updated notes only')
        snapshot = actions()
        for browser, role in [(head, 'Office Head'), (staff, 'Office Staff')]:
            check(role + ' denied Action Management GET', browser.request('/admin/actions.php')[0] == 403)
            _, dashboard = browser.request('/account.php')
            check(role + ' denied Action Management POST', browser.request('/admin/actions.php', {
                'csrf_token': token(dashboard), 'action_id': 1, 'status': 'completed',
                'resolution_notes': 'Unauthorized update'})[0] == 403)
        check('missing CSRF rejected', admin.request('/admin/actions.php', {
            'action_id': 1, 'status': 'completed', 'resolution_notes': 'Bad token update'})[0] == 419)
        _, page = admin.request('/admin/actions.php')
        admin.request('/admin/actions.php', {'csrf_token': token(page), 'action_id': 1,
                      'status': 'pending_approval', 'resolution_notes': 'Invalid transition'})
        check('authorization, CSRF and invalid status attempts leave action data unchanged', actions() == snapshot)
        print('PASS: Action Management regression suite completed in disposable database.', flush=True)
    except Exception:
        log.flush()
        log.seek(0)
        print(log.read()[-4000:])
        raise
    finally:
        if server is not None:
            server.terminate()
            server.wait(timeout=10)
        log.close()
        fixture('cleanup')

