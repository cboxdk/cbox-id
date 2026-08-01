<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
| The suite owns its own infrastructure, and says so here rather than hoping.
|
| phpunit.xml's `<env>` — even with force="true" — writes $_ENV and putenv() but
| NOT $_SERVER, and Laravel's Env repository reads $_SERVER first. So a machine
| that exports QUEUE_CONNECTION=redis wins over the suite's own configuration and
| redirects every job at a Redis nobody started. That is not hypothetical: this
| repository's self-hosted CI runner exports exactly that, and its `pest` step had
| been failing with "RedisException: Connection refused" on commits that were green
| on every developer machine — long enough for a red CI to become the normal view.
|
| Setting all three ($_SERVER, $_ENV, putenv) closes the hole for good.
|
| DB_* IS DELIBERATELY ABSENT. The engines matrix redirects the whole run to
| PostgreSQL or MySQL by exporting DB_CONNECTION, and pinning it here would put
| every driver back on sqlite — quietly re-creating the sqlite-only CI this
| application already paid for once.
*/

require __DIR__.'/../vendor/autoload.php';

$suiteOwned = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    // Pinned off so the suite is deterministic: the devices tests assert the feature's
    // default state, and a developer .env — or a shell that exports it — must not change
    // what the tests see. Tests that want it on set it through config(). Both spellings
    // are pinned; the ID_ form is the pre-0.34 name the config still honours.
    'CBOX_ID_DEVICES_ENABLED' => 'false',
    'ID_DEVICES_ENABLED' => 'false',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ($suiteOwned as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}
