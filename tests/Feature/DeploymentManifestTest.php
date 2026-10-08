<?php

declare(strict_types=1);

use App\Platform\Health\ProductionConfigDoctorCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| cbox.yaml is a production deployment, and the doctor says so
|--------------------------------------------------------------------------
|
| The manifest is the one place the production shape of this application is written down.
| These tests read it the way the platform does and hold it to two things: the doctor's
| production checks pass against exactly the environment it declares, and no secret is
| ever written in it — every credential is a reference to a platform Secret.
*/

/** @return array<string, mixed> */
function deploymentManifest(): array
{
    $manifest = Yaml::parseFile(base_path('cbox.yaml'));

    expect($manifest)->toBeArray();

    /** @var array<string, mixed> $manifest */
    return $manifest;
}

/**
 * Run $callback with the manifest's environment applied as the platform would apply it: its
 * `env` values, and a stand-in value for every `secrets` reference (the platform resolves
 * those from its Secrets; what matters here is that each is SET). The config files are
 * evaluated again under that environment, so what the doctor reads is what a pod booted
 * from this manifest would read.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function underManifestEnvironment(Closure $callback): mixed
{
    $manifest = deploymentManifest();
    $variables = [
        ...array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, (array) $manifest['env']),
        ...array_map(static fn (): string => 'resolved-from-a-platform-secret', (array) ($manifest['secrets'] ?? [])),
    ];

    // The bindings under `resources` are resolved by the platform too.
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
        foreach (['app', 'cache', 'session', 'queue', 'logging', 'mail', 'health', 'cbox-id', 'queue-autoscale'] as $file) {
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

it('is a production deployment the doctor passes, every check of it', function (): void {
    $verdicts = underManifestEnvironment(static function (): array {
        expect(app()->isProduction())->toBeTrue()
            ->and(config('app.debug'))->toBeFalse();

        return collect(app(ProductionConfigDoctorCheck::class)->run())
            ->mapWithKeys(static fn (HealthResult $result): array => [$result->label => $result->status->value.': '.$result->detail])
            ->all();
    });

    // Mail, cache, sessions, queue, logs and the health token — all ok, none merely warned about.
    expect($verdicts)->toHaveCount(6);

    foreach ($verdicts as $label => $verdict) {
        expect($verdict)->toStartWith('ok', "{$label}: {$verdict}");
    }
});

it('shares every piece of state through Valkey, because it runs more than one replica', function (): void {
    $manifest = deploymentManifest();
    $env = (array) $manifest['env'];

    expect($manifest['replicas'])->toBeGreaterThan(1)
        // The application is told the same number the platform is, or the doctor's
        // replica-aware checks would be asking about a deployment that does not exist.
        ->and((int) $env['CBOX_ID_REPLICAS'])->toBe($manifest['replicas'])
        ->and($env['CACHE_STORE'])->toBe('redis')
        ->and($env['SESSION_DRIVER'])->toBe('redis')
        ->and($env['QUEUE_CONNECTION'])->toBe('redis')
        ->and($env['QUEUE_AUTOSCALE_CLUSTER_ENABLED'])->toBe('true')
        ->and($manifest['resources']['cache']['engine'])->toBe('valkey')
        // The service's name is the platform's: it arrives in the bound variables.
        ->and($manifest['resources']['cache']['bind'])->toBe(['host' => 'REDIS_HOST', 'port' => 'REDIS_PORT'])
        ->and((string) file_get_contents(base_path('cbox.yaml')))->toContain('maxmemory-policy noeviction');
});

it('writes no secret in the manifest: every credential is a reference to a platform Secret', function (): void {
    $manifest = deploymentManifest();
    $env = (array) $manifest['env'];
    $secrets = (array) $manifest['secrets'];

    foreach (['APP_KEY', 'CBOX_ID_CRYPTO_KEY', 'HEALTH_TOKEN', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'] as $variable) {
        expect($env)->not->toHaveKey($variable, "{$variable} is a secret: reference it under `secrets`, never as a value")
            ->and($secrets)->toHaveKey($variable);

        // A reference names where the value lives; it never is the value.
        expect($secrets[$variable])->toBeArray()
            ->and(array_keys($secrets[$variable]))->toEqualCanonicalizing(['secret', 'key']);
    }

    foreach (['DB_PASSWORD', 'REDIS_PASSWORD'] as $variable) {
        expect($env)->not->toHaveKey($variable);
    }

    // The database password is the platform's binding — a secretKeyRef, never a copy.
    expect($manifest['resources']['database']['bind']['password'])->toBe('DB_PASSWORD');

    // Nothing in `env` looks like a key or a password somebody pasted in.
    foreach ($env as $name => $value) {
        expect((string) $value)->not->toMatch('/^base64:|^cbid_|^sk_|BEGIN [A-Z ]*PRIVATE KEY/', "{$name} looks like a secret");
    }
});

it('is real mail, on SMTP, with the provider left to the operator', function (): void {
    $env = (array) deploymentManifest()['env'];

    expect($env['MAIL_MAILER'])->toBe('smtp')
        ->and($env)->not->toHaveKey('MAIL_HOST');
});

it('runs the pinned PHP 8.5 image and logs to stderr, not into the pod', function (): void {
    $manifest = deploymentManifest();

    expect($manifest['image'])->toBe('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v1')
        ->and($manifest['env']['APP_ENV'])->toBe('production')
        ->and($manifest['env']['APP_DEBUG'])->toBe('false')
        ->and($manifest['env']['LOG_CHANNEL'])->toBe('stderr');
});

it('probes readiness at the health package\'s readiness path, behind its token, and liveness at /up', function (): void {
    $health = (array) deploymentManifest()['health'];
    $readiness = '/'.trim((string) config('health.endpoints.prefix'), '/').'/'.ltrim((string) config('health.endpoints.readiness.path'), '/');

    expect($health['path'])->toBe($readiness)
        ->and($health['liveness'])->toBe('/up')
        ->and($health['token'])->toBe('HEALTH_TOKEN')
        ->and(deploymentManifest()['secrets'])->toHaveKey('HEALTH_TOKEN');

    // Both answer on the paths named: readiness refuses an anonymous caller, liveness does not.
    config(['health.security.token' => 'probe-token']);

    $this->get($readiness)->assertForbidden();
    $this->withToken('probe-token')->get($readiness)->assertSuccessful();
    $this->get('/up')->assertOk();
});

it('leaves the security headers to the application, and never sets a second CSP in nginx', function (): void {
    $env = (array) deploymentManifest()['env'];

    foreach (['X_FRAME_OPTIONS', 'X_CONTENT_TYPE_OPTIONS', 'REFERRER_POLICY', 'PERMISSIONS_POLICY'] as $header) {
        expect($env)->toHaveKey("NGINX_HEADER_{$header}", '');
    }

    expect($env)->not->toHaveKey('NGINX_HEADER_CONTENT_SECURITY_POLICY');
});
