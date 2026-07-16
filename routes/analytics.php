<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Gated on the feature, so the route doesn't exist on a host without analytics.
// `platform.auth` is the host console's auth guard (cbox-id); adjust per host.
Route::middleware(['platform.auth', 'console.feature:analytics'])->group(function (): void {
    Volt::route('/analytics', 'analytics.dashboard')->name('analytics.overview');
});
