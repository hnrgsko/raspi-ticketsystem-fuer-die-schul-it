#!/usr/bin/env python3
"""Repository policy checks for the portable Schul-IT appliance.

These checks intentionally use only the Python standard library so they can run
in CI and on a maintainer workstation without additional dependencies.
"""
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "database" / "migrations"


def fail(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def check_migrations() -> None:
    files = sorted(MIGRATIONS.glob("*.sql"))
    if not files:
        fail("No database migrations found.")

    seen_numbers: set[str] = set()
    for path in files:
        version = path.stem
        match = re.fullmatch(r"(\d{3})_[A-Za-z0-9_-]+", version)
        if match is None:
            fail(f"Invalid migration filename: {path.relative_to(ROOT)}")

        number = match.group(1)
        if number in seen_numbers:
            fail(f"Duplicate migration number: {number}")
        seen_numbers.add(number)

        sql = path.read_text(encoding="utf-8")
        registration = re.compile(
            r"INSERT\s+IGNORE\s+INTO\s+schema_migrations\s*"
            r"\(\s*version\s*\)\s*VALUES\s*\(\s*['\"]"
            + re.escape(version)
            + r"['\"]\s*\)",
            re.IGNORECASE | re.MULTILINE,
        )
        if registration.search(sql) is None:
            fail(
                f"Migration {version} does not register its exact version "
                "in schema_migrations."
            )


def _prepared_sql_literals(source: str):
    # The application currently keeps prepared SQL statements in literal
    # single- or double-quoted PHP strings. This deliberately catches the
    # native-PDO failure mode HY093 we hit during development.
    patterns = (
        re.compile(r"->prepare\(\s*'((?:\\.|[^'\\])*)'\s*\)", re.DOTALL),
        re.compile(r'->prepare\(\s*"((?:\\.|[^"\\])*)"\s*\)', re.DOTALL),
    )
    for pattern in patterns:
        for match in pattern.finditer(source):
            yield match.group(1)


def check_duplicate_pdo_parameters() -> None:
    for path in sorted((ROOT / "application").rglob("*.php")):
        source = path.read_text(encoding="utf-8")
        for sql in _prepared_sql_literals(source):
            params = re.findall(r":([A-Za-z_][A-Za-z0-9_]*)", sql)
            duplicates = sorted({p for p in params if params.count(p) > 1})
            if duplicates:
                fail(
                    f"{path.relative_to(ROOT)} reuses named PDO parameter(s) "
                    f"{', '.join(duplicates)} in one prepared statement. "
                    "Native PDO prepares require unique placeholders."
                )


def check_required_security_headers() -> None:
    application = (ROOT / "installer" / "application.sh").read_text(encoding="utf-8")
    required = (
        'X-Content-Type-Options',
        'X-Frame-Options',
        'Referrer-Policy',
        'Permissions-Policy',
        'Content-Security-Policy',
    )
    missing = [header for header in required if header not in application]
    if missing:
        fail("Application vhost is missing security header(s): " + ", ".join(missing))


def check_dev_updater_dependency() -> None:
    update_service = (ROOT / "installer" / "update-service.sh").read_text(encoding="utf-8")
    if "Requires=mariadb.service" in update_service:
        fail(
            "Development updater must not Require=mariadb.service; "
            "the installer deliberately restarts MariaDB during updates."
        )


def check_no_school_specific_branding() -> None:
    # Portable repository must not silently become tied to the GSK production
    # instance. Documentation may discuss generic examples, but runtime files
    # must remain school-neutral.
    runtime_roots = [
        ROOT / "application",
        ROOT / "installer",
        ROOT / "system",
        ROOT / "setup",
    ]
    forbidden = (
        "Gesamtschule Konradsdorf",
        "support.harzenetter.eu",
        "k195297_tickets",
    )
    for root in runtime_roots:
        if not root.exists():
            continue
        for path in root.rglob("*"):
            if not path.is_file():
                continue
            try:
                text = path.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                continue
            for value in forbidden:
                if value in text:
                    fail(
                        f"Portable runtime contains school-specific value "
                        f"{value!r} in {path.relative_to(ROOT)}."
                    )


def main() -> None:
    check_migrations()
    check_duplicate_pdo_parameters()
    check_required_security_headers()
    check_dev_updater_dependency()
    check_no_school_specific_branding()
    print("Repository policy checks passed.")


if __name__ == "__main__":
    main()
