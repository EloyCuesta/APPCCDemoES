#!/usr/bin/env python3
"""Provision the first administrator through existing APPCC domain endpoints."""
import argparse
import getpass
import json
from pathlib import Path
from api import internal_request


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("profile", type=Path, help="Private JSON with entidadFiscal, establecimiento, administrador")
    args = parser.parse_args()
    profile = json.loads(args.profile.read_text(encoding="utf-8"))
    password = getpass.getpass("Administrator password (12–72 characters): ")
    if not 12 <= len(password.encode("utf-8")) <= 72:
        raise SystemExit("Use a password between 12 and 72 UTF-8 bytes.")
    if getpass.getpass("Repeat password: ") != password:
        raise SystemExit("Passwords differ. No account was created.")
    account = internal_request("/api/onboarding", profile)
    internal_request("/api/auth/configurar-password", {
        "token": account["tokenConfiguracionPassword"],
        "password": password, "passwordConfirmation": password,
    }, expected=204)
    print(f"Administrator ready for establishment {account['establecimientoId']}. Sign in through HTTPS.")


if __name__ == "__main__":
    main()
