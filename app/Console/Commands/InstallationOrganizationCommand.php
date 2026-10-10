<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\InstallationOrganization;
use App\Platform\PlaneResolver;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Console\Command;

/**
 * Name the organization a single-tenant install belongs to ({@see InstallationOrganization}),
 * whose owners then administer the environment's sign-in settings from its console. Run
 * by whoever runs the install, on purpose not from the console itself: deciding who holds
 * the environment is not something an organization's administrator should be able to grant.
 */
class InstallationOrganizationCommand extends Command
{
    protected $signature = 'cbox-id:installation-organization {organization? : The organization\'s id or slug} {--clear : Name none}';

    protected $description = 'Show or set the organization a single-tenant install belongs to — its owners administer the environment\'s sign-in settings';

    public function handle(PlaneResolver $planes, PlatformRoot $root): int
    {
        if ($planes->isMultiTenant()) {
            $this->error('This is a multi-tenant deployment: environments are administered from their environment console.');

            return self::FAILURE;
        }

        $result = $root->run(function (): int {
            $installation = app(InstallationOrganization::class);
            $given = $this->argument('organization');

            if ($this->option('clear')) {
                $installation->set(null);
                $this->info('No organization is the installation\'s own now; only platform operators administer the environment.');

                return self::SUCCESS;
            }

            if (! is_string($given) || $given === '') {
                $id = $installation->id();
                $this->line($id === null ? 'None — only platform operators administer the environment.' : 'Organization '.$id);

                return self::SUCCESS;
            }

            $organization = Organization::query()->whereKey($given)->orWhere('slug', $given)->first();

            if ($organization === null || ! $installation->set($organization->id)) {
                $this->error('No organization with that id or slug in this install.');

                return self::FAILURE;
            }

            $this->info($organization->name.' is the installation\'s own: its owners administer the environment\'s sign-in settings.');

            return self::SUCCESS;
        });

        return is_int($result) ? $result : self::FAILURE;
    }
}
