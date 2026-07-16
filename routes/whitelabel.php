<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Gated on the feature, so the route doesn't exist on a host without whitelabel.
// `platform.auth` is the host console's auth guard (cbox-id); adjust per host.
Route::middleware(['web', 'platform.auth', 'console.feature:whitelabel'])->group(function (): void {
    Volt::route('/settings/branding', 'whitelabel.branding')->name('whitelabel.branding');
});
