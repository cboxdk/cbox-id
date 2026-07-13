<?php

namespace Database\Seeders;

use Cbox\Id\Organization\Models\Environment;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Bootstrap the essentials a fresh install needs to function: a default
     * environment (the hard outer boundary every owned row belongs to). Demo
     * organizations and users live in DemoSeeder, run explicitly.
     *
     * A platform operator — the identity above environments — is created out of
     * band (an interactive command or env-driven bootstrap), never with a
     * hard-coded password here.
     */
    public function run(): void
    {
        Environment::firstOrCreate(
            ['slug' => 'production'],
            ['name' => 'Production', 'status' => 'active'],
        );
    }
}
