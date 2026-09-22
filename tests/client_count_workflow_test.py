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


server = None
with tempfile.TemporaryDirectory(prefix='pasig-count-test-') as temp:
    log = open(Path(temp) / 'server.log', 'w+', encoding='utf-8')
    try:
        fixture('setup')
        check('migration preserves legacy snapshot without inventing annual year', fixture('migration_test') == 'preserved')
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
        uno, other, head, staff, outsider = [Browser() for _ in range(5)]
        for browser, username in [(uno, 'uno'), (other, 'other_admin'), (head, 'head'), (staff, 'staff'), (outsider, 'other_staff')]:
            code, page = browser.login(username)
            check(username + ' authenticated', code == 200 and 'Successfully logged in' in page)
        for batch_id in [1, 2]:
            code, report = other.request('/office/rejected_rows.php?batch_id=' + str(batch_id))
            check('Administrator can download rejected rows for batch ' + str(batch_id), code == 200 and 'error_message' in report)
        check('Office Head can download own office rejected rows', head.request('/office/rejected_rows.php?batch_id=1')[0] == 200)
        check('Office Head cannot forge rejected-row office scope', head.request('/office/rejected_rows.php?batch_id=2&office_id=2')[0] == 404)
        check('Office Staff cannot download rejected rows', staff.request('/office/rejected_rows.php?batch_id=1')[0] == 403)
        for browser, name in [(uno, 'Uno'), (other, 'another Administrator')]:
            code, page = browser.request('/admin/client-counts.php')
            check(name + ' can open processing page and navigation', code == 200 and 'Annual Client-count Requests' in page and 'admin/client-counts.php' in page)
        for browser, role in [(head, 'Office Head'), (staff, 'Office Staff')]:
            check(role + ' cannot open processing page', browser.request('/admin/client-counts.php')[0] == 403)
            check(role + ' cannot use legacy processing route', browser.request('/office/client-counts.php')[0] == 403)
        for username in ['inactive', 'legacy']:
            browser = Browser()
            browser.login(username)
            _, page = browser.request('/admin/client-counts.php')
            check(username + ' cannot authenticate/access processing page', 'Annual Client-count Requests' not in page and 'Authorized Login' in page)
        for browser, audit_path in [(other, '/admin/system.php'), (head, '/office/audit.php')]:
            code, audit_page = browser.request(audit_path)
            check('historical audit records remain readable: ' + audit_path, code == 200 and 'Legacy role (retired)' in audit_page)
            check('audit filter offers only current roles: ' + audit_path, 'role_filter=supervisor' not in audit_page and 'value="supervisor"' not in audit_page)
        _, page = staff.request('/office/client-count-request.php')
        check('request creation requires CSRF', staff.request('/office/client-count-request.php', {})[0] == 419)
        _, page = staff.request('/office/client-count-request.php', {'csrf_token': token(page), 'staff_id': 8, 'user_id': 8})
        check('Staff creates pending request', 'Pending' in page and 'sent to an Administrator' in page)
        for browser in [uno, other]:
            _, notices = browser.request('/notifications.php')
            check('Administrator receives request notification', 'Annual client count requested' in notices and 'Administrator review' in notices)
        snapshot = json.loads(fixture('inspect'))
        request_id = snapshot['requests'][0]['id']
        check('forged requester IDs ignored and requested year defaults to current year', int(snapshot['requests'][0]['staff_user_id']) == 4 and int(snapshot['requests'][0]['requested_year']) == time.localtime().tm_year)
        _, request_page = staff.request('/office/client-count-request.php')
        _, duplicate = staff.request('/office/client-count-request.php', {'csrf_token': token(request_page)})
        check('duplicate pending annual request rejected', 'pending annual request already exists' in duplicate and len(json.loads(fixture('inspect'))['requests']) == 1)
        check('only active Administrators notified', [int(n['user_id']) for n in snapshot['notifications']] == [1, 2])
        _, uno_pending = uno.request('/admin/client-counts.php')
        check('office separator is an ASCII hyphen', 'TEST - Test Office' in uno_pending and 'TEST ? Test Office' not in uno_pending)
        uno_token = token(uno_pending)
        _, page = other.request('/admin/client-counts.php')
        check('Administrator response requires CSRF', other.request('/admin/client-counts.php', {'request_id': request_id})[0] == 419)
        _, page = other.request('/admin/client-counts.php', {'csrf_token': token(page), 'request_id': request_id, 'count': 999})
        check('Administrator answers using calculated count, ignores submitted count', 'Verified annual count sent: 2.' in page and 'Answered' in page)
        _, page = uno.request('/admin/client-counts.php')
        _, page = uno.request('/admin/client-counts.php', {'csrf_token': uno_token, 'request_id': request_id})
        check('second Administrator cannot answer answered request', 'already been answered' in page)
        _, page = staff.request('/office/client-count-request.php')
        check('Staff sees count, answered status, responder', 'Answered' in page and '<td>2</td>' in page and 'Another Administrator' in page)
        _, page = staff.request('/notifications.php')
        check('Staff receives answered count notification', ' is 2. Status: Answered.' in page and 'Status: Answered' in page)
        _, page = outsider.request('/office/client-count-request.php')
        check('another office cannot see request history', 'No tracked requests yet' in page)
        for path in ['/office/staff.php', '/review.php', '/office/actions.php']:
            check('Office Head business POST denied: ' + path, head.request(path, {})[0] == 403)
        snapshot = json.loads(fixture('inspect'))
        answered = snapshot['requests'][0]
        check('response persisted with count/time/administrator', answered['status'] == 'answered' and int(answered['answered_count']) == 2 and answered['answered_at'] and int(answered['answered_by_user_id']) == 2)
        check('exactly one staff answer notification', sum(n['type'] == 'client_count' for n in snapshot['notifications']) == 1)
        check('request and response audit entries recorded once', [a['action'] for a in snapshot['audit']] == ['client_count_request', 'admin_client_count_sent'])
        check('fixture feedback remains unchanged in count', snapshot['feedback_count'] == 5)
        _, page = staff.request('/office/client-count-request.php')
        staff.request('/office/client-count-request.php', {'csrf_token': token(page)})
        second_id = json.loads(fixture('inspect'))['requests'][-1]['id']
        _, page = uno.request('/admin/client-counts.php')
        _, page = uno.request('/admin/client-counts.php', {'csrf_token': token(page), 'request_id': second_id})
        check('Uno can also answer by Administrator role', 'Verified annual count sent: 2.' in page)
        _, page = staff.request('/office/client-count-request.php')
        staff.request('/office/client-count-request.php', {'csrf_token': token(page)})
        third_id = json.loads(fixture('inspect'))['requests'][-1]['id']
        workers = [subprocess.Popen([PHP, str(ROOT / 'tests/client_count_fixture.php'), 'answer', DB, str(admin), str(third_id)],
                                   cwd=ROOT, creationflags=FLAGS, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                   for admin in [1, 2]]
        results = [worker.communicate(timeout=20) for worker in workers]
        check('simultaneous administrators produce exactly one answer',
              all(worker.returncode == 0 for worker in workers) and sorted(out for out, err in results) == ['answered', 'rejected'])
        snapshot = json.loads(fixture('inspect'))
        check('three requests yield exactly three answers and six audit entries',
              sum(n['type'] == 'client_count' for n in snapshot['notifications']) == 3 and len(snapshot['audit']) == 6)
        fixture('output_setup')
        def output_metrics(page):
            return {label: re.search(re.escape('<small>' + label + '</small><strong>') + r'([^<]+)', page).group(1)
                    for label in ['Total Output', 'Average Daily Output', 'Peak Daily Output', 'Active Days']}
        date_range = '?month=2001-01'
        expected = {'Total Output': '3', 'Average Daily Output': '1.5', 'Peak Daily Output': '2', 'Active Days': '2'}
        _, page = staff.request('/office/client-output.php' + date_range)
        check('staff metrics include only own account-owned assisted records', output_metrics(page) == expected)
        check('staff month selector replaces date controls', 'Your own assisted-client output' in page and 'name="month"' in page and 'name="from"' not in page)
        check('staff filter and Assisted By column removed', 'name="assisted_by"' not in page and '<th>Assisted By</th>' not in page and 'All Staff (Assisted By)' not in page)
        check('staff breakdown groups own records once per day', re.findall(r'<td>(Jan \d\d, 2001)</td><td>.*?>(\d+) clients</span>', page) == [('Jan 02, 2001', '1'), ('Jan 01, 2001', '2')])
        for extra in ['&assisted_by=Other%20Assistant&staff_id=8&user_id=8&office_id=2', '&assisted_by=Unassigned&from=1900-01-01&to=9998-12-31', '&assisted_by=Test%20Staff&imported_by_user_id=8']:
            _, tampered = staff.request('/office/client-output.php' + date_range + extra)
            check('staff cannot widen scope using query parameters: ' + extra, output_metrics(tampered) == expected)
        _, tampered = staff.request('/office/client-output.php' + date_range, {'month':'2002-01','staff_id':8,'user_id':8,'office_id':2,'assisted_by':'Other Assistant'})
        check('POST parameters cannot override staff ownership or selected query month', output_metrics(tampered) == expected)
        _, day = staff.request('/office/client-output.php?month=2001-02')
        check('selected month changes staff results', output_metrics(day) == {'Total Output':'1','Average Daily Output':'1.0','Peak Daily Output':'1','Active Days':'1'})
        staff_b = Browser()
        staff_b.login('staff_b')
        _, other_output = staff_b.request('/office/client-output.php' + date_range)
        check('staff B ownership stays separate even with a colliding display name', output_metrics(other_output) == expected)
        date_range = '?from=2001-01-01&to=2001-01-02'
        for browser, path, total in [(head, '/office/client-output.php', '10'), (other, '/admin/client-output.php', '11')]:
            _, wide = browser.request(path + date_range)
            check('monitoring scope includes office-wide records: ' + path, output_metrics(wide)['Total Output'] == total)
            check('monitoring retains staff filter and column: ' + path, 'name="assisted_by"' in wide and '<th>Assisted By</th>' in wide)
            _, filtered = browser.request(path + date_range + '&assisted_by=Other%20Assistant')
            check('monitoring staff filter works: ' + path, output_metrics(filtered)['Total Output'] == '2')
        check('Office Head cannot POST Client Output', head.request('/office/client-output.php', {'staff_id':8})[0] == 403)
        before = json.loads(fixture('inspect'))['feedback_hash']
        _, empty = staff.request('/office/client-output.php?month=2001-03')
        check('empty new month displays zero without resetting records', output_metrics(empty) == {'Total Output':'0','Average Daily Output':'0.0','Peak Daily Output':'0','Active Days':'0'} and 'No client output' in empty)
        _, default_month = staff.request('/office/client-output.php')
        check('staff output defaults to current month', 'value="' + time.strftime('%Y-%m') + '"' in default_month and output_metrics(default_month)['Total Output'] == '2')
        check('invalid month rejected', staff.request('/office/client-output.php?month=2001-13')[0] == 400)
        _, annual_form = staff.request('/office/client-count-request.php')
        _, bad_year = staff.request('/office/client-count-request.php', {'csrf_token':token(annual_form),'requested_year':'2001 OR 1=1'})
        check('invalid annual year rejected', 'Select a valid calendar year' in bad_year)
        staff.request('/office/client-count-request.php', {'csrf_token':token(annual_form),'requested_year':2001,'staff_id':8,'user_id':8})
        annual = json.loads(fixture('inspect'))['requests'][-1]
        check('annual requester and year stored securely', int(annual['requested_year']) == 2001 and int(annual['staff_user_id']) == 4)
        check('Office Head cannot answer annual request', head.request('/admin/client-counts.php', {'request_id':annual['id']})[0] == 403)
        check('Staff cannot answer annual request', staff.request('/admin/client-counts.php', {'request_id':annual['id']})[0] == 403)
        _, admin_form = other.request('/admin/client-counts.php')
        other.request('/admin/client-counts.php', {'csrf_token':token(admin_form),'request_id':annual['id'],'requested_year':2002})
        annual = json.loads(fixture('inspect'))['requests'][-1]
        check('annual count includes Jan 1 and Dec 31, excludes other years, staff, public, CSV, void and unknown owners', int(annual['answered_count']) == 5 and int(annual['requested_year']) == 2001)
        _, history = staff.request('/office/client-count-request.php')
        check('staff history shows annual year and verified snapshot', '<td>2001</td>' in history and '<td>5</td>' in history and 'Verified Annual Count' in history)
        workers = [subprocess.Popen([PHP, str(ROOT / 'tests/client_count_fixture.php'), 'request', DB], cwd=ROOT, creationflags=FLAGS, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(2)]
        results = [worker.communicate(timeout=20) for worker in workers]
        check('concurrent duplicate annual requests prevented', all(worker.returncode == 0 for worker in workers) and sorted(out for out, err in results) == ['created','rejected'])
        check('monthly browsing and annual verification do not modify feedback', before == json.loads(fixture('inspect'))['feedback_hash'])
        fixture('legacy_setup')
        _, history = staff.request('/office/client-count-request.php?staff_id=7&office_id=2')
        check('own legacy snapshot remains accessible across an office transfer', 'Legacy - no year' in history and '<td>42</td>' in history)
        check('other requester legacy snapshot stays private', '<td>999</td>' not in history)
        legacy_request = json.loads(fixture('inspect'))['requests'][-1]
        _, admin_form = other.request('/admin/client-counts.php')
        check('Administrator can read legacy snapshots', 'Legacy - no year' in admin_form and '<td>42</td>' in admin_form)
        _, rejected = other.request('/admin/client-counts.php', {'csrf_token':token(admin_form),'request_id':legacy_request['id'],'requested_year':2001})
        check('legacy pending request cannot be assigned an invented year', 'legacy request has no year' in rejected and json.loads(fixture('inspect'))['requests'][-1]['requested_year'] is None)
        print('PASS: PHP HTTP workflow completed against isolated MySQL database.', flush=True)
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
