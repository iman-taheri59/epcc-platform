#!/usr/bin/env python3
"""Run the whole PHP suite on explicitly named, existing isolated test DBs.
Does not create/drop databases. Each shard's fixtures DO reset its database.
Usage: python3 scripts/run-test-shards.py --databases epcc_test_1,epcc_test_2 --output /path/to/results
"""
import argparse
import concurrent.futures
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
p = argparse.ArgumentParser(description=__doc__)
p.add_argument('--databases', required=True)
p.add_argument('--output', required=True)
a = p.parse_args()
databases = a.databases.split(',')
if len(set(databases)) != len(databases) or not all(re.fullmatch(r'epcc_test_[a-zA-Z0-9_]+', x) for x in databases):
    p.error('Use unique epcc_test_* databases: their contents will be replaced by fixtures.')
output = Path(a.output).resolve()
output.mkdir(parents=True, exist_ok=True)
files = sorted((ROOT / 'app/tests').glob('*/*Test.php'))
# Keep each test class intact, balancing by approximate test-method count.
weighted = sorted(((len(re.findall(r'function test_', f.read_text())), f) for f in files), reverse=True)
groups = [[] for _ in databases]
weights = [0] * len(databases)
for weight, f in weighted:
    index = min(range(len(groups)), key=lambda i: weights[i])
    groups[index].append(f)
    weights[index] += max(weight, 1)
(output / 'assignment.json').write_text(json.dumps({db: [str(f.relative_to(ROOT)) for f in group] for db, group in zip(databases, groups)}, indent=2))

def run(index):
    db = databases[index]
    with tempfile.TemporaryDirectory(prefix='epcc-shard-') as tmp:
        tree = ET.parse(ROOT / 'app/phpunit.xml')
        config = tree.getroot()
        config.set('bootstrap', str(ROOT / 'app/tests/bootstrap.php'))
        suites = config.find('testsuites')
        suites.clear()
        suite = ET.SubElement(suites, 'testsuite', name=db)
        for f in sorted(groups[index]):
            ET.SubElement(suite, 'file').text = str(f)
        for directory in config.findall('./source/include/directory'):
            directory.text = str(ROOT / 'app' / directory.text)
        for env in config.findall('./php/env'):
            if env.get('name') == 'DB_DATABASE':
                env.set('value', db)
                env.set('force', 'true')
            if env.get('name') in ('DB_URL', 'APP_ENV', 'DB_CONNECTION'):
                env.set('force', 'true')
        path = Path(tmp) / 'phpunit.xml'
        tree.write(path, encoding='utf-8', xml_declaration=True)
        with (output / (db + '.log')).open('w') as log:
            result = subprocess.run(['php', 'vendor/bin/phpunit', '--configuration', str(path), '--colors=never', '--do-not-cache-result', '--log-junit', str(output / (db + '.xml'))], cwd=ROOT / 'app', stdout=log, stderr=subprocess.STDOUT)
        print(f'{db}: exit {result.returncode}', flush=True)
        return result.returncode

print(f'Running {len(files)} PHP test files across {len(databases)} isolated databases.', flush=True)
with concurrent.futures.ThreadPoolExecutor(max_workers=len(databases)) as pool:
    results = list(pool.map(run, range(len(databases))))
(output / 'exit-codes.json').write_text(json.dumps(dict(zip(databases, results)), indent=2))
raise SystemExit(1 if any(results) else 0)
