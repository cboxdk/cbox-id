<?php

namespace Database\Seeders;

use App\Models\User;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Organization\Enums\OrganizationType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $mk = fn (string $name, string $email) => User::firstOrCreate(
            ['email' => $email],
            ['id' => (string) \Illuminate\Support\Str::ulid(), 'name' => $name, 'password' => Hash::make('password'), 'status' => 'active', 'email_verified_at' => now()],
        );

        $user = $mk('Ada Lovelace', 'admin@acme.test');

        $orgs = app(Organizations::class);
        $org = $orgs->bySlug('acme') ?? $orgs->create(new NewOrganization(name: 'Acme Inc', slug: 'acme', type: OrganizationType::Customer));

        $members = app(Memberships::class);
        if (! $members->of($org->id, (string) $user->id)) {
            $members->add($org->id, (string) $user->id, 'owner');
        }

        // A few extra members so the members screen isn't empty.
        foreach ([['Grace Hopper','grace@acme.test','admin'],['Alan Turing','alan@acme.test','member'],['Katherine Johnson','kat@acme.test','member']] as [$name,$email,$role]) {
            $u = $mk($name, $email);
            if (! $members->of($org->id, (string) $u->id)) {
                $members->add($org->id, (string) $u->id, $role);
            }
        }

        echo "USER_ID={$user->id}\nORG_ID={$org->id}\n";
    }
}
