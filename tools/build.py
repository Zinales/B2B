"""
Build the wb-core upload zip — only if every guardrail passes.

    python tools/build.py                     build DEPLOY/wb-core-<version>.zip
    python tools/build.py --check             run every gate, build nothing
    python tools/build.py --accept-inventory  record the current inventory as the new baseline
    python tools/build.py --allow-dirty       build from uncommitted changes (testing only)

The gates, in order. Any failure stops the build and nothing is written.
  1. Syntax:     php -l on every PHP file in the plugin.
  2. Tests:      every tests/*.php must exit 0 (selftest + every regress-*.php).
  3. Inventory:  every shortcode, REST route, capability, role, default option and data
                 field in tools/inventory-baseline.json must still exist. Removing one is
                 how a working part gets undone; it has to be a deliberate decision
                 (--accept-inventory, recorded in git), never an accident.
  4. Versions:   the plugin header, WB_VERSION and the top CHANGELOG entry agree.
  5. Source:     the git working tree is clean, so every zip maps to one commit.
  6. No reuse:   a zip for this version must not already exist. A change means a new version.
"""
import json
import os
import re
import subprocess
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'plugin-source', 'wb-core')
DEPLOY = os.path.join(ROOT, 'DEPLOY')
BASELINE = os.path.join(ROOT, 'tools', 'inventory-baseline.json')
PHP = os.environ.get('WB_PHP') or (r'C:/Users/zinae/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe' if os.name == 'nt' else 'php')
EXCLUDE_DIRS = {'tests', '.git', '__pycache__'}


def fail(msg):
    print('\nBUILD STOPPED: ' + msg)
    sys.exit(1)


def run(args, cwd=None):
    p = subprocess.run(args, cwd=cwd, capture_output=True, text=True, encoding='utf-8', errors='replace')
    return p.returncode, (p.stdout or '') + (p.stderr or '')


def php_files():
    for root, dirs, files in os.walk(PLUGIN):
        dirs[:] = [d for d in dirs if d not in {'.git'}]
        for f in files:
            if f.endswith('.php'):
                yield os.path.join(root, f)


def gate_syntax():
    bad = []
    n = 0
    for f in php_files():
        n += 1
        code, out = run([PHP, '-l', f])
        if code != 0:
            bad.append(out.strip())
    if bad:
        fail('syntax errors:\n' + '\n'.join(bad))
    print(f'1. Syntax      {n} PHP files clean')


def gate_tests():
    tdir = os.path.join(PLUGIN, 'tests')
    tests = sorted(f for f in os.listdir(tdir) if f.endswith('.php'))
    if not tests:
        fail('no tests found')
    for t in tests:
        code, out = run([PHP, os.path.join(tdir, t)], cwd=PLUGIN)
        last = [l for l in out.strip().splitlines() if l.strip()][-1:] or ['(no output)']
        if code != 0:
            fail(f'{t} failed:\n{out[-3000:]}')
        print(f'2. Tests       {t}: {last[0].strip()}')


def current_inventory():
    code, out = run([PHP, os.path.join(ROOT, 'tools', 'inventory.php')])
    if code != 0:
        fail('inventory could not load the plugin:\n' + out[-2000:])
    start = out.find('{')
    try:
        return json.loads(out[start:])
    except ValueError:
        fail('inventory output was not JSON:\n' + out[-2000:])


def gate_inventory(accept):
    inv = current_inventory()
    if accept:
        with open(BASELINE, 'w', encoding='utf-8', newline='\n') as fh:
            json.dump(inv, fh, indent=1, sort_keys=True)
            fh.write('\n')
        print('3. Inventory   baseline recorded: ' + ', '.join(f'{k} {len(v)}' for k, v in sorted(inv.items())))
        return
    if not os.path.exists(BASELINE):
        fail('no tools/inventory-baseline.json — run with --accept-inventory once')
    with open(BASELINE, encoding='utf-8') as fh:
        base = json.load(fh)
    missing = []
    for kind, items in base.items():
        now = set(inv.get(kind, []))
        for it in items:
            if it not in now:
                missing.append(f'{kind}: {it}')
    if missing:
        fail('these existed before and are gone (a working part was removed):\n  ' + '\n  '.join(missing)
             + '\nIf the removal is deliberate, run --accept-inventory and commit the baseline with a reason.')
    added = sum(len(set(inv.get(k, [])) - set(v)) for k, v in base.items())
    print(f'3. Inventory   nothing removed ({added} new item(s) since the baseline)')


def gate_versions():
    with open(os.path.join(PLUGIN, 'wb-core.php'), encoding='utf-8') as fh:
        src = fh.read()
    header = re.search(r'^\s*\*\s*Version:\s*([0-9.]+)', src, re.M)
    const = re.search(r"define\(\s*'WB_VERSION',\s*'([0-9.]+)'", src)
    with open(os.path.join(PLUGIN, 'CHANGELOG.md'), encoding='utf-8') as fh:
        log = re.search(r'^##\s*([0-9.]+)', fh.read(), re.M)
    vals = {'header': header and header.group(1), 'WB_VERSION': const and const.group(1), 'CHANGELOG': log and log.group(1)}
    if len(set(vals.values())) != 1 or None in vals.values():
        fail('versions disagree: ' + json.dumps(vals))
    print(f"4. Versions    {vals['header']} everywhere")
    return vals['header']


def gate_clean(allow_dirty):
    code, out = run(['git', 'status', '--porcelain'], cwd=ROOT)
    if code != 0:
        fail('not a git repository')
    if out.strip() and not allow_dirty:
        fail('uncommitted changes — commit first so this zip maps to one commit:\n' + out)
    code, head = run(['git', 'rev-parse', '--short', 'HEAD'], cwd=ROOT)
    print('5. Source      ' + ('UNCOMMITTED changes (testing build)' if out.strip() else 'clean at ' + head.strip()))
    return head.strip()


def build(version):
    os.makedirs(DEPLOY, exist_ok=True)
    out = os.path.join(DEPLOY, f'wb-core-{version}.zip')
    if os.path.exists(out):
        fail(f'{os.path.basename(out)} already exists — bump the version for any change')
    n = 0
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for root, dirs, files in os.walk(PLUGIN):
            dirs[:] = sorted(d for d in dirs if d not in EXCLUDE_DIRS)
            for f in sorted(files):
                full = os.path.join(root, f)
                rel = os.path.relpath(full, PLUGIN).replace(os.sep, '/')
                z.write(full, 'wb-core/' + rel)
                n += 1
    with zipfile.ZipFile(out) as z:
        names = z.namelist()
        assert 'wb-core/wb-core.php' in names and not any('\\' in x for x in names)
    print(f'6. Built       {os.path.relpath(out, ROOT)} ({n} files)')


def main():
    args = set(sys.argv[1:])
    gate_syntax()
    gate_tests()
    gate_inventory('--accept-inventory' in args)
    version = gate_versions()
    if '--check' in args or '--accept-inventory' in args:
        print('\nAll gates passed. Nothing built.')
        return
    gate_clean('--allow-dirty' in args)
    build(version)
    print('\nAll gates passed.')


if __name__ == '__main__':
    main()
