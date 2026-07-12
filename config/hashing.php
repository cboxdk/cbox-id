<?php

declare(strict_types=1);

return [

    /*
     * Password hashing driver. Argon2id (memory-hard, side-channel resistant) is
     * the security-first default over bcrypt — it is what NIST and OWASP recommend
     * for new deployments. Requires the sodium/argon2 PHP support (bundled in
     * modern PHP builds). Existing bcrypt hashes still verify, and are upgraded on
     * next login (needsRehash).
     */
    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
     * OWASP-aligned Argon2id parameters: 64 MiB memory, 4 iterations, 1 thread.
     * Raise `memory`/`time` on beefier hardware for more cost.
     */
    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
