<?php

declare(strict_types=1);

namespace App\Platform\Realm;

/**
 * What the operator chose for one run of the realm flip ({@see RealmFlip}).
 */
final readonly class FlipOptions
{
    public const ROLE_HIGHEST = 'highest';

    public const ROLE_ROOT = 'root';

    public const ROLE_REALM = 'realm';

    /**
     * @param  bool  $createMissing  move an unlinked root organization, and a root person with no
     *                               account in the realm, INTO the realm with the id they have
     *                               (see {@see RealmFlip} for why this and not "skip")
     * @param  string  $roleConflict  which role a person keeps where both rows gave them one
     * @param  bool  $keepRootAudit  leave the old root's audit rows in `audit_logs` (for a database
     *                               whose audit tables refuse DELETE); they are archived either way
     * @param  string|null  $domain  the new root's `domain`: a host, `none`, or null to decide from config
     * @param  bool  $allowPendingJobs  go ahead with jobs waiting on a queue that cannot be read
     * @param  list<string>  $carryClients  client ids of root platform clients to carry even though
     *                                      the realm has a client of the same name
     */
    public function __construct(
        public bool $createMissing = false,
        public string $roleConflict = self::ROLE_HIGHEST,
        public bool $keepRootAudit = false,
        public ?string $domain = null,
        public bool $allowPendingJobs = false,
        public array $carryClients = [],
    ) {}
}
