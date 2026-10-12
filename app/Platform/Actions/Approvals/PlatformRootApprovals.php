<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use App\Platform\Actions\Danger;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use App\Platform\PlaneResolver;
use Cbox\Id\Platform\PlatformRoot;

/**
 * ON THE PLATFORM ROOT'S OWN ENVIRONMENT, EVERY WRITE BY A MACHINE WAITS FOR A PERSON.
 *
 * Danger is declared once per action ({@see Danger}), and it is the same action whichever
 * environment it runs in — `apis.create` is a Write in a tenant and a Write in the root.
 * What is not the same is the blast radius: the root environment is how every workspace
 * owner and every operator signs in to Cbox ID, so a change there made by an agent on a
 * prompt nobody read reaches every customer at once.
 *
 * So the action is not forked and its danger is not redeclared; the CREDENTIAL's approval
 * threshold is raised when the environment it acts in is the root's. A token or a key that
 * would hold only Critical actions for its person's approval in a tenant holds every Write
 * in the root — the sense in which a Write there is treated as Critical. Read stays free.
 *
 * MULTI-TENANT ONLY. On a single-tenant install the root environment IS the product — its
 * keys run the operator's own provisioning, as any tenant's do — and nobody else signs in
 * through it, so nothing about it is wider than a tenant's.
 *
 * Applied by the two principals that act in an environment by machine
 * ({@see EnvironmentMemberPrincipal}, {@see EnvironmentKeyPrincipal}); the console has its
 * own step-up (`env.sudo`) and is a person by definition.
 */
final class PlatformRootApprovals
{
    /** The stricter of $policy and "every write", when $environmentId is the platform root's. */
    public static function raise(?StepUpPolicy $policy, ?string $environmentId): ?StepUpPolicy
    {
        if (! self::isRoot($environmentId)) {
            return $policy;
        }

        return StepUpPolicy::strictest($policy, new StepUpPolicy(Danger::Write));
    }

    /** Whether $environmentId is the platform root's own environment. */
    public static function isRoot(?string $environmentId): bool
    {
        if (! app(PlaneResolver::class)->isMultiTenant()) {
            return false;
        }

        $root = app(PlatformRoot::class)->environment()?->environmentKey();

        return $environmentId !== null && $root !== null && $environmentId === $root;
    }
}
