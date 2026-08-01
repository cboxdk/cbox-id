<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
| The suite owns its own environment, and says so here rather than hoping.
|
| Two things make hoping insufficient:
|
| 1. phpunit.xml's `<env>` — even with force="true" — writes $_ENV and putenv() but
|    NOT $_SERVER, and Laravel's Env repository reads $_SERVER first. A machine that
|    exports QUEUE_CONNECTION=redis therefore wins over the suite's own configuration,
|    and every job goes at a Redis nobody started.
|
| 2. It is a class of variables, not one. This repository's self-hosted CI runner
|    exports QUEUE_CONNECTION=redis *and* a devices queue connection; pinning the first
|    left the second still redirecting that module's jobs on its own. Naming them one
|    at a time is whack-a-mole — the next variable added to that machine is another red
|    build on a commit that is green everywhere else.
|
| So: every variable carrying this application's own prefixes (CBOX_ID_ and the legacy
| ID_) that was inherited from the host is REMOVED, and the suite's own values are then
| written to all three of $_SERVER, $_ENV and putenv(). What the tests see is a function
| of this file and nothing else.
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
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',

    // Platform identity: fixed so key material and issuer URLs are identical on every
    // machine, which several suites assert against.
    'CBOX_ID_CRYPTO_KEY' => 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
    'CBOX_ID_ISSUER' => 'http://localhost',
    'CBOX_ID_WEBAUTHN_RP_ID' => 'localhost',
    'CBOX_ID_WEBAUTHN_ORIGIN' => 'http://localhost',

    // Modules off, their delivery jobs inline. The devices tests assert the feature's
    // default state, and a developer .env — or a runner's shell — must not change what
    // they see; a test that wants a module on turns it on through config(). Both
    // spellings are pinned: ID_ is the pre-0.34 name the config still honours.
    'CBOX_ID_DEVICES_ENABLED' => 'false',
    'ID_DEVICES_ENABLED' => 'false',
    'CBOX_ID_DEVICES_QUEUE_CONNECTION' => '',
    'ID_DEVICES_QUEUE_CONNECTION' => '',
    'CBOX_ID_DEVICES_QUEUE' => '',
    'ID_DEVICES_QUEUE' => '',
];

// Everything this application configures through the environment is the suite's to
// decide. An inherited CBOX_ID_*/ID_* variable is removed rather than merged: it can
// only be some machine's opinion about a deployment, and this is not one.
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
