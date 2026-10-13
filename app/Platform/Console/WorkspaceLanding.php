<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\IntendedUrl;

/**
 * WHERE A WORKSPACE MEMBER LANDS after signing in: the workspace's home, Projects.
 *
 * A customer signing in used to land on an overview of their workspace's own organization
 * in Cbox's environment — sign-in counts for a team of four, a setup checklist for roles
 * and apps they would never configure there. Projects lists every environment with an
 * "Open console" beside it, which is where they work from.
 *
 * NOT STRAIGHT INTO THE ONE ENVIRONMENT, which is what this did for a workspace with
 * exactly one: every sign-in at the account host — a password, Google, a passkey — minted
 * a handoff and dropped the person into a tenant's console they had not asked to open.
 * Together with a sign-out that left the account host signed in, it was how a person who
 * had just logged out of a tenant console found themselves back in it. The account host
 * opens an environment's console when somebody asks for it: "Open console", the context
 * switcher, or a sign-in that started at that console's own `/admin`
 * ({@see IntendedUrl::pullForSubject()}).
 */
final readonly class WorkspaceLanding
{
    public function url(): string
    {
        return route('projects');
    }
}
