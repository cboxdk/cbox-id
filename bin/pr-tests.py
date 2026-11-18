#!/usr/bin/env python3
"""Pick the tests a pull request touches, for CI's `pr-tests` and `changes` jobs (.github/workflows/ci.yml).

Reads the paths the pull request changes, one per line on stdin (`git diff --name-only`), and
prints the test files to run, one per line:

  - every test file the pull request edits;
  - every test file that names a class the pull request edits, by its fully qualified name
    (a `use` line, `::class`, or a string), with the class found from the PSR-4 maps in
    composer.json and packages/*/composer.json, so a deleted class still counts;
  - every test file named after an edited class (`Foo.php` -> `FooTest.php`);
  - every test file that names an edited fixture under tests/Fixtures by its file name;
  - ALWAYS: the drift guards below, which hold generated files (OpenAPI, the actions reference,
    the deployment manifest, the docs' pictures, hosted-page languages) to the code. They take
    seconds and are what `lint` cannot see.

Only tests main runs are candidates: tests/Unit, tests/Feature and the one Integration test
CI runs on Postgres. Browser tests are not picked here; the `browser` job runs all of them
when `--frontend` says the pull request touches the front end.

  git diff --name-only BASE HEAD | python3 bin/pr-tests.py                       # test files
  git diff --name-only BASE HEAD | python3 bin/pr-tests.py --phpunit pr.xml      # and a config
  git diff --name-only BASE HEAD | python3 bin/pr-tests.py --frontend            # true / false
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

GUARDS = [
    'tests/Feature/Actions/OpenApiBuildTest.php',
    'tests/Feature/Actions/ActionsReferenceTest.php',
    'tests/Feature/DeploymentManifestTest.php',
    'tests/Feature/DocsScreenshotsCatalogueTest.php',
    'tests/Feature/HostedPageLanguageTest.php',
]

# What the browser suite draws or drives: pages, styles, copy, the build, and the suite itself.
FRONTEND = re.compile(
    r'^(resources/(js|css|views)/|lang/|public/|tests/Browser/|tests/\.pest/snapshots/'
    r'|package(-lock)?\.json$|vite\.config\.ts$|tsconfig\.json$)'
)


def candidates() -> list[str]:
    files = sorted(
        str(p.relative_to(ROOT))
        for d in ('tests/Unit', 'tests/Feature')
        for p in (ROOT / d).rglob('*Test.php')
    )
    return files + ['tests/Integration/SourceCommitOrderTest.php']


def psr4() -> list[tuple[str, str]]:
    """(directory prefix, namespace prefix), longest directory first."""
    maps: list[tuple[str, str]] = []
    for manifest in [ROOT / 'composer.json', *sorted(ROOT.glob('packages/*/composer.json'))]:
        base = manifest.parent.relative_to(ROOT)
        for ns, dirs in json.loads(manifest.read_text()).get('autoload', {}).get('psr-4', {}).items():
            for d in [dirs] if isinstance(dirs, str) else dirs:
                prefix = (base / d).as_posix().rstrip('/') + '/'
                maps.append((prefix.removeprefix('./'), ns))
    return sorted(maps, key=lambda m: -len(m[0]))


def class_of(path: str, maps: list[tuple[str, str]]) -> str | None:
    if not path.endswith('.php'):
        return None
    for prefix, ns in maps:
        if path.startswith(prefix):
            return ns + path[len(prefix):-4].replace('/', '\\')
    if path.startswith('tests/') and '/' in path[6:]:  # Tests\Support\…, not a test file itself
        return 'Tests\\' + path[6:-4].replace('/', '\\')
    return None


def select(changed: list[str]) -> list[str]:
    tests = candidates()
    pool = set(tests)
    picked = {p for p in changed if p in pool} | set(GUARDS)

    maps = psr4()
    classes = {c for p in changed if p not in pool and (c := class_of(p, maps))}
    names = {c.rsplit('\\', 1)[-1] + 'Test.php' for c in classes}
    fixtures = {Path(p).name for p in changed if p.startswith('tests/Fixtures/')}

    if classes:
        named = re.compile(
            r'(?<![\w\\])\\?(' + '|'.join(re.escape(c) for c in sorted(classes)) + r')(?![\w\\])'
        )
    for test in tests:
        if test in picked:
            continue
        if Path(test).name in names:
            picked.add(test)
            continue
        if not classes and not fixtures:
            continue
        source = (ROOT / test).read_text(errors='replace').replace('\\\\', '\\')
        if (classes and named.search(source)) or any(f in source for f in fixtures):
            picked.add(test)

    return sorted(p for p in picked if (ROOT / p).is_file())


def write_config(target: str, tests: list[str]) -> None:
    """phpunit.xml with its suites replaced by the picked files: `PR` for the parallel run and
    `Integration` for the Postgres test that runs in one process, as on main. Pest's
    `--parallel` takes one path at most, so the files reach it through a suite."""
    def suite(name: str, files: list[str]) -> str:
        body = ''.join(f'\n            <file>{f}</file>' for f in files)
        return f'\n        <testsuite name="{name}">{body}\n        </testsuite>'

    serial = [t for t in tests if t.startswith('tests/Integration/')]
    parallel = [t for t in tests if t not in serial]
    suites = '<testsuites>' + suite('PR', parallel) + suite('Integration', serial) + '\n    </testsuites>'
    config = re.sub(r'<testsuites>.*?</testsuites>', suites, (ROOT / 'phpunit.xml').read_text(), flags=re.S)
    (ROOT / target).write_text(config)


def main() -> None:
    changed = [line.strip() for line in sys.stdin if line.strip()]
    if sys.argv[1:] == ['--frontend']:
        print('true' if any(FRONTEND.match(p) for p in changed) else 'false')
        return
    tests = select(changed)
    if sys.argv[1:2] == ['--phpunit']:
        write_config(sys.argv[2], tests)
    print('\n'.join(tests))


if __name__ == '__main__':
    main()
