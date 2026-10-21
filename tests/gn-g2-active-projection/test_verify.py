#!/usr/bin/env python3
"""Active wrapper safety tests without Docker or Kubernetes access."""
from pathlib import Path
import types
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).with_name('verify')
runner = types.ModuleType('active_runner')
runner.__file__ = str(SOURCE)
exec(compile(SOURCE.read_text(), str(SOURCE), 'exec'), runner.__dict__)


class ActiveWrapperSafetyTests(unittest.TestCase):
    def test_node_image_pull_requires_owned_identity_first(self):
        with patch.object(runner.base.runner, 'check_identity', side_effect=RuntimeError('wrong cluster')):
            with patch.object(runner.subprocess, 'run') as command:
                with self.assertRaises(RuntimeError):
                    runner.fixture_image(Path('/tmp/unused'), {'node': 'canonical-forbidden'})
                command.assert_not_called()

    def test_fixture_uses_exact_owned_node_and_pinned_harmless_digest(self):
        with patch.object(runner.base.runner, 'check_identity') as check:
            with patch.object(runner.subprocess, 'run') as command:
                data = {'node': 'k3d-gn-g2-proof-0123456789ab-server-0'}
                runner.fixture_image(Path('/tmp/unused'), data)
                check.assert_called_once()
                args = command.call_args.args[0]
                self.assertEqual(args[:3], ['docker', 'exec', data['node']])
                self.assertEqual(args[-1], runner.PAUSE)
                self.assertIn('@sha256:', args[-1])

    def test_provider_images_are_explicit_digest_identities(self):
        self.assertEqual(set(runner.IMAGES), {'asterisk', 'freeswitch'})
        for image in runner.IMAGES.values():
            self.assertRegex(image, r'^127\.0\.0\.1:5001/utcp/[a-z-]+@sha256:[a-f0-9]{64}$')


if __name__ == '__main__':
    unittest.main()
