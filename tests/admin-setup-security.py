"""Offline checks: the retired SQL seed cannot create or reset an account."""
from pathlib import Path
import re
root = Path(__file__).resolve().parent.parent
seed = (root / "sql/admin-account.sql").read_text()
assert all(not line.strip() or line.lstrip().startswith("--") for line in seed.splitlines())
assert not re.search(r"\$2[aby]\$[0-9]{2}\$", seed)
assert "scripts/create-local-admin.php" in seed
assert "HOSTINGER-NO-SSH-ACTIVATION.md" in seed
helper = (root / "scripts/create-local-admin.php").read_text()
assert "password_hash($password, PASSWORD_DEFAULT)" in helper
assert "That administrator already exists" in helper
assert "ON DUPLICATE KEY" not in helper.upper()
print("Passed 7 offline administrator-setup safety checks.")
