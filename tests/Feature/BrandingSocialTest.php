<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Livewire\Volt\Volt;

it('lets an admin set org branding and themes the branded login page', function () {
    [, $org] = actingAsRole('owner');

    Volt::test('settings')
        ->set('brandColor', '#0ea5e9')
        ->set('brandLogoUrl', 'https://acme.test/logo.svg')
        ->call('saveBranding')
        ->assertHasNoErrors();

    expect(app(Organizations::class)->find($org->id)?->settings)->toMatchArray([
        'brand_color' => '#0ea5e9',
    ]);

    $this->get('/o/'.$org->slug.'/login')
        ->assertOk()
        ->assertSee('#0ea5e9')          // colour injected into the themed <style>
        ->assertSee($org->name);        // org name on the branded panel
});

it('rejects an invalid brand colour', function () {
    actingAsRole('owner');

    Volt::test('settings')->set('brandColor', 'red')->call('saveBranding')->assertHasErrors('brandColor');
});

it('forbids a non-admin from changing branding', function () {
    actingAsRole('member');

    Volt::test('settings')->set('brandColor', '#000000')->call('saveBranding')->assertForbidden();
});

it('hides social buttons and 404s providers when none are configured', function () {
    config(['services.google.client_id' => null, 'services.github.client_id' => null, 'services.microsoft.client_id' => null]);

    $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
    $this->get('/auth/google/redirect')->assertNotFound();
});

it('offers a social provider once it is configured', function () {
    config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);

    $this->get('/login')->assertOk()->assertSee('Continue with Google');
});
