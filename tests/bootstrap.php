<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
| The suite owns its own environment, and says so here rather than hoping.
|
| Two properties, both learned the hard way:
|
| 1. It must beat the host. phpunit.xml's `<env>` — even with force="true" — writes
|    $_ENV and putenv() but NOT $_SERVER, and Laravel's Env repository reads $_SERVER
|    first, so a machine that exports QUEUE_CONNECTION=redis silently wins. Setting all
|    three closes that, and every CBOX_ID_ variable is removed rather than merged: an
|    inherited one can only be some machine's opinion about a deployment, and this is
|    not one. (The bare ID_ prefix is still stripped, so a deployment that has not
|    finished renaming cannot reach the suite through the door 0.34.0 closed.)
|
| 2. It must not need anything nobody started. Queue metrics ship enabled with a REDIS
|    storage driver and a JobQueued listener, so the suite quietly required a Redis
|    daemon. That is invisible on a developer machine — one is usually running — and
|    fatal on CI, which is the worst possible split: the machine with no local services
|    is the one whose red build everybody learns to ignore. It cost this repository a
|    permanently red `pest` step.
|
| DB_* IS DELIBERATELY EXCLUDED and stays in phpunit.xml. The engines matrix redirects
| the whole run to PostgreSQL or MySQL by exporting DB_CONNECTION; pinning it here would
| put every driver back on sqlite and quietly re-create the sqlite-only CI this
| application already paid for once.
*/

require __DIR__.'/../vendor/autoload.php';

$suiteOwned = [
    // Framework infrastructure. The suite runs against nothing it did not start.
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    // Queue metrics default to enabled with a REDIS storage driver, and their
    // JobQueued listener writes on every dispatch — so the suite silently required a
    // Redis daemon that nothing in this repository starts. It passed on developer
    // machines because a Redis happens to be running on them, and failed on CI, which
    // is the worst possible split: the machine with no local services is the one whose
    // red build everybody learns to ignore. The suite does not test queue metrics; a
    // test that wants them turns them on through config().
    'QUEUE_METRICS_ENABLED' => 'false',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',

    // Platform identity: fixed so key material and issuer URLs are identical on every
    // machine, which several suites assert against.
    'CBOX_ID_CRYPTO_KEY' => 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
    'CBOX_ID_ISSUER' => 'http://localhost',
    'CBOX_ID_WEBAUTHN_RP_ID' => 'localhost',
    'CBOX_ID_WEBAUTHN_ORIGIN' => 'http://localhost',

    // The Frontend API is OFF by default in production and ON here, pinned rather than
    // inherited: the routes are decided when the application boots, so a suite reading
    // this from whichever `.env` happens to be on the machine is a suite whose green says
    // nothing about anybody else's. The one test that proves the off-state rebuilds the
    // application with it off, which is what an install that never turned it on does.
    'CBOX_ID_FRONTEND_API' => 'true',

    // Modules off, their delivery jobs inline. The devices tests assert the feature's
    // default state, and a developer .env — or a runner's shell — must not change what
    // they see; a test that wants a module on turns it on through config().
    'CBOX_ID_DEVICES_ENABLED' => 'false',
    'CBOX_ID_DEVICES_QUEUE_CONNECTION' => '',
    'CBOX_ID_DEVICES_QUEUE' => '',
];

// Everything this application configures through the environment is the suite's to
// decide. The bare ID_ prefix is included even though nothing reads it any more: a
// machine still carrying the pre-0.34 names must not be able to reach the suite if a
// fallback is ever reintroduced by accident.
foreach (array_unique([...array_keys($_SERVER), ...array_keys($_ENV)]) as $name) {
    if (! is_string($name) || array_key_exists($name, $suiteOwned)) {
        continue;
    }

    if (str_starts_with($name, 'CBOX_ID_') || str_starts_with($name, 'ID_')) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}

foreach ($suiteOwned as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}
