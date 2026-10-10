<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('puts the preloader on every page — signed in, and on the way in', function () {
    $preloader = '<div class="app-preloader" id="appPreloader" role="status" aria-label="Loading">';

    expect(test()->get(route('login'))->assertOk()->getContent())
        ->toContain($preloader)
        ->toContain('preloader.gif');

    expect(test()->actingAs(userWithRole('Sourcing'))->get(route('admin.dashboard'))->assertOk()->getContent())
        ->toContain($preloader)
        // Without JavaScript nothing would take it down again.
        ->toContain('<noscript><style>.app-preloader { display: none; }</style></noscript>');
});

it('has the GIF it shows', function () {
    expect(public_path('preloader.gif'))->toBeFile();
});
