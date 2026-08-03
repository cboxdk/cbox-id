<?php

declare(strict_types=1);

namespace App\Platform\Install\Contracts;

use App\Platform\Install\Exceptions\PlatformNotEmpty;
use App\Platform\Install\InstalledPlatform;
use App\Platform\Install\InstallPlan;
use App\Platform\Install\PlatformOccupancy;

/**
 * Takes an empty deployment to a usable one: a platform root, the first operator, and —
 * in the SaaS shape — the first account and its environment.
 *
 * An interface because two callers provision (the install command and the first-run
 * screen) and because the refusal below is a security control: whatever provisions a
 * platform root must be replaceable in a test without replacing the check that stops it
 * happening twice.
 */
interface PlatformInstaller
{
    /**
     * Whether nothing has claimed this platform yet.
     *
     * Separate from {@see occupancy()} because it is asked on ordinary web requests: it
     * stops at the first thing it finds, so a live deployment answers in one query
     * instead of four.
     */
    public function isEmpty(): bool;

    /**
     * Whether the schema this installer writes into is actually there.
     *
     * An un-migrated deployment IS empty — nothing has claimed it — but it cannot be
     * installed until its tables exist, and the two facts have to be separable: the
     * first-run screen must be able to say "run your migrations" rather than offering a
     * form whose submission would fail on a missing table.
     */
    public function ready(): bool;

    /** Everything that makes this platform non-empty — the finding a refusal reports. */
    public function occupancy(): PlatformOccupancy;

    /**
     * Provision the deployment described by the plan.
     *
     * @throws PlatformNotEmpty when anything is already there. NOT an idempotent no-op:
     *                          re-running against a live deployment would mint a second
     *                          platform root and re-key the environment it stamps.
     */
    public function install(InstallPlan $plan): InstalledPlatform;
}
