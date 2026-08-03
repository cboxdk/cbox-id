<?php

declare(strict_types=1);

use App\Platform\ConsoleLocation;
use App\Platform\Help\DocsLinks;
use App\Platform\Help\HelpTopic;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes a real guide for every topic that claims to have one', function (): void {
    foreach (HelpTopic::cases() as $topic) {
        $path = $topic->docsPath();

        if ($path === null) {
            continue;
        }

        // A "Read the guide" link that 404s is worse than no link: it teaches people
        // the documentation is not there. The file has to exist in this repo.
        expect(base_path('docs/'.$path.'.md'))->toBeFile();
    }
});

it('gives every topic an explanation that stands on its own', function (): void {
    foreach (HelpTopic::cases() as $topic) {
        expect($topic->title())->not->toBe('')
            // Short enough to read in a popover, long enough to actually say something.
            ->and(strlen($topic->summary()))->toBeGreaterThan(80)
            ->and($topic->summary())->toEndWith('.');
    }
});

it('emits no documentation link when no docs site is configured', function (): void {
    config()->set('docs.base_url', '');

    expect(app(DocsLinks::class)->url(HelpTopic::SingleSignOn))->toBeNull();
});

it('builds a documentation link from the configured base and suffix', function (): void {
    config()->set('docs.base_url', 'https://example.test/docs/');
    config()->set('docs.suffix', '.md');

    expect(app(DocsLinks::class)->url(HelpTopic::SingleSignOn))
        ->toBe('https://example.test/docs/guides/single-sign-on.md');
});

it('never links a topic that has no guide behind it', function (): void {
    config()->set('docs.base_url', 'https://example.test/docs');

    expect(app(DocsLinks::class)->url(HelpTopic::Members))->toBeNull();
});

/**
 * The wayfinding bug this closes: a page's eyebrow is supposed to say which area of
 * the console you are in, and hand-written ones drifted from the sidebar labels that
 * got you there ("Authentication" above a page reached from "Sign-in").
 */
it('resolves a page eyebrow to the nav area that owns it', function (): void {
    expect(app(ConsoleLocation::class)->areaLabel('connections'))->toBe('Sign-in')
        ->and(app(ConsoleLocation::class)->areaLabel('clients'))->toBe('Developers')
        ->and(app(ConsoleLocation::class)->areaLabel('audit'))->toBe('Logs')
        // Sub-pages hang off their page's route name.
        ->and(app(ConsoleLocation::class)->areaLabel('connections.edit'))->toBe('Sign-in')
        ->and(app(ConsoleLocation::class)->areaLabel('not-a-console-route'))->toBeNull();
});

/**
 * Clicking "Stored tokens" and landing on a page headed "Token vault" reads as the
 * product being confusing. The nav label and the page's own title are one promise.
 */
it('keeps every nav label identical to its page title', function (): void {
    $expected = [
        'connections' => 'Single sign-on',
        'directories' => 'Sync users in',
        'provisioning' => 'Sync users out',
        'hooks' => 'Inline hooks',
        'vault' => 'Token vault',
        'sod-policies' => 'Role conflicts',
        'audit' => 'Activity log',
    ];

    // A capability that serves BOTH console planes lives at
    // `livewire/console/<name>/index` instead of a single `livewire/<route>` page. The
    // promise the nav label makes is the same either way, so the sweep follows the
    // component rather than losing the page the moment it is merged.
    $mergedViews = [
        'provisioning' => 'console/provisioning/index',
    ];

    $labels = [];

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            $labels[$page->route] = $page->label;
        }
    }

    foreach ($expected as $route => $label) {
        expect($labels[$route] ?? null)->toBe($label);

        $view = file_get_contents(resource_path('views/livewire/'.($mergedViews[$route] ?? $route).'.blade.php'));

        expect($view)->toContain("['title' => '{$label}']")
            ->and($view)->toContain("<x-page-header title=\"{$label}\"");
    }
});
