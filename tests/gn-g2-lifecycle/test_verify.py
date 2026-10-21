#!/usr/bin/env python3
"""Guard the narrow lifecycle mutation allowance without infrastructure access."""
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'components/kubernetes-effects/verify-static'


class IsolationGuardTests(unittest.TestCase):
    def check(self, path, expression):
        with tempfile.TemporaryDirectory(prefix='utcp-lifecycle-static-', dir='/tmp') as tmp:
            root = Path(tmp)
            for directory in ['components/kubernetes-effects', 'tests/gn-g2-effects',
                              'docs/architecture/proposals/gn-g2']:
                (root / directory).mkdir(parents=True)
            report = root / 'docs/architecture/proposals/UTCP_GN_G2_CONDITIONAL_KUBERNETES_DEPLOYMENT_EFFECTS.md'
            report.write_text('Disposable checker fixture.\n')
            checker = root / SOURCE.relative_to(ROOT)
            checker.write_text(SOURCE.read_text())
            target = root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('package lifecycle\nfunc fixture() { ' + expression + ' }\n')
            return subprocess.run([sys.executable, str(checker)], capture_output=True, text=True)

    def test_only_two_owned_files_allow_create_delete_transport(self):
        for name in ['engine.go', 'kubernetes.go']:
            result = self.check('components/kubernetes-effects/lifecycle/' + name, 'client.Post(); client.Delete()')
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_other_paths_cannot_gain_mutation_authority(self):
        for path in ['components/kubernetes-effects/engine.go',
                     'components/kubernetes-effects/lifecycle/transport.go',
                     'components/kubernetes-effects/other/kubernetes.go']:
            result = self.check(path, 'client.Post(); client.Delete()')
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('isolation mutation boundary', result.stderr)

    def test_allowed_files_still_reject_unbounded_authorities(self):
        for expression in ['client.Put()', 'client.Watch()', 'database/sql',
                           'InClusterConfig()', 'ListenAndServe()']:
            result = self.check('components/kubernetes-effects/lifecycle/kubernetes.go', expression)
            self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
