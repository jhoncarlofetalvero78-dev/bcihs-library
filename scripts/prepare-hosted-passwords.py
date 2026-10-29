"""Offline-only hosted password preparation. Never connects to a database or provider."""
import argparse
from datetime import datetime, timezone
import getpass
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import warnings

TARGET = 'u473523930_bcihs_library'
PHP = '/Applications/MAMP/bin/php/php8.3.30/bin/php'
DEFAULT = Path.home() / '.codex/bcihs-hostinger/private-account-rotation'


def password_errors(value):
    errors = []
    size = len(value.encode('utf-8'))
    if size < 20:
        errors.append('Too short: use at least 20 UTF-8 bytes (20 characters for ASCII passwords).')
    if size > 72:
        errors.append('Too long: use at most 72 UTF-8 bytes; accented characters and emojis can use multiple bytes.')
    if '\0' in value:
        errors.append('Null characters are not allowed.')
    for pattern, message in [
        (r'[a-z]', 'Missing a lowercase letter: include a-z.'),
        (r'[A-Z]', 'Missing an uppercase letter: include A-Z.'),
        (r'[0-9]', 'Missing a digit: include 0-9.'),
        (r'[^a-zA-Z0-9]', 'Missing a non-alphanumeric character: include punctuation such as ! or #.'),
    ]:
        if not re.search(pattern, value):
            errors.append(message)
    return errors


def strong(value):
    return not password_errors(value)


def hidden_input(prompt):
    # Never fall back to echoed input if Terminal cannot provide a hidden prompt.
    with warnings.catch_warnings():
        warnings.simplefilter('error', getpass.GetPassWarning)
        try:
            return getpass.getpass(prompt)
        except getpass.GetPassWarning:
            raise RuntimeError('Hidden input is unavailable. Run this command directly in macOS Terminal; no password will be echoed.') from None


def prompt_password(account, previous=None):
    while True:
        value = hidden_input('New hosted ' + account + ' password: ')
        errors = password_errors(value)
        if previous is not None and value == previous:
            errors.append('The student password must be different from the administrator password.')
        if errors:
            print('Password rejected:')
            for message in errors:
                print(' - ' + message)
            print('Please enter a new password. No files have been created.\n')
            continue
        confirmation = hidden_input('Re-enter the same ' + account + ' password: ')
        if value != confirmation:
            print('Confirmation mismatch: the two entries were different. Enter this account password again. No files have been created.\n')
            continue
        print(account.capitalize() + ' password accepted.\n')
        return value


