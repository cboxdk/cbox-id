"""Hand off immutable architecture digests through run-scoped registry references."""
import json
import os
import re
import subprocess
import sys

DIGEST = re.compile(r'sha256:[0-9a-f]{64}')


def digest(value):
    if not isinstance(value, str) or not DIGEST.fullmatch(value):
        raise ValueError('Invalid image digest')
    return value


def images(manifest):
    digest(manifest.get('digest'))
    entries = manifest.get('manifests')
    if not isinstance(entries, list) or not entries:
        raise ValueError('Expected an image index with explicit platforms')
    result = {}
    attestations = []
    for entry in entries:
        value = digest(entry.get('digest'))
        platform = entry.get('platform', {})
        arch = platform.get('architecture')
        if platform.get('os') == 'linux' and arch in ['amd64', 'arm64']:
            if arch in result:
                raise ValueError('Duplicate image architecture')
            result[arch] = value
        elif platform == {'architecture': 'unknown', 'os': 'unknown'}:
            annotations = entry.get('annotations', {})
            if annotations.get('vnd.docker.reference.type') != 'attestation-manifest':
                raise ValueError('Unknown non-image manifest')
            attestations.append(digest(annotations.get('vnd.docker.reference.digest')))
        else:
            raise ValueError('Unexpected image platform')
    if any(value not in result.values() for value in attestations):
        raise ValueError('Attestation does not reference an included image')
    return result


def verify_source(manifest, config, arch, revision, run, attempt):
    found = images(manifest)
    if set(found) != {arch} or config.get('os') != 'linux' or config.get('architecture') != arch:
        raise ValueError('Architecture mismatch')
    labels = config.get('config', {}).get('Labels', {})
    expected = {'org.opencontainers.image.revision': revision,
                'io.cbox.build.run-id': run, 'io.cbox.build.run-attempt': attempt}
    if any(labels.get(key) != value for key, value in expected.items()):
        raise ValueError('Image belongs to another commit or workflow attempt')
    return digest(manifest['digest'])


class Docker:
    def inspect(self, reference, field='Manifest'):
        raw = subprocess.check_output(['docker', 'buildx', 'imagetools', 'inspect', reference,
                                       '--format', '{{json .'+field+'}}'], text=True)
        return json.loads(raw)

    def create(self, tags, sources):
        arguments = ['docker', 'buildx', 'imagetools', 'create']
        for tag in tags:
            arguments.extend(['--tag', tag])
        subprocess.run([*arguments, *sources], check=True)


def context(env):
    image, revision, run, attempt = (env[key] for key in ['IMAGE', 'GITHUB_SHA', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT'])
    if not re.fullmatch(r'[a-z0-9./_-]+', image) or not re.fullmatch(r'[0-9a-f]{40}', revision):
        raise ValueError('Invalid image or commit identity')
    if not run.isdigit() or not attempt.isdigit():
        raise ValueError('Invalid workflow identity')
    return image, revision, run, attempt


def execute(mode, env, docker, arch=None, built_digest=None):
    image, revision, run, attempt = context(env)
    prefix = f'{image}:build-{revision}-{run}-{attempt}'
    if mode == 'publish':
        if arch not in ['amd64', 'arm64']:
            raise ValueError('Unsupported architecture')
        source = image+'@'+digest(built_digest)
        manifest = docker.inspect(source)
        child = images(manifest).get(arch)
        if child is None:
            raise ValueError('Missing architecture')
        config = docker.inspect(image+'@'+child, 'Image')
        if verify_source(manifest, config, arch, revision, run, attempt) != built_digest:
            raise ValueError('Build output digest mismatch')
        reference = prefix+'-'+arch
        docker.create([reference], [source])
        if docker.inspect(reference).get('digest') != built_digest:
            raise ValueError('Published architecture reference changed its digest')
        return source
    if mode != 'merge':
        raise ValueError('Unknown handoff mode')
    sources, expected = [], {}
    for arch in ['amd64', 'arm64']:
        manifest = docker.inspect(prefix+'-'+arch)
        child = images(manifest).get(arch)
        if child is None:
            raise ValueError('Missing architecture')
        config = docker.inspect(image+'@'+child, 'Image')
        value = verify_source(manifest, config, arch, revision, run, attempt)
        sources.append(image+'@'+value)
        expected[arch] = child
    tags = json.loads(env['DOCKER_METADATA_OUTPUT_JSON'])['tags']
    if not isinstance(tags, list) or not tags or any(not isinstance(tag, str) or not tag.startswith(image+':') for tag in tags):
        raise ValueError('Invalid destination image tags')
    full_commit = image+':sha-'+revision
    assembled = prefix+'-verified'
    docker.create([assembled], sources)
    merged = docker.inspect(assembled)
    if images(merged) != expected:
        raise ValueError('Merged image is not the verified architecture pair')
    verified = image+'@'+digest(merged['digest'])
    docker.create(list(dict.fromkeys([*tags, full_commit])), [verified])
    if docker.inspect(full_commit).get('digest') != merged['digest']:
        raise ValueError('Promoted image digest differs from verified index')
    return verified


if __name__ == '__main__':
    try:
        print(execute(sys.argv[1], os.environ, Docker(),
                      os.environ.get('ARCH'), os.environ.get('DIGEST')))
    except Exception as error:
        print('Image handoff refused: '+str(error), file=sys.stderr)
        sys.exit(1)
