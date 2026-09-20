#!/usr/bin/env python3
from __future__ import annotations

import json
import sys

import update_core

try:
    result = update_core.check()
    print(json.dumps(result, ensure_ascii=False))
except Exception as exc:
    print(f"schulit-update-check: {exc}", file=sys.stderr)
    raise SystemExit(1)
