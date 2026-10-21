#!/usr/bin/env python3
"""Safety checks without Docker, credentials, or Kubernetes access."""
import json
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).with_name('verify')
runner = types.ModuleType('proof_runner')
runner.__file__ = str(SOURCE)
exec(compile(SOURCE.read_text(), str(SOURCE), 'exec'), runner.__dict__)


class SafetyTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='utcp-gn-g2-real-', dir='/tmp')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.data = {'run': 'gn-g2-proof-0123456789ab',
                     'context': 'k3d-gn-g2-proof-0123456789ab',
                     'nonce': '0123456789ab' + '0' * 20, 'container_id': 'owned-id'}

    def test_only_explicit_temporary_directory(self):
        with self.assertRaises(RuntimeError):
            runner.load('/home/grimange/.kube')

    def test_reject_canonical_and_historical_names(self):
        for name in ['default', 'utcp-local', 'utcp-dev01', 'gn-g2-proof-existing']:
            with self.subTest(name=name):
                self.data['run'] = name
                runner.save(self.directory / 'identity.json', self.data)
                with self.assertRaises(RuntimeError):
                    runner.load(self.directory)

    def test_context_must_bind_exact_run(self):
        self.data['context'] = 'default'
        runner.save(self.directory / 'identity.json', self.data)
        with self.assertRaises(RuntimeError):
            runner.load(self.directory)

    def test_container_label_and_immutable_id_required(self):
        good = {'Id': 'owned-id', 'Config': {'Labels': {
            'utcp.gn-g2.run': self.data['nonce'], 'k3d.cluster': self.data['run']}}}
        with patch.object(runner, 'command', return_value=json.dumps(good)):
            self.assertEqual(runner.owned(self.data), 'owned-id')
        for change in ['nonce', 'cluster', 'container']:
            bad = json.loads(json.dumps(good))
            if change == 'nonce':
                bad['Config']['Labels']['utcp.gn-g2.run'] = 'other'
            elif change == 'cluster':
                bad['Config']['Labels']['k3d.cluster'] = 'utcp-local'
            else:
                bad['Id'] = 'replacement'
            with self.subTest(change=change), patch.object(runner, 'command', return_value=json.dumps(bad)):
                with self.assertRaises(RuntimeError):
                    runner.owned(self.data)

    def test_api_identity_must_match_before_execution(self):
        self.data['system_uid'] = 'expected'
        with patch.object(runner, 'owned'), patch.object(runner, 'kubectl', return_value='replacement'):
            with self.assertRaises(RuntimeError):
                runner.check_identity(self.directory, self.data)

    def test_kubectl_always_receives_explicit_credentials_and_context(self):
        with patch.object(runner, 'command', return_value='ok') as command:
            runner.kubectl(self.directory, self.data, 'get', 'namespace', 'kube-system')
            args = command.call_args.args[0]
            self.assertEqual(args[:5], ['kubectl', '--kubeconfig', str(self.directory / 'kubeconfig'),
                                       '--context', self.data['context']])


if __name__ == '__main__':
    unittest.main()
