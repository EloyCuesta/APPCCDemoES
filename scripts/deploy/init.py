#!/usr/bin/env python3
"""Create private configuration once. Re-running never rotates existing secrets."""
import argparse
import os
from pathlib import Path
import re
import secrets


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("domain", help="DNS hostname pointing to the server; no scheme or path")
    parser.add_argument("email", help="Contact for the HTTPS certificate")
    args = parser.parse_args()
    domain = args.domain.lower()
    if not re.fullmatch(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}", domain):
        parser.error("Use a DNS hostname such as your-name.duckdns.org")
    if not re.fullmatch(r"[^\s'\"=]+@[^\s'\"=]+\.[^\s'\"=]+", args.email):
        parser.error("Use a valid certificate contact email")
    root = Path(__file__).resolve().parents[2]
    private = root / ".deploy"
    private.mkdir(mode=0o700, exist_ok=True)
    os.chmod(private, 0o700)
    config = private / "config.env"
    runtime = private / "runtime.env"
    if config.exists() or runtime.exists():
        if config.is_file() and runtime.is_file():
            print("Existing configuration preserved. Edit .deploy/config.env to change the hostname.")
            return
        raise SystemExit("Incomplete configuration. Restore the missing file; secrets were not overwritten.")
    os.umask(0o077)
    password = secrets.token_hex(32)
    config.write_text(f"APP_DOMAIN={domain}\nACME_EMAIL={args.email}\n", encoding="utf-8")
    # Generated hex secrets require no URL escaping. Never print their values.
    runtime.write_text(
        "APP_ENV=prod\nAPP_DEBUG=0\n"
        f"APP_SECRET={secrets.token_hex(32)}\nPOSTGRES_PASSWORD={password}\n"
        f'DATABASE_URL="postgresql://appcc:{password}@postgres:5432/appcc?serverVersion=18&charset=utf8"\n'
        f"DEFAULT_URI=https://{domain}\n"
        f"CORS_ALLOW_ORIGIN='^https://{re.escape(domain)}$'\n"
        "JWT_SECRET_KEY=/var/appcc/jwt/private.pem\nJWT_PUBLIC_KEY=/var/appcc/jwt/public.pem\n"
        f"JWT_PASSPHRASE={secrets.token_hex(32)}\n"
        "APPCC_EVIDENCIAS_DIR=/var/appcc/evidencias\n"
        "APPCC_EVIDENCIAS_MAX_BYTES=10485760\nAPPCC_EVIDENCIAS_TTL_SECONDS=3600\n",
        encoding="utf-8",
    )
    print("Private configuration created. No database or existing records were changed.")


if __name__ == "__main__":
    main()
