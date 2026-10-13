"""Safety checks which never execute a demo reset or production migrations."""
from pathlib import Path
import os
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class OperationalSafetyTest(unittest.TestCase):
    def run_command(self, args, **kwargs):
        return subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=30, **kwargs)

    def test_restore_help_exits_before_runtime_work(self):
        r = self.run_command(['bash', 'restore.sh', '--help'])
        self.assertEqual(0, r.returncode)
        self.assertIn('runtime ONLY', r.stdout)

    def test_restore_requires_both_reset_flags(self):
        for flag in ['--reset-demo', '--confirm-database=epcc', '--typo']:
            with self.subTest(flag=flag):
                r = self.run_command(['bash', 'restore.sh', flag])
                self.assertEqual(2, r.returncode)
                self.assertNotIn('Installing', r.stdout)

    def test_reset_refuses_no_confirmation(self):
        r = self.run_command(['php', 'scripts/reset-demo.php'])
        self.assertEqual(2, r.returncode)
        self.assertIn('Refusing reset', r.stderr)

    def test_reset_refuses_mismatched_database_even_in_check_mode(self):
        r = self.run_command(['php', 'scripts/reset-demo.php', '--confirm-database=not_the_database', '--check-only'],
                             env={**os.environ, 'APP_ENV': 'local', 'DB_DATABASE': 'epcc_test'})
        self.assertEqual(2, r.returncode)
        self.assertIn('Refusing reset', r.stderr)

    def test_reset_refuses_production(self):
        r = self.run_command(['php', 'scripts/reset-demo.php', '--confirm-database=epcc_test', '--check-only'],
                             env={**os.environ, 'APP_ENV': 'production', 'DB_DATABASE': 'epcc_test'})
        self.assertEqual(2, r.returncode)
        self.assertIn('Refusing reset', r.stderr)

    def test_confirmed_local_check_only_is_non_destructive(self):
        r = self.run_command(['php', 'scripts/reset-demo.php', '--confirm-database=epcc_test', '--check-only'],
                             env={**os.environ, 'APP_ENV': 'local', 'DB_DATABASE': 'epcc_test'})
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertIn('CHECK ONLY; no database changes', r.stdout)

    def test_missing_jsonschema_is_failure_not_success(self):
        # -S hides installed site packages, reproducing an unprovisioned host.
        r = self.run_command([sys.executable, '-S', 'standards-packs/validate_packs.py'])
        self.assertNotEqual(0, r.returncode)
        self.assertIn('schema validation is REQUIRED', r.stdout)
        self.assertNotIn('All 3 standards packs', r.stdout)

    def test_bootstrap_preserves_application_documents_and_does_not_follow_links(self):
        documents = ROOT / 'app/storage/app/private/documents'
        documents.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='audit-sentinel-', dir=documents) as directory:
            sentinel = Path(directory) / 'keep.txt'
            sentinel.write_text('must survive')
            program = ("require 'app/tests/bootstrap.php'; "
                       "$root = $_ENV['LARAVEL_STORAGE_PATH']; "
                       "file_put_contents($root.'/app/private/test.txt', 'temporary'); "
                       "symlink($argv[1], $root.'/outside-documents'); echo $root;")
            r = self.run_command(['php', '-r', program, str(directory)])
            self.assertEqual(0, r.returncode, r.stderr)
            self.assertEqual('must survive', sentinel.read_text())
            self.assertFalse(Path(r.stdout).exists(), 'test storage must be cleaned at exit')

    def test_non_test_database_is_refused_before_fixture_migrations(self):
        r = subprocess.run(
            ['php', 'vendor/bin/phpunit', '--colors=never', '--filter',
             'BaselineIsolationTest::test_missing_tenant', '--stop-on-error'],
            cwd=ROOT / 'app', env={**os.environ, 'DB_DATABASE': 'epcc_do_not_touch', 'DB_URL': ''},
            capture_output=True, text=True, timeout=30)
        self.assertNotEqual(0, r.returncode)
        self.assertIn('Refusing destructive fixtures', r.stdout)

    def test_full_runner_does_not_claim_success_with_missing_prerequisites(self):
        with tempfile.TemporaryDirectory(prefix='epcc-empty-path-') as directory:
            # dirname is the sole external prerequisite needed to reach the
            # runner's preflight; deliberately hide PHP/Python/Node.
            os.symlink('/usr/bin/dirname', Path(directory) / 'dirname')
            r = self.run_command(['/bin/bash', 'prototype/test/run-all.sh'],
                                 env={**os.environ, 'PATH': directory})
            self.assertEqual(2, r.returncode)
            self.assertIn('INCOMPLETE', r.stderr)
            self.assertNotIn('SUITES PASSED', r.stdout)

    def test_shards_reject_non_test_or_duplicate_database_names(self):
        for names in ['epcc', 'epcc_test_a,epcc_test_a']:
            r = self.run_command([sys.executable, 'scripts/run-test-shards.py', '--databases', names, '--output', '/tmp/unused-epcc-test-output'])
            self.assertEqual(2, r.returncode)

if __name__ == '__main__':
    unittest.main()
