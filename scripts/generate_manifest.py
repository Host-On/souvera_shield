#!/usr/bin/env python3
"""Generate appinfo/manifest.json – the file-integrity manifest consumed by
lib/SetupChecks/FileIntegrityCheck.php.

Run AFTER `yarn build` (js/ hashes change every build). build_release.sh
does this automatically.
"""
import hashlib
import json
import os
import re
from datetime import date

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RUNTIME_DIRS = ('appinfo', 'lib', 'js', 'l10n', 'templates', 'img')
EXCLUDE = {'appinfo/manifest.json'}


def app_version() -> str:
    with open(os.path.join(ROOT, 'appinfo', 'info.xml'), encoding='utf-8') as fh:
        match = re.search(r'<version>([^<]+)</version>', fh.read())
    return match.group(1) if match else '0.0.0'


files = {}
for base in RUNTIME_DIRS:
    for dirpath, _dirnames, filenames in os.walk(os.path.join(ROOT, base)):
        for name in sorted(filenames):
            abspath = os.path.join(dirpath, name)
            rel = os.path.relpath(abspath, ROOT).replace(os.sep, '/')
            if rel in EXCLUDE or name.startswith('.'):
                continue
            with open(abspath, 'rb') as fh:
                files[rel] = hashlib.sha256(fh.read()).hexdigest()

manifest = {
    'version': app_version(),
    'generated': date.today().isoformat(),
    'files': dict(sorted(files.items())),
}
out = os.path.join(ROOT, 'appinfo', 'manifest.json')
with open(out, 'w', encoding='utf-8') as fh:
    json.dump(manifest, fh, indent=2)
    fh.write('\n')
print(f'manifest.json: v{manifest["version"]}, {len(files)} files')
