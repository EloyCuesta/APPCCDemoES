#!/usr/bin/env python3
"""CI-only production image smoke: real HTTP, JWT, private disk, rebuild and restore."""
import json
import os
from pathlib import Path
import secrets
import ssl
import subprocess
import urllib.error
import urllib.request
from api import ROOT, internal_request


def compose(*args, env=None, check=True, input=None):
    result = subprocess.run(["bash", "scripts/deploy/compose.sh", *args], cwd=ROOT,
                            env=env, input=input, text=True, capture_output=True)
    if check and result.returncode:
        # Never dump env interpolation or request/response bodies into Actions.
        raise RuntimeError("Production smoke Docker operation failed: " + " ".join(args[:3]))
    return result


def request(path, *, method="GET", data=None, token=None, tenant=None, binary=None, content_type=None):
    headers = {"Accept": "application/ld+json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if tenant:
        headers["X-Establecimiento-Id"] = str(tenant)
    body = binary
    if data is not None:
        body = json.dumps(data).encode()
        headers["Content-Type"] = "application/json" if path == "/api/login_check" else "application/ld+json"
    if content_type:
        headers["Content-Type"] = content_type
    req = urllib.request.Request("https://appcc.test" + path, data=body, headers=headers, method=method)
    # Only the CI-local Caddy certificate is self-signed; production uses ACME.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                        urllib.request.HTTPSHandler(context=ssl._create_unverified_context()))
    try:
        response = opener.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    payload = response.read()
    return response.status, payload


def main():
    if os.environ.get("CI") != "true":
        raise SystemExit("This writes synthetic data. Run it only in the isolated CI stack.")
    profile = json.loads((ROOT / "deploy/onboarding.example.json").read_text())
    password = secrets.token_urlsafe(24)
    account = internal_request("/api/onboarding", profile)
    assert request("/api/onboarding", method="POST", data=profile)[0] == 403
    assert request("/login")[0] == 200
    assert request("/api/me")[0] == 401
    internal_request("/api/auth/configurar-password", {
        "token": account["tokenConfiguracionPassword"], "password": password,
        "passwordConfirmation": password,
    }, expected=204)
    status, payload = request("/api/login_check", method="POST", data={
        "email": profile["administrador"]["email"], "password": password,
    })
    assert status == 200, "Production login failed"
    token = json.loads(payload)["token"]
    tenant = account["establecimientoId"]
    assert request("/api/me", token=token)[0] == 200
    assert request("/api/establecimientos/" + str(tenant), token=token, tenant=tenant)[0] == 200
    assert compose("exec", "-T", "backend", "php", "bin/console", "app:demo:seed", check=False).returncode != 0
    compose("exec", "-T", "backend", "php", "-r",
            'require "vendor/autoload.php"; exit(class_exists("PHPUnit\\\\Framework\\\\TestCase") ? 1 : 0);')
    image = (ROOT / "backend/tests/Fixtures/evidencia.png").read_bytes()
    boundary = "appcc" + secrets.token_hex(12)
    multipart = (
        f'--{boundary}\r\nContent-Disposition: form-data; name="tipo"\r\n\r\nfoto\r\n'
        f'--{boundary}\r\nContent-Disposition: form-data; name="archivo"; filename="smoke.png"\r\n'
        'Content-Type: image/png\r\n\r\n'
    ).encode() + image + f'\r\n--{boundary}--\r\n'.encode()
    assert request("/api/evidencias/subidas", method="POST", token=token, tenant=tenant,
                   binary=multipart, content_type="multipart/form-data; boundary=" + boundary)[0] == 201
    count_code = 'echo count(glob("/var/appcc/evidencias/temporal/*"));'
    assert int(compose("exec", "-T", "backend", "php", "-r", count_code).stdout) == 1
    compose("up", "-d", "--wait", "--no-deps", "--force-recreate", "backend")
    assert request("/api/me", token=token)[0] == 200, "JWT keys did not survive replacement"
    assert int(compose("exec", "-T", "backend", "php", "-r", count_code).stdout) == 1, "Photo did not persist"
    result = subprocess.run(["bash", "scripts/deploy/backup.sh"], cwd=ROOT, capture_output=True, text=True)
    assert result.returncode == 0, "Consistent backup failed"
    backups = sorted((ROOT / ".deploy/backups").glob("*/COMPLETE"))
    assert len(backups) == 1
    restored = "appcc_restore_ci"
    result = subprocess.run(["bash", "scripts/deploy/restore-fresh.sh", str(backups[0].parent), restored],
                            cwd=ROOT, capture_output=True, text=True)
    assert result.returncode == 0, "Isolated restoration failed"
    restored_env = dict(os.environ, APPCC_PROJECT_NAME=restored,
                        APPCC_DEPLOY_DIR=str(ROOT / ".deploy/restore" / restored))
    compose("up", "-d", "--wait", "backend", env=restored_env)
    restored_count = compose("exec", "-T", "backend", "php", "-r", count_code, env=restored_env)
    assert restored_count.stdout.strip() == "1", "Restored photo is absent"
    me = compose("exec", "-T", "backend", "curl", "-s", "-o", "/dev/null", "-w", "%{http_code}",
                 "--header", "@-", "http://localhost/api/me", env=restored_env,
                 input="Authorization: Bearer " + token + "\n")
    assert me.stdout == "200", "Restored database/JWT are inconsistent"
    # Guard against accidental destructive reuse of an existing restore target.
    again = subprocess.run(["bash", "scripts/deploy/restore-fresh.sh", str(backups[0].parent), restored],
                           cwd=ROOT, capture_output=True, text=True)
    assert again.returncode != 0
    codes = [request("/api/login_check", method="POST", data={"email": "absent@example.com", "password": "invalid"})[0]
             for _ in range(25)]
    assert 429 in codes, "Public authentication has no effective rate limit"
    print("Production smoke passed: HTTPS proxy, login/JWT, no-dev, demo guard, private photo persistence, backup/restore, rate limit.")


if __name__ == "__main__":
    main()
