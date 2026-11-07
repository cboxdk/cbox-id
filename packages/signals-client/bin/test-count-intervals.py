#!/usr/bin/env python3
"""Real PHP SDK -> engine HTTP -> isolated PostgreSQL acceptance, never production."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--engine', type=Path, required=True)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
engine = args.engine.resolve()
if not os.environ.get('SIGNALS_TEST_DATABASE_URL'):
    raise SystemExit('Provide a disposable loopback PostgreSQL DSN in SIGNALS_TEST_DATABASE_URL.')
if args.output.exists():
    raise SystemExit('Refusing to replace an existing acceptance receipt.')
subprocess.run(['git', 'diff', '--quiet', 'HEAD', '--'], cwd=engine, check=True)
subprocess.run(['git', 'check-ignore', '-q', 'artifacts/sdk-interval-fixture'], cwd=engine, check=True)
revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=engine, text=True).strip()
template = root / 'tests/Integration/interval-fixture.go.txt'
artifacts = engine / 'artifacts'
artifacts.mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='sdk-interval-', dir=artifacts) as temporary:
    source = Path(temporary) / 'main.go'
    source.write_bytes(template.read_bytes())
    result = subprocess.run(['go', 'run', str(source)], cwd=engine,
                            env={**os.environ, 'SIGNALS_TEST_SDK_ROOT': str(root)},
                            text=True, capture_output=True, timeout=180)
    if result.returncode:
        raise SystemExit(result.stderr)
    receipt = json.loads(result.stdout)
    if receipt.get('passed') is not True:
        raise SystemExit('Acceptance did not pass.')
    files = sorted([*root.glob('src/**/*.php'), *root.glob('tests/Integration/*')])
    digest = hashlib.sha256()
    for file in files:
        digest.update(str(file.relative_to(root)).encode() + b'\0' + file.read_bytes())
    receipt.update(checked_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
                   engine_revision=revision, sdk_source_sha256=digest.hexdigest(),
                   fixture_sha256=hashlib.sha256(template.read_bytes()).hexdigest())
    args.output.write_text(json.dumps(receipt, indent=2) + '\n')
    print(json.dumps(receipt))
