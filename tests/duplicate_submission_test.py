"""Concurrency and expiry test for the public duplicate claim, in a disposable DB."""
import os, secrets, subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', r'C:\xampp\php\php.exe' if os.name == 'nt' else 'php')
DB = 'pasig_count_test_' + secrets.token_hex(8)
FIXTURE = ROOT / 'tests/client_count_fixture.php'
FLAGS = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0

def run(action):
    return subprocess.check_output([PHP, str(FIXTURE), action, DB], cwd=ROOT, text=True, creationflags=FLAGS).strip()

try:
    run('setup')
    workers = [subprocess.Popen([PHP, str(FIXTURE), 'claim', DB], cwd=ROOT, text=True,
               stdout=subprocess.PIPE, stderr=subprocess.PIPE, creationflags=FLAGS) for _ in range(2)]
    results = sorted(worker.communicate(timeout=20)[0].strip() for worker in workers)
    assert results == ['claimed', 'duplicate'], results
    assert run('claim') == 'duplicate'
    assert run('expire_claim') == 'expired'
    assert run('claim') == 'claimed'
    print('PASS: simultaneous duplicates serialize; active-window repeats fail; expired-window repeats succeed.')
finally:
    run('cleanup')
