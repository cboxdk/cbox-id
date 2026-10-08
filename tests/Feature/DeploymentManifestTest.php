<?php

declare(strict_types=1);

use App\Platform\Health\ProductionConfigDoctorCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| cbox.yaml is the local manifest, in production's shape
|--------------------------------------------------------------------------
|
| cbox-engine (the `cbox` CLI and Cbox Local) reads cbox.yaml to run this application on a
| developer's machine the way production runs it. Production itself is deployed from the
| private infrastructure repository, not from this file (docs/operations/deployment.md).
| These tests hold the file to two things: the engine's reader accepts it — every key, every
| binding, every command — and it keeps production's shape: replicas that share all state
| through Valkey, the queue manager and the scheduler beside them, PostgreSQL of
| production's major, and no secret written down.
*/

/** @return array<string, mixed> */
function localManifest(): array
{
    $manifest = Yaml::parseFile(base_path('cbox.yaml'));

    expect($manifest)->toBeArray();

    /** @var array<string, mixed> $manifest */
    return $manifest;
}

/**
 * Run $callback with the manifest's `env` applied over a production environment, with a
 * stand-in for every variable a resource binding resolves. The config files the manifest
 * speaks to are evaluated again, so what the doctor reads is what a pod with this shape
 * would read.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function underLocalManifestEnvironment(Closure $callback): mixed
{
    $manifest = localManifest();
    $variables = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        ...array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, (array) $manifest['env']),
    ];

    foreach ((array) ($manifest['resources'] ?? []) as $resource) {
        foreach ((array) ($resource['bind'] ?? []) as $variable) {
            $variables[(string) $variable] = 'resolved-from-a-binding';
        }
    }

    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        foreach (['app', 'cache', 'session', 'queue', 'logging', 'cbox-id', 'queue-autoscale'] as $file) {
            config([$file => require config_path($file.'.php')]);
        }

        app()->detectEnvironment(fn (): string => (string) config('app.env'));

        return $callback();
    } finally {
        foreach ($previous as $name => [$env, $server]) {
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
    }
}

/*
| cbox-engine's ProjectManifestReader refuses a key it does not know rather than ignoring
| it. Its lists are copied here, because the engine is not a dependency of this
| application: a key added here that the engine cannot read fails this test, not a
| developer's `cbox deploy`.
*/
it('speaks the cbox-engine reader\'s schema, every key of it', function (): void {
    $manifest = localManifest();

    // ProjectManifestReader::KEYS.
    $readable = ['build', 'domains', 'env', 'idle_seconds', 'image', 'mount', 'name', 'port',
        'mounts', 'processes', 'replicas', 'resources', 'scale_to_zero', 'services', 'source', 'url'];

    expect(array_values(array_diff(array_keys($manifest), $readable)))->toBe([]);

    // An environment variable is one value; the reader refuses a structure.
    foreach ((array) $manifest['env'] as $name => $value) {
        expect(is_scalar($value))->toBeTrue("env.{$name} is a single value");
    }

    foreach ((array) $manifest['resources'] as $name => $resource) {
        $resource = (array) $resource;

        expect(array_values(array_diff(array_keys($resource), ['engine', 'version', 'storage', 'bind'])))->toBe([], "resources.{$name}");

        // Cbox\Platform\Binding\ConnectionField — `user`, never `username`.
        foreach (array_keys((array) ($resource['bind'] ?? [])) as $field) {
            expect($field)->toBeIn(['host', 'port', 'database', 'user', 'password', 'url'], "resources.{$name}.bind.{$field}");
        }

        // The engine's Valkey has no password, and the reader refuses a binding for one.
        if ($resource['engine'] === 'valkey') {
            expect((array) ($resource['bind'] ?? []))->not->toHaveKey('password');
        }
    }

    // A container runs a program, not a shell line: the reader refuses `&&`, pipes,
    // redirects and `$VAR` by name.
    foreach ((array) $manifest['processes'] as $name => $command) {
        expect($command)->toBeList();

        foreach ((array) $command as $argument) {
            expect((string) $argument)->not->toMatch('/^(&&|\|\|?|;|>>?|<)$|\$/', "processes.{$name}");
        }
    }
});

it('runs from the working copy on the base image the production image is built from', function (): void {
    $manifest = localManifest();
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($manifest['source'])->toBeTrue()
        ->and($manifest['image'])->toBe('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v1')
        ->and($dockerfile)->toContain("\nFROM {$manifest['image']}\n")
        ->and($manifest['env']['LOG_CHANNEL'])->toBe('stderr');
});

