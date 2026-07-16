<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Gated on the feature, so the routes don't exist on a host without compliance.
// `platform.auth` is the host console's auth guard (cbox-id); adjust per host.
Route::middleware(['web', 'platform.auth', 'console.feature:compliance'])->group(function (): void {
    Volt::route('/compliance/audit', 'compliance.audit')->name('compliance.audit');
    Volt::route('/compliance/exports', 'compliance.exports')->name('compliance.exports');
});
