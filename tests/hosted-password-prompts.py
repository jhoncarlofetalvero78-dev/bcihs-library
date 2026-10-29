"""Synthetic-input tests only: no hosted/local database connections or real credentials."""
import contextlib
import io
import json
from pathlib import Path
import runpy
import sys
import tempfile
from types import SimpleNamespace
import unittest.mock as mock
import warnings

module = runpy.run_path(str(Path(__file__).resolve().parent.parent / 'scripts/prepare-hosted-passwords.py'))
scope = module['main'].__globals__
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise RuntimeError(label)
    checks += 1


valid = 'Ab9!' + 'x' * 16
other = 'Cd8#' + 'y' * 16
errors = module['password_errors']
for size in [20, 72]:
    check(errors('Ab9!' + 'x' * (size - 4)) == [], 'ASCII boundary should pass')
cases = [
    ('Ab9!' + 'x' * 15, 'Too short'),
    ('Ab9!' + 'x' * 69, 'Too long'),
    ('AB9!' + 'X' * 16, 'Missing a lowercase'),
    ('ab9!' + 'x' * 16, 'Missing an uppercase'),
    ('Abc!' + 'x' * 16, 'Missing a digit'),
    ('Ab9x' + 'x' * 16, 'Missing a non-alphanumeric'),
    (valid + '\0', 'Null characters'),
    ('Ab9!' + 'é' * 35, 'Too long'),
]
for value, reason in cases:
    messages = errors(value)
    check(any(reason in message for message in messages), 'Specific validation reason')
    check(not module['strong'](value), 'Invalid input should not pass')
    check(value not in '\n'.join(messages), 'Error must not echo input')
check(len(errors('')) == 5, 'Empty input reports length and all four missing requirements')
check(errors('Ab9!' + 'é' * 8) == [], 'UTF-8 byte minimum is explicit and unchanged')
check(module['strong'](valid + ' '), 'Existing whitespace handling remains unchanged; no silent trim')


def prompted(values, previous=None):
    output = io.StringIO()
    iterator = iter(values)
    with mock.patch.dict(scope, {'hidden_input': lambda _prompt: next(iterator)}), contextlib.redirect_stdout(output):
        value = module['prompt_password']('user', previous)
    return value, output.getvalue()


value, output = prompted(['short', valid, valid])
check(value == valid and 'Too short' in output, 'Invalid rules retry before confirmation')
check(valid not in output and 'short' not in output.replace('Too short', ''), 'Retry output does not expose inputs')
value, output = prompted([valid, other, valid, valid])
check(value == valid and 'Confirmation mismatch' in output, 'Mismatch retries with a specific explanation')
check(valid not in output and other not in output, 'Mismatch output is sanitized')
value, output = prompted([valid, other, other], valid)
check(value == other and 'must be different' in output, 'Duplicate account password is rejected before confirmation')
check(valid not in output and other not in output, 'Duplicate output is sanitized')

with mock.patch.object(scope['getpass'], 'getpass', side_effect=lambda _prompt: warnings.warn('No hidden input', scope['getpass'].GetPassWarning)):
    try:
        module['hidden_input']('Synthetic prompt: ')
        check(False, 'Unavailable hidden input should fail closed')
    except RuntimeError as error:
        check('Hidden input is unavailable' in str(error), 'Hidden-input failure has a safe actionable explanation')

with tempfile.TemporaryDirectory(prefix='bcihs-prompt-qa-') as folder:
    destination = Path(folder) / 'synthetic-output'
    answers = iter([valid, valid, other, other])
    output = io.StringIO()
    with mock.patch.dict(scope, {'DEFAULT': destination, 'hidden_input': lambda _prompt: next(answers)}), \
            mock.patch.object(sys, 'argv', ['prepare-hosted-passwords.py']), \
            mock.patch.object(sys, 'stdin', SimpleNamespace(isatty=lambda: True)), contextlib.redirect_stdout(output):
        module['main']()
    check(set(p.name for p in destination.iterdir()) == {'01-update-hosted-passwords.sql', '02-verify-hosted-passwords.sql', 'prepared.json'}, 'Successful preparation writes only expected private SQL and receipt')
    check(not (destination / 'activation.json').exists(), 'Preparation never creates activation marker')
    check(all(valid not in p.read_text() and other not in p.read_text() for p in destination.iterdir()), 'No plaintext password stored in generated files')
    check(valid not in output.getvalue() and other not in output.getvalue(), 'No plaintext password in terminal output')
    check(all(p.stat().st_mode & 0o077 == 0 for p in destination.iterdir()), 'Generated files remain owner-only')
    check(destination.stat().st_mode & 0o077 == 0, 'Output directory remains owner-only')

print(f'Completed {checks} offline password validation/prompt/privacy checks. Synthetic inputs and owned temporary files only; no databases, network requests or activation markers.')