it('runs production\'s processes beside the web: the queue manager and the scheduler', function (): void {
    expect(localManifest()['processes'])->toBe([
        'queue' => ['php', 'artisan', 'queue:autoscale'],
        'scheduler' => ['php', 'artisan', 'schedule:work'],
    ]);
});

it('shares every piece of state through Valkey, because it runs more than one replica', function (): void {
    $manifest = localManifest();
    $env = (array) $manifest['env'];

    expect($manifest['replicas'])->toBeGreaterThan(1)
        // The application is told the same number the engine is, or the doctor's
        // replica-aware checks would be asking about a deployment that does not exist.
        ->and((int) $env['CBOX_ID_REPLICAS'])->toBe($manifest['replicas'])
        ->and($env['CACHE_STORE'])->toBe('redis')
        ->and($env['SESSION_DRIVER'])->toBe('redis')
        ->and($env['QUEUE_CONNECTION'])->toBe('redis')
        ->and($env['QUEUE_AUTOSCALE_CLUSTER_ENABLED'])->toBe('true')
        ->and($manifest['resources']['cache']['engine'])->toBe('valkey')
        // The service's name is the engine's: it arrives in the bound variables.
        ->and($manifest['resources']['cache']['bind'])->toBe(['host' => 'REDIS_HOST', 'port' => 'REDIS_PORT'])
        ->and((string) file_get_contents(base_path('cbox.yaml')))->toContain('maxmemory-policy noeviction');
});

it('is a shape the production doctor passes on cache, sessions, queue and logs', function (): void {
    $verdicts = underLocalManifestEnvironment(static function (): array {
        expect(app()->isProduction())->toBeTrue();

        return collect(app(ProductionConfigDoctorCheck::class)->run())
            ->mapWithKeys(static fn (HealthResult $result): array => [$result->label => $result->status->value.': '.$result->detail])
            ->all();
    });

    // Mail and the health token are the environment's, not the manifest's: production's
    // come from its Secret, a developer's from `.env`. The four the manifest decides pass.
    foreach (['Cache', 'Sessions', 'Queue', 'Logs'] as $label) {
        expect($verdicts)->toHaveKey($label)
            ->and($verdicts[$label])->toStartWith('ok', "{$label}: {$verdicts[$label]}");
    }
});

it('writes no secret: the keys come from the working copy\'s .env and the bindings', function (): void {
    $manifest = localManifest();
    $env = (array) $manifest['env'];

    foreach (['APP_KEY', 'CBOX_ID_CRYPTO_KEY', 'HEALTH_TOKEN', 'MAIL_PASSWORD', 'DB_PASSWORD', 'REDIS_PASSWORD'] as $variable) {
        expect($env)->not->toHaveKey($variable, "{$variable} is a secret and never belongs in a committed file");
    }

    // APP_ENV, APP_DEBUG and mail are the developer's `.env`: a value here would override it.
    expect($env)->not->toHaveKey('APP_ENV')
        ->and($env)->not->toHaveKey('APP_DEBUG')
        ->and($env)->not->toHaveKey('MAIL_MAILER');

    // The database password is the binding's, resolved from the engine's own Secret.
    expect($manifest['resources']['database']['bind']['password'])->toBe('DB_PASSWORD');

    // Nothing in `env` looks like a key or a password somebody pasted in.
    foreach ($env as $name => $value) {
        expect((string) $value)->not->toMatch('/^base64:|^cbid_|^sk_|BEGIN [A-Z ]*PRIVATE KEY/', "{$name} looks like a secret");
    }
});

it('runs PostgreSQL of the major CI tests on and production runs', function (): void {
    $database = (array) localManifest()['resources']['database'];
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $requirements = (string) file_get_contents(base_path('docs/requirements.md'));

    expect($database['engine'])->toBe('postgres')
        // Stated, so an engine release cannot move the major; the same major is the one
        // the `engines` job runs the suite against and the one docs/requirements.md names
        // for production.
        ->and($database)->toHaveKey('version')
        ->and($ci)->toContain('image: postgres:'.$database['version'])
        ->and($requirements)->toContain('PostgreSQL '.$database['version']);
});

it('leaves the security headers to the application, and never sets a second CSP in nginx', function (): void {
    $env = (array) localManifest()['env'];

    foreach (['X_FRAME_OPTIONS', 'X_CONTENT_TYPE_OPTIONS', 'REFERRER_POLICY', 'PERMISSIONS_POLICY'] as $header) {
        expect($env)->toHaveKey("NGINX_HEADER_{$header}", '');
    }

    expect($env)->not->toHaveKey('NGINX_HEADER_CONTENT_SECURITY_POLICY');
});
