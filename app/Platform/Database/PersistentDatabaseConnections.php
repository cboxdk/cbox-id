<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Contracts\Config\Repository;
use PDO;

/**
 * Keeps the PostgreSQL connection between requests, where a process serves one request at
 * a time and then another: php-fpm.
 *
 * A connection that says `'persistent' => true` in config/database.php gets
 * `PDO::ATTR_PERSISTENT` here, at boot, and only under such a server. It cannot be written
 * into the config file itself: the file is cached by a command-line process
 * (`config:cache`), so it cannot ask which server will read it.
 *
 * NOT in a command-line process (a queue worker, the scheduler, a test, a long-lived
 * application server). Such a process keeps its connection for as long as it lives anyway,
 * so persistence buys nothing, and it can fork: PHP hands a forked child the parent's
 * persistent connection, and two processes then write to one socket. That is how this was
 * found: a test that forks to prove a row lock blocks saw the child pass straight through
 * on the parent's connection.
 */
final class PersistentDatabaseConnections
{
    /** The servers that run one request per process at a time and reuse the process. */
    private const REQUEST_PER_PROCESS = ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed'];

    public static function apply(Repository $config, string $sapi): void
    {
        if (! in_array($sapi, self::REQUEST_PER_PROCESS, true)) {
            return;
        }

        $connections = $config->get('database.connections');

        if (! is_array($connections)) {
            return;
        }

        foreach ($connections as $name => $connection) {
            if (! is_string($name) || ! is_array($connection)
                || ($connection['driver'] ?? null) !== 'pgsql' || ($connection['persistent'] ?? false) !== true) {
                continue;
            }

            $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
            $options[PDO::ATTR_PERSISTENT] = true;

            $config->set("database.connections.{$name}.options", $options);
        }
    }
}
