"""Start/reuse the existing isolated BCIHS preview. No seeding or database writes."""
import argparse
import fcntl
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import time
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
WORKSPACE = Path('/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01')
PHP = Path('/Applications/MAMP/bin/php/php8.3.30/bin/php')
URL = 'http://127.0.0.1:8899/index.html'
DATABASE = 'bcihs_demo_20261028_236ee935'


def listening():
    with socket.socket() as connection:
        connection.settimeout(1)
        return connection.connect_ex(('127.0.0.1', 8899)) == 0


def healthy():
    try:
        with urllib.request.urlopen('http://127.0.0.1:8899/__staging-status', timeout=3) as response:
            status = json.load(response)
        return (status.get('application') == 'bcihs-final-staging'
                and status.get('database') == DATABASE
                and status.get('sms_permission') is False
                and status.get('provider') == 'simulation'
                and status.get('live_enabled') is False
                and status.get('credentials_present') is False)
    except (OSError, ValueError):
        return False


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--no-open', action='store_true', help='Verify/start without opening a browser.')
    args = parser.parse_args()
    os.umask(0o077)
    owner_file = WORKSPACE / 'demo-owner.json'
    if owner_file.is_symlink() or not owner_file.is_file() or owner_file.stat().st_mode & 0o077:
        raise RuntimeError('Private staging ownership marker is missing or not protected.')
    owner = json.loads(owner_file.read_text())
    if owner.get('database') != DATABASE or owner.get('source') != 'bcihs_library' or owner.get('local_only') is not True:
        raise RuntimeError('Staging ownership does not match. No changes made.')
    with (WORKSPACE / 'launch.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if listening():
            if not healthy():
                raise RuntimeError('Port 8899 is occupied or staging safety checks failed. Nothing was stopped or replaced.')
            print('Existing final staging preview verified; reusing it.')
        else:
            if not PHP.is_file():
                raise RuntimeError('MAMP PHP is unavailable. Restore the configured MAMP installation first.')
            verify = subprocess.run([str(PHP), str(ROOT / 'scripts/demo-environment.php'), 'verify',
                                     '--workspace=' + str(WORKSPACE)], cwd=str(ROOT),
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if verify.returncode != 0:
                raise RuntimeError('Staging verification failed. Start MySQL in MAMP, then try again. No reset was attempted.')
            marker = WORKSPACE / 'server-running'
            if marker.exists():
                if marker.is_symlink():
                    raise RuntimeError('Unexpected preview marker. No changes made.')
                pid = int(marker.read_text())
                process = subprocess.run(['/bin/ps', '-p', str(pid), '-o', 'command='],
                                         stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                if str(ROOT / 'scripts/demo-environment.php') in process.stdout and str(WORKSPACE) in process.stdout:
                    raise RuntimeError('The owned preview process exists but is not listening. Review it before restarting.')
                # Marker belongs to a stopped preview or a reused PID; never kill that process.
                marker.unlink()
            log = WORKSPACE / 'preview-launch.log'
            if log.is_symlink():
                raise RuntimeError('Unexpected launch log. No changes made.')
            with log.open('ab') as output:
                os.chmod(str(log), 0o600)
                process = subprocess.Popen([str(PHP), str(ROOT / 'scripts/demo-environment.php'), 'serve',
                                            '--workspace=' + str(WORKSPACE), '--port=8899'], cwd=str(ROOT),
                                           stdin=subprocess.DEVNULL, stdout=output, stderr=output,
                                           start_new_session=True)
            for _ in range(40):
                if healthy():
                    break
                if process.poll() is not None:
                    raise RuntimeError('Preview did not start. Check MAMP MySQL and private preview permissions; do not reset data.')
                time.sleep(0.25)
            else:
                raise RuntimeError('Preview readiness timed out. No database reset or provider request was attempted.')
            print('Final staging preview started on loopback only.')
    print('Database: ' + DATABASE)
    print('SMS: OFF, simulation only, no provider credentials.')
    print('Open: ' + URL)
    if not args.no_open:
        subprocess.run(['/usr/bin/open', URL], check=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Static operational errors only. Never dump private config or subprocess output.
        message = str(error) if isinstance(error, RuntimeError) else 'Preview could not be verified. No database reset was attempted.'
        print(message, file=sys.stderr)
        sys.exit(1)
