import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path

spec = importlib.util.spec_from_file_location('build', Path(__file__).resolve().parents[1] / 'scripts/build.py')
build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build)


class PackageTest(unittest.TestCase):
    def test_packages_are_standalone_and_reproducible(self):
        for variant in ('callback', 'api'):
            with self.subTest(variant=variant), tempfile.TemporaryDirectory() as one, tempfile.TemporaryDirectory() as two:
                values, files = build.package_files(variant)
                first, digest = build.build(variant, Path(one))
                second, again = build.build(variant, Path(two))
                self.assertEqual(digest, again)
                self.assertIn(values['BUNDLE'] + '.php', files)
                self.assertIn('composer.json', files)
                self.assertIn('Model/DncFeedback.php', files)
                self.assertEqual('Mailer/Transport/MailganerApiTransport.php' in files, variant == 'api')
                with zipfile.ZipFile(first) as z:
                    self.assertEqual(len(z.namelist()), len(files))
                    self.assertTrue(all(p.startswith(values['BUNDLE'] + '/') for p in z.namelist()))
                    self.assertFalse(any('src/' in p or 'vendor/' in p for p in z.namelist()))

    def test_callback_implementation_has_one_source(self):
        relative = Path('EventSubscriber/CallbackSubscriber.php')
        self.assertTrue((build.ROOT / 'src/shared' / relative).is_file())
        for variant in ('api', 'callback'):
            self.assertFalse((build.ROOT / 'src' / variant / relative).exists())

    def test_unknown_tokens_fail_closed(self):
        with self.assertRaises(ValueError):
            build.render('__UNKNOWN__', {})
        self.assertEqual('__DIR__', build.render('__DIR__', {}))


if __name__ == '__main__':
    unittest.main()
