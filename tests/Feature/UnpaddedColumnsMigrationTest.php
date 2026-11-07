<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('keeps connection keys and optional location data intact when upgrading padded columns', function (): void {
    $migration = require database_path('migrations/2026_11_06_210000_use_unpadded_service_connection_and_location_columns.php');

    // Reproduce the old declarations, including their constraints. This covers
    // databases which already ran those migrations, not just a fresh install.
    Schema::table('service_connections', fn (Blueprint $table) => $table->char('id', 26)->change());
    Schema::table('auth_sessions', fn (Blueprint $table) => $table->char('country_code', 2)->nullable()->change());

    DB::table('service_connections')->insert([
        'id' => 'connection_upgrade', 'environment_id' => 'env_test',
        'organization_id' => 'org_upgrade', 'consumer_client_id' => 'consumer',
        'service' => 'test', 'service_client_id' => 'service_client',
        'audience' => 'https://service.test', 'scopes' => '[]', 'created_by' => 'actor',
    ]);
    foreach (['located' => 'DK', 'unknown' => null] as $id => $country) {
        DB::table('auth_sessions')->insert([
            'id' => $id, 'environment_id' => 'env_test', 'user_id' => 'subject',
            'country_code' => $country, 'expires_at' => now()->addHour(),
        ]);
    }

    $connectionIndexes = Schema::getIndexes('service_connections');
    $sessionIndexes = Schema::getIndexes('auth_sessions');
    $migration->up();

    expect(DB::table('service_connections')->value('id'))->toBe('connection_upgrade')
        ->and(DB::table('auth_sessions')->where('id', 'located')->value('country_code'))->toBe('DK')
        ->and(DB::table('auth_sessions')->where('id', 'unknown')->value('country_code'))->toBeNull()
        ->and(Schema::getIndexes('service_connections'))->toBe($connectionIndexes)
        ->and(Schema::getIndexes('auth_sessions'))->toBe($sessionIndexes);

    $id = collect(Schema::getColumns('service_connections'))->firstWhere('name', 'id');
    $country = collect(Schema::getColumns('auth_sessions'))->firstWhere('name', 'country_code');
    expect($id['nullable'])->toBeFalse()
        ->and($country['nullable'])->toBeTrue()
        ->and($country['default'])->toBeNull()
        ->and($id['type_name'])->not->toBe('bpchar')
        ->and($country['type_name'])->not->toBe('bpchar');

    // Re-running and rolling back the correction must not erase stored data.
    $migration->up();
    $migration->down();
    expect(DB::table('service_connections')->value('id'))->toBe('connection_upgrade');
});
