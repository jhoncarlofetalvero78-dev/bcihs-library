"""Build an upload candidate outside the repository; never reads private credentials."""
import hashlib
import json
from pathlib import Path
import re
import shutil
import sys
import zipfile

ROOT = Path(__file__).resolve().parent.parent
STAGING = Path('/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01')
release = Path(sys.argv[1]).resolve()
assert release.is_dir() and not release.is_relative_to(ROOT)
verification = json.loads((release / 'database-verification.json').read_text())
assert verification['source_database'] == 'bcihs_demo_20261028_236ee935'
assert verification['restored_row_hashes_match']
assert hashlib.sha256((release / 'bcihs-refined.sql').read_bytes()).hexdigest() == verification['sql_sha256']
package = release / 'application'
assert not package.exists()
public = package / 'public_html'
private = package / 'bcihs-private'
public.mkdir(parents=True, mode=0o700)
private.mkdir(mode=0o700)
for folder in ['backend', 'css', 'js', 'images']:
    for file in (ROOT / folder).rglob('*'):
        if not file.is_file() or file.is_symlink() or any(part.startswith('.') for part in file.relative_to(ROOT / folder).parts):
            continue
        if file.suffix.lower() not in ['.php', '.css', '.js', '.map', '.png', '.jpg', '.jpeg', '.webp', '.gif', '.svg', '.woff', '.woff2', '.ttf', '.ico']:
            continue
        target = public / file.relative_to(ROOT)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(file, target)
for file in ROOT.glob('*.html'):
    shutil.copyfile(file, public / file.name)
(public / 'includes').mkdir()
for file in (ROOT / 'includes').glob('*.php'):
    if file.is_symlink() or '-local' in file.name or file.name == 'semaphore-api-key.php' or file.name.startswith('.'):
        continue
    shutil.copyfile(file, public / 'includes' / file.name)
# Data-linked assets come from the reviewed staging copy, never original uploaded records.
manifest = json.loads((STAGING / 'app/demo-assets/manifest.json').read_text())
assets = [row['image_path'] for row in manifest['books'] + manifest['announcements']]
assert len(assets) == 14
for name in assets:
    assert re.fullmatch(r'demo-assets/(books|announcements)/[a-z-]+\.png', name)
    src = STAGING / 'app' / name
    assert src.is_file() and src.read_bytes() == (ROOT / name).read_bytes()
    target = public / name
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(src, target)
(public / 'demo-assets/ARTWORK-LICENSE.txt').write_text('Original typographic/geometric library artwork. CC0-1.0. No commercial edition jackets or publisher logos included.\n')
# Locked dependencies are private; PHP can still require their autoloader.
shutil.copytree(ROOT / 'vendor', private / 'vendor', symlinks=False)
for file in ['composer.json', 'composer.lock']:
    shutil.copyfile(ROOT / file, private / file)
for name in ['config.example.php', 'rotate-credentials.php']:
    shutil.copyfile(ROOT / 'deployment/hostinger' / name, private / name)
for name in ['hosting-config.php', 'db_config.php']:
    shutil.copyfile(ROOT / 'deployment/hostinger' / name, public / 'includes' / name)
(public / 'includes/sms-local.php').write_text("<?php return ['provider'=>'simulation','mode'=>'simulate','live_enabled'=>false];\n")
(public / 'includes/pickup-local.php').write_text("<?php require_once __DIR__.'/hosting-config.php'; return ['BCIHS_PUBLIC_BASE_URL'=>hostingConfiguration()['public_base_url']];\n")
# Do not allow inherited host environment variables to turn staging transport live.
sms = public / 'includes/sms.php'
text = sms.read_text().replace("    $mode = getenv('BCIHS_SMS_MODE');", "    $mode = 'simulate';")
text = text.replace("    $enabled = getenv('BCIHS_SMS_LIVE_ENABLED');", "    $enabled = '0';")
text = text.replace("    $provider = getenv('BCIHS_SMS_PROVIDER');", "    $provider = 'simulation';")
sms.write_text(text)
pickup = public / 'includes/pickup-qr.php'
pickup.write_text(pickup.read_text().replace("$value = getenv('BCIHS_PUBLIC_BASE_URL');", "$value = false;"))
# Server-side gates run for all database-backed workflows, not just login pages.
session = public / 'includes/session.php'
text = session.read_text().replace('<?php', "<?php\nrequire_once __DIR__.'/hosting-config.php';\nhostingRequireRotation();\nif (session_status() !== PHP_SESSION_ACTIVE) {\n    session_save_path(hostingPrivatePath('sessions'));\n    session_name('BCIHS_HOSTED_SESSION');\n}\n", 1)
session.write_text(text)
for file in ['loan-due.php', 'statistical-report.php']:
    path = public / 'includes' / file
    text = path.read_text().replace('<?php', "<?php\nrequire_once __DIR__.'/hosting-config.php';", 1)
    text = text.replace("__DIR__ . '/borrowing-policy.json'", "hostingPrivatePath('borrowing-policy.json')")
    text = text.replace("__DIR__ . '/.borrowing-policy.lock'", "hostingPrivatePath('.borrowing-policy.lock')")
    text = text.replace("__DIR__ . '/.borrowing-policy-' . bin2hex(random_bytes(8)) . '.json'", "hostingPrivatePath('.borrowing-policy-' . bin2hex(random_bytes(8)) . '.json')")
    text = text.replace("__DIR__.'/report-local.json'", "hostingPrivatePath('report-local.json')")
    path.write_text(text)
