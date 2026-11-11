<?php

declare(strict_types=1);

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppCatalog\CatalogEntryData;
use App\Platform\AppCatalog\CatalogHue;

/*
 * `php artisan app-catalog:apply` — the Cbox catalog's switcher fields, kept in the repo and
 * applied by an operator. A patch: only the fields the file names change.
 */

function catalogFile(array $apps): string
{
    $path = tempnam(sys_get_temp_dir(), 'catalog').'.json';
    file_put_contents($path, json_encode(['apps' => $apps], JSON_THROW_ON_ERROR));

    return $path;
}

it('sets the purpose and hue of apps already in the catalog and keeps everything else', function (): void {
    app(AppCatalog::class)->save('work', CatalogEntryData::fromArray(['version' => 0, 'name' => 'Cbox Work', 'launch_url' => 'https://work.example.com/launch',
        'enabled' => true, 'position' => 3, 'audience' => 'everyone', 'admission_url' => 'https://work.example.com/signup']));

    $this->artisan('app-catalog:apply', ['file' => catalogFile([['key' => 'work', 'purpose' => 'Time and invoices', 'hue' => 'amber'], ['key' => 'tax', 'purpose' => 'VAT']]),
        '--environment' => 'env_test'])
        ->expectsOutputToContain('work: updated.')
        ->expectsOutputToContain('tax: not in the env_test catalog')
        ->assertSuccessful();

    $work = CatalogEntry::query()->where('key', 'work')->sole();
    expect([$work->purpose, $work->hue, $work->name, $work->launch_url, $work->position, $work->enabled, $work->admission_url, $work->version])
        ->toBe(['Time and invoices', 'amber', 'Cbox Work', 'https://work.example.com/launch', 3, true, 'https://work.example.com/signup', 2])
        ->and(CatalogEntry::query()->where('key', 'tax')->exists())->toBeFalse();

    // Running it again changes nothing.
    $this->artisan('app-catalog:apply', ['file' => catalogFile([['key' => 'work', 'purpose' => 'Time and invoices', 'hue' => 'amber']]), '--environment' => 'env_test'])
        ->expectsOutputToContain('work: unchanged.')->assertSuccessful();
    expect(CatalogEntry::query()->where('key', 'work')->sole()->version)->toBe(2);
});

it('creates a draft when the file says where the app lives, and refuses an invalid field', function (): void {
    $this->artisan('app-catalog:apply', ['file' => catalogFile([
        ['key' => 'signals', 'name' => 'Cbox Signals', 'launch_url' => 'https://signals.example.com/launch', 'purpose' => 'Signals', 'hue' => 'rose'],
        ['key' => 'status', 'name' => 'Cbox Status', 'launch_url' => 'https://status.example.com', 'hue' => 'chartreuse'],
    ]), '--environment' => 'env_test'])
        ->expectsOutputToContain('signals: created (draft).')
        ->expectsOutputToContain('status:')
        ->assertFailed();

    expect(CatalogEntry::query()->where('key', 'signals')->sole()->enabled)->toBeFalse()
        ->and(CatalogEntry::query()->where('key', 'status')->exists())->toBeFalse();
});

it('ships a valid seed for the fourteen Cbox apps', function (): void {
    $file = json_decode((string) file_get_contents(resource_path('app-catalog/cbox.json')), true, 32, JSON_THROW_ON_ERROR);

    expect($file['environment'])->toBe('cbox')
        ->and(array_column($file['apps'], 'key'))->toEqualCanonicalizing(['id', 'billing', 'engage', 'telemetry', 'work', 'brain', 'infra', 'assistant', 'sites', 'stache', 'status', 'tax', 'signals', 'domains']);
    foreach ($file['apps'] as $app) {
        expect(mb_strlen($app['purpose']))->toBeLessThanOrEqual(60)
            ->and($app['hue'])->toBeIn(CatalogHue::values());
    }
});
