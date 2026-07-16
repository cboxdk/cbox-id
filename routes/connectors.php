<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Gated on the feature, so these routes don't exist on a host without connectors.
// `platform.auth` is the host console's auth guard (cbox-id); adjust per host.
Route::middleware(['web', 'platform.auth', 'console.feature:connectors'])->group(function (): void {
    Volt::route('/connectors', 'connectors.catalog')->name('connectors.catalog');
    Volt::route('/connectors/connections', 'connectors.connections')->name('connectors.connections');
});
