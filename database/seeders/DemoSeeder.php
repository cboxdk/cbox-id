<?php

namespace Database\Seeders;

use App\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // User now uses HasUlids, so ->id is the real ULID the login path resolves.
        $mk = fn (string $name, string $email) => User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password', 'status' => 'active', 'email_verified_at' => now()],
        );

        $user = $mk('Ada Lovelace', 'admin@acme.test');

        $orgs = app(Organizations::class);
        $org = $orgs->bySlug('acme') ?? $orgs->create(new NewOrganization(name: 'Acme Inc', slug: 'acme', type: OrganizationType::Customer));

        $members = app(Memberships::class);
        if (! $members->of($org->id, (string) $user->id)) {
            $members->add($org->id, (string) $user->id, 'owner');
        }

        // A few extra members so the members screen isn't empty.
        foreach ([['Grace Hopper', 'grace@acme.test', 'admin'], ['Alan Turing', 'alan@acme.test', 'member'], ['Katherine Johnson', 'kat@acme.test', 'member']] as [$name,$email,$role]) {
            $u = $mk($name, $email);
            if (! $members->of($org->id, (string) $u->id)) {
                $members->add($org->id, (string) $u->id, $role);
            }
        }

        // A second org the same admin belongs to — so the org switcher has options.
        $org2 = $orgs->bySlug('globex') ?? $orgs->create(new NewOrganization(name: 'Globex', slug: 'globex', type: OrganizationType::Customer));
        if (! $members->of($org2->id, (string) $user->id)) {
            $members->add($org2->id, (string) $user->id, 'admin');
        }

        echo "USER_ID={$user->id}\nORG_ID={$org->id}\nORG2_ID={$org2->id}\n";
    }
}
