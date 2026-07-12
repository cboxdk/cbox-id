<?php

declare(strict_types=1);

it('redirects the root to the login screen for guests', function () {
    $this->get('/')->assertRedirect(route('login'));
});
