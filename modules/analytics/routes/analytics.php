<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceImpersonationWindow;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Gated on the feature, so the route doesn't exist on a host without analytics.
// `platform.auth` is the host console's auth guard (cbox-id); adjust per host.
Route::middleware([
    'web',
    // The same stack every host console route carries. `plane:subject` confines these
    // pages to a tenant host rather than answering on the account root, and the
    // impersonation window is ENFORCED rather than inherited — without it, an
    // impersonation that outlived its 30-minute box keeps reading these pages, because
    // reads sit on the call guard's allowlist and nothing else stops them.
    'plane:subject',
    EnforceImpersonationWindow::class,
    'platform.auth',
    'console.feature:analytics',
])->group(function (): void {
    Volt::route('/analytics', 'analytics.dashboard')->name('analytics.overview');
});