security = public / 'includes/security.php'
security.write_text(security.read_text().replace("sys_get_temp_dir() . '/bcihs-security-' . substr(hash('sha256', dirname(__DIR__)), 0, 16)", "hostingPrivatePath('rate-limits')"))
xlsx = public / 'includes/statistical-xlsx.php'
xlsx.write_text(xlsx.read_text().replace("__DIR__.'/../vendor/autoload.php'", "dirname(__DIR__, 2).'/bcihs-private/vendor/autoload.php'"))
for folder in ['sessions', 'rate-limits']:
    (private / folder).mkdir(mode=0o700)
for folder in ['books', 'announcements']:
    path = public / 'uploads' / folder
    path.mkdir(parents=True)
    shutil.copyfile(ROOT / 'uploads' / folder / '.htaccess', path / '.htaccess')
for folder in ['includes']:
    (public / folder / '.htaccess').write_text('Require all denied\n')
(public / 'demo-assets/.htaccess').write_text('Options -Indexes\n<FilesMatch "(?i)\\.(php|phtml|phar|json|sql|log)$">\nRequire all denied\n</FilesMatch>\n')
(private / '.htaccess').write_text('Require all denied\n')
(public / '.htaccess').write_text('''# Initial release is deliberately inaccessible until the deployment checklist is complete.
Require all denied
# Replace only the initial deny with independently verified staging access protection.
DirectoryIndex index.html
Options -Indexes
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=302,L]
</IfModule>
<FilesMatch "(?i)(^\\.|\\.(sql|bak|log|ini|env|toml|json|lock)$)">
Require all denied
</FilesMatch>
<IfModule mod_headers.c>
Header always set X-Content-Type-Options nosniff
Header always set Referrer-Policy same-origin
Header always set X-Robots-Tag "noindex, nofollow, noarchive"
<FilesMatch "\\.(html|php)$">
Header set Cache-Control "no-store"
</FilesMatch>
</IfModule>
''')
(public / '.user.ini').write_text('display_errors=Off\nlog_errors=On\nexpose_php=Off\nsession.use_strict_mode=1\nsession.cookie_httponly=1\nsession.cookie_secure=1\nsession.cookie_samesite=Lax\nupload_max_filesize=5M\npost_max_size=8M\n')
(public / 'robots.txt').write_text('User-agent: *\nDisallow: /\n')
(private / 'SETUP.txt').write_text('Copy config.example.php to config.php privately; enter actual hPanel values. Rotate both imported account passwords with rotate-credentials.php before opening access. Do not put this directory inside public_html. Keep SMS simulation-only.\n')
files = []
for file in sorted(package.rglob('*')):
    if not file.is_file():
        continue
    relative = str(file.relative_to(package))
    assert not any(x in relative for x in ['db-local.php', 'semaphore-api-key.php', '.git/', '.sql', '.claim', 'server.log'])
    if file.suffix in ['.php', '.js', '.html', '.css', '.ini']:
        content = file.read_text(errors='replace')
        assert '/Users/macbookprom116512' not in content and '/Applications/MAMP' not in content, relative
    files.append({'path': relative, 'bytes': file.stat().st_size, 'sha256': hashlib.sha256(file.read_bytes()).hexdigest()})
    file.chmod(0o600)
for directory in package.rglob('*'):
    if directory.is_dir():
        directory.chmod(0o700)
(release / 'application-manifest.json').write_text(json.dumps({'sql_sha256': verification['sql_sha256'], 'files': files, 'refined_images': assets}, indent=2)+'\n')
print('Built closed-by-default application candidate:', len(files), 'files,', len(assets), 'refined images. No private source configuration copied.')
