<?php

declare(strict_types=1);

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

it('redirects the root to the login screen for guests', function () {
    $this->get('/')->assertRedirect(route('login'));
});
