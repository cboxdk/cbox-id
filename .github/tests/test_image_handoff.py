import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('handoff', ROOT / '.github/scripts/image_handoff.py')
handoff = importlib.util.module_from_spec(spec)
spec.loader.exec_module(handoff)


def sha(letter):
    return 'sha256:'+letter*64


class FakeDocker:
    def __init__(self):
        self.env = {'IMAGE': 'registry.test/id', 'GITHUB_SHA': 'a'*40,
                    'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '2',
                    'DOCKER_METADATA_OUTPUT_JSON': json.dumps({'tags': ['registry.test/id:main']})}
        self.prefix = 'registry.test/id:build-'+'a'*40+'-123-2'
        self.records = []
        self.data = {}
        children = []
        for arch, parent, child in [('amd64', 'b', 'c'), ('arm64', 'd', 'e')]:
            entry = {'digest': sha(child), 'platform': {'os': 'linux', 'architecture': arch}}
            children.append(entry)
            manifest = {'digest': sha(parent), 'manifests': [entry]}
            self.data[(self.prefix+'-'+arch, 'Manifest')] = manifest
            self.data[('registry.test/id@'+sha(parent), 'Manifest')] = manifest
            self.data[('registry.test/id@'+sha(child), 'Image')] = {
                'os': 'linux', 'architecture': arch, 'config': {'Labels': {
                    'org.opencontainers.image.revision': 'a'*40,
                    'io.cbox.build.run-id': '123', 'io.cbox.build.run-attempt': '2'}}}
        self.data[(self.prefix+'-verified', 'Manifest')] = {'digest': sha('f'), 'manifests': children}
        self.data[('registry.test/id:sha-'+'a'*40, 'Manifest')] = self.data[(self.prefix+'-verified', 'Manifest')]

    def inspect(self, reference, field='Manifest'):
        return copy.deepcopy(self.data[(reference, field)])

    def create(self, tags, sources):
        self.records.append((tags, sources))


class ImageHandoffTest(unittest.TestCase):
    def test_publish_uses_run_scoped_reference_and_exact_built_digest(self):
        docker = FakeDocker()
        self.assertEqual(handoff.execute('publish', docker.env, docker, 'amd64', sha('b')), 'registry.test/id@'+sha('b'))
        self.assertEqual(docker.records, [([docker.prefix+'-amd64'], ['registry.test/id@'+sha('b')])])

    def test_merge_pins_both_architectures_and_verifies_before_promoting(self):
        docker = FakeDocker()
        self.assertEqual(handoff.execute('merge', docker.env, docker), 'registry.test/id@'+sha('f'))
        self.assertEqual(docker.records[0], ([docker.prefix+'-verified'], ['registry.test/id@'+sha('b'), 'registry.test/id@'+sha('d')]))
        self.assertEqual(docker.records[1][1], ['registry.test/id@'+sha('f')])

    def test_stale_commit_run_or_attempt_is_never_published(self):
        for label in ['org.opencontainers.image.revision', 'io.cbox.build.run-id', 'io.cbox.build.run-attempt']:
            docker = FakeDocker()
            docker.data[('registry.test/id@'+sha('c'), 'Image')]['config']['Labels'][label] = 'stale'
            with self.subTest(label=label), self.assertRaises(ValueError):
                handoff.execute('merge', docker.env, docker)
            self.assertEqual(docker.records, [])

    def test_wrong_or_missing_architecture_is_never_published(self):
        for mutation in ['wrong', 'missing', 'duplicate']:
            docker = FakeDocker()
            entries = docker.data[(docker.prefix+'-amd64', 'Manifest')]['manifests']
            if mutation == 'wrong':
                entries[0]['platform']['architecture'] = 'arm64'
            elif mutation == 'missing':
                entries.clear()
            else:
                entries.append(copy.deepcopy(entries[0]))
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                handoff.execute('merge', docker.env, docker)
            self.assertEqual(docker.records, [])

    def test_wrong_assembled_pair_cannot_promote_main_or_commit_tags(self):
        docker = FakeDocker()
        docker.data[(docker.prefix+'-verified', 'Manifest')]['manifests'].pop()
        with self.assertRaises(ValueError):
            handoff.execute('merge', docker.env, docker)
        self.assertEqual(len(docker.records), 1)
        self.assertEqual(docker.records[0][0], [docker.prefix+'-verified'])

    def test_attestations_must_refer_to_actual_images(self):
        docker = FakeDocker()
        manifest = docker.data[(docker.prefix+'-amd64', 'Manifest')]
        entry = {'digest': sha('f'), 'platform': {'os': 'unknown', 'architecture': 'unknown'},
                 'annotations': {'vnd.docker.reference.type': 'attestation-manifest', 'vnd.docker.reference.digest': sha('c')}}
        manifest['manifests'].append(entry)
        self.assertEqual(handoff.images(manifest), {'amd64': sha('c')})
        entry['annotations']['vnd.docker.reference.digest'] = sha('e')
        with self.assertRaises(ValueError):
            handoff.images(manifest)

    def test_wrong_image_config_and_build_digest_are_refused(self):
        docker = FakeDocker()
        docker.data[('registry.test/id@'+sha('c'), 'Image')]['architecture'] = 'arm64'
        with self.assertRaises(ValueError):
            handoff.execute('publish', docker.env, docker, 'amd64', sha('b'))
        self.assertEqual(docker.records, [])
        docker = FakeDocker()
        docker.data[('registry.test/id@'+sha('b'), 'Manifest')]['digest'] = sha('f')
        with self.assertRaises(ValueError):
            handoff.execute('publish', docker.env, docker, 'amd64', sha('b'))
        self.assertEqual(docker.records, [])

    def test_foreign_destination_tags_are_refused_before_promotion(self):
        docker = FakeDocker()
        docker.env['DOCKER_METADATA_OUTPUT_JSON'] = json.dumps({'tags': ['registry.test/foreign:main']})
        with self.assertRaises(ValueError):
            handoff.execute('merge', docker.env, docker)
        self.assertEqual(docker.records, [])

    def test_workflow_has_no_artifact_quota_dependency(self):
        source = (ROOT / '.github/workflows/build-image.yml').read_text()
        self.assertNotIn('actions/upload-artifact', source)
        self.assertNotIn('actions/download-artifact', source)
        self.assertIn('DOCKER_BUILD_RECORD_UPLOAD: "false"', source)
        self.assertIn('provenance: mode=max', source)
        self.assertIn('needs: build', source)
        self.assertIn('image_handoff.py publish', source)
        self.assertIn('image_handoff.py merge', source)


if __name__ == '__main__':
    unittest.main()
