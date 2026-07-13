<?php

namespace Database\Seeders;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Everything below is environment-owned, so it must be seeded inside a
        // plane. Provision the default "Production" environment and a second
        // "Staging" plane so the environment switcher has options.
        $production = Environment::firstOrCreate(['slug' => 'production'], ['name' => 'Production', 'status' => 'active']);
        Environment::firstOrCreate(['slug' => 'staging'], ['name' => 'Staging', 'status' => 'active']);

        // A platform operator — the identity above every environment — so the
        // demo can administer planes. Demo credentials only.
        $operators = app(PlatformOperators::class);
        if (! $operators->findByEmail('operator@cbox.test')) {
            $operators->create('operator@cbox.test', 'password', 'Platform Operator');
        }

        app(EnvironmentContext::class)->runAs($production, function (): void {
            $subjects = app(Subjects::class);
            $orgs = app(Organizations::class);
            $members = app(Memberships::class);

            // Create-or-find via the proper identity API: it fills environment_id,
            // hashes the password with the configured driver, and links identities.
            $mk = function (string $name, string $email) use ($subjects): string {
                $existing = $subjects->findByEmail($email);
                if ($existing !== null) {
                    return $existing->id;
                }

                $subject = $subjects->create($email, $name, 'password');
                User::query()->where('email', $email)->update(['email_verified_at' => now()]);

                return $subject->id;
            };

            $adminId = $mk('Ada Lovelace', 'admin@acme.test');

            $org = $orgs->bySlug('acme')
                ?? $orgs->create(new NewOrganization(name: 'Acme Inc', slug: 'acme', type: OrganizationType::Customer));
            if (! $members->of($org->id, $adminId)) {
                $members->add($org->id, $adminId, 'owner');
            }

            foreach ([['Grace Hopper', 'grace@acme.test', 'admin'], ['Alan Turing', 'alan@acme.test', 'member'], ['Katherine Johnson', 'kat@acme.test', 'member']] as [$name, $email, $role]) {
                $id = $mk($name, $email);
                if (! $members->of($org->id, $id)) {
                    $members->add($org->id, $id, $role);
                }
            }

            // A second org the same admin belongs to — so the org switcher has options.
            $org2 = $orgs->bySlug('globex')
                ?? $orgs->create(new NewOrganization(name: 'Globex', slug: 'globex', type: OrganizationType::Customer));
            if (! $members->of($org2->id, $adminId)) {
                $members->add($org2->id, $adminId, 'admin');
            }

            echo "ADMIN_ID={$adminId}\nORG_ID={$org->id}\nORG2_ID={$org2->id}\n";
        });
    }
}
