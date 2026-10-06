"""Internal API access over Docker/SSH; credentials only travel on stdin."""
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]


def internal_request(path, body, expected=201):
    command = ["bash", str(ROOT / "scripts/deploy/compose.sh"), "exec", "-T", "backend",
               "curl", "--silent", "--show-error", "--request", "POST",
               "--header", "Content-Type: application/ld+json", "--header", "Accept: application/ld+json",
               "--data-binary", "@-", "--write-out", "\n%{http_code}", "http://localhost" + path]
    result = subprocess.run(command, input=json.dumps(body), text=True, capture_output=True, cwd=ROOT)
    if result.returncode:
        raise RuntimeError("Internal API transport failed; inspect private container logs.")
    payload, status = result.stdout.rsplit("\n", 1)
    if int(status) != expected:
        raise RuntimeError(f"Internal API returned HTTP {status} for {path}; no response body was logged.")
    return json.loads(payload) if payload.strip() else None