def sql_files(admin_hash, student_hash):
    for value in [admin_hash, student_hash]:
        if not re.fullmatch(r'\$2y\$\d{2}\$[./A-Za-z0-9]{53}', value):
            raise RuntimeError('Unexpected PHP hash format; stop without uploading anything.')
    schema = '`' + TARGET + '`'
    scope = (f"DATABASE()='{TARGET}' AND (SELECT COUNT(*) FROM {schema}.admins)=1 "
             f"AND (SELECT COUNT(*) FROM {schema}.users)=1")
    join = (f"FROM {schema}.admins a JOIN {schema}.users u ON u.id=1 "
            "WHERE a.id=1 AND a.username='admin' AND u.student_id='user' "
            "AND u.role='student' AND u.account_status='accepted'")
    verify = (f"SELECT ({scope}) AS correct_target_and_account_counts,\n"
              f"  (a.password_hash='{admin_hash}') AS admin_hash_matches,\n"
              f"  (u.password_hash='{student_hash}') AS student_hash_matches,\n"
              "  (u.reset_code IS NULL AND u.reset_code_expires IS NULL) AS recovery_fields_clear,\n"
              f"  ((SELECT COUNT(*) FROM {schema}.sms_notification_settings WHERE id=1 AND allow_notifications=0)=1) AS sms_still_off\n"
              f"{join};\n")
    update = ("-- PRIVATE HASHES ONLY. Keep website default-denied. Select the exact hosted DB first.\n"
              "-- One InnoDB multi-table statement updates the matched pair together, or neither.\n"
              f"SET @bcihs_rotation_scope_ok := ({scope});\n"
              f"UPDATE {schema}.admins a JOIN {schema}.users u ON u.id=1\n"
              f"SET a.password_hash='{admin_hash}', u.password_hash='{student_hash}',\n"
              "    u.reset_code=NULL, u.reset_code_expires=NULL\n"
              "WHERE @bcihs_rotation_scope_ok=1 AND a.id=1 AND a.username='admin'\n"
              "  AND u.student_id='user' AND u.role='student' AND u.account_status='accepted';\n"
              "SELECT ROW_COUNT() AS changed_accounts;\n" + verify)
    return update, verify


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--finalize', action='store_true', help='Write marker only after you verify the hosted SQL results.')
    args = parser.parse_args()
    os.umask(0o077)
    if DEFAULT.is_symlink():
        raise RuntimeError('Unexpected private output path.')
    if args.finalize:
        receipt = DEFAULT / 'prepared.json'
        if not receipt.is_file() or receipt.is_symlink() or receipt.stat().st_mode & 0o077:
            raise RuntimeError('Protected preparation record is required.')
        state = json.loads(receipt.read_text())
        if state.get('target') != TARGET:
            raise RuntimeError('Prepared target does not match.')
        print('Continue ONLY after the separate hosted verification query returned exactly one row with ALL FIVE values equal to 1 using these same prepared SQL files.')
        print('An initial update normally changes 2 accounts; repeating the same update can report 0 because the expected hashes are already saved. A zero count alone is not verification.')
        print('Keep Require all denied in place. This is your administrative verification, not an automatic connection to Hostinger.')
        if input('Type VERIFIED ' + TARGET + ' to confirm those completed checks: ') != 'VERIFIED ' + TARGET:
            raise RuntimeError('No marker created.')
        marker = DEFAULT / 'activation.json'
        with marker.open('x') as output:
            json.dump({'credentials_rotated': True, 'rotated_at': datetime.now(timezone.utc).isoformat(),
                       'rotation_method': 'offline_hashing_verified_private_database_update'}, output, indent=2)
            output.write('\n')
        os.chmod(marker, 0o600)
        print('Private activation.json prepared. Upload ONLY into the existing bcihs-private sibling of public_html, after verification. Do not remove default-deny protection.')
        return
    if DEFAULT.exists():
        raise RuntimeError('Private preparation directory already exists. Preserve it; do not overwrite or repeat a completed rotation.')
    if not sys.stdin.isatty():
        raise RuntimeError('Run interactively in your Mac Terminal; hidden password input is required.')
    print('Use two different passwords, each 20-72 UTF-8 bytes long.')
    print('Each needs lowercase a-z, uppercase A-Z, a digit 0-9, and a non-alphanumeric character (prefer punctuation).')
    print('For simple ASCII passwords, 20-72 characters equals 20-72 bytes. Null characters are forbidden.')
    print('Nothing appears while you type or paste. Input is not trimmed; confirmation must match exactly.')
    print('Press Control-C to cancel without creating files.\n')
    passwords = {}
    for account in ['admin', 'user']:
        passwords[account] = prompt_password(account, passwords.get('admin'))
    code = ('$p=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$r=[];'
            'foreach($p as $k=>$v){$r[$k]=password_hash($v,PASSWORD_DEFAULT);if(!password_verify($v,$r[$k]))exit(1);}'
            'echo json_encode($r,JSON_THROW_ON_ERROR);')
    result = subprocess.run([PHP, '-d', 'display_errors=0', '-r', code],
                            input=json.dumps(passwords), text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    passwords.clear()
    if result.returncode != 0 or result.stderr:
        raise RuntimeError('Offline PHP hashing failed. No output files created.')
    hashes = json.loads(result.stdout)
    update, verify = sql_files(hashes['admin'], hashes['user'])
    DEFAULT.mkdir(parents=True, mode=0o700)
    for name, content in [('01-update-hosted-passwords.sql', update), ('02-verify-hosted-passwords.sql', verify),
                          ('prepared.json', json.dumps({'target': TARGET, 'generated_at': datetime.now(timezone.utc).isoformat()})+'\n')]:
        file = DEFAULT / name
        with file.open('x') as output:
            output.write(content)
        file.chmod(0o600)
    print('Private SQL files created at: ' + str(DEFAULT))
    print('No passwords/hashes printed, no database connection, no activation marker, and no network request.')


if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        message = str(error) if isinstance(error, RuntimeError) else 'Preparation stopped safely. No private data displayed.'
        print(message, file=sys.stderr)
        sys.exit(1)
