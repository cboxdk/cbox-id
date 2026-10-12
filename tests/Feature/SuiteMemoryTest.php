<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/*
|--------------------------------------------------------------------------
| What one test leaves behind for the next
|--------------------------------------------------------------------------
|
| The suite boots a fresh application for every test and runs ~5,900 of them in a
| process. Anything a test leaves reachable is carried by every test after it, and
| the bill arrives at the end of the run, in whichever test happens to tip the
| memory limit — the console crawls, last time — where it reads as a problem with
| that test.
|
| The commonest way to leave something behind is a static that captures the
| application: a registry, a macro or a resolver callback that closes over `$app`,
| registered on every boot and never released. Each test then keeps the whole
| container of the test before it alive, and so on down the run.
|
| These three tests are ordered and live in one file, so a parallel worker runs them
| together. The FIRST is never asserted on: the first application a process boots
| runs the migrations, and Laravel's console prompts keep the command that ran them
| (and so that application) in a static for the rest of the process. That is one
| application, once, not a leak that grows.
*/

final class SuiteMemoryProbe
{
    /** @var WeakReference<Application>|null */
    public static ?WeakReference $previous = null;
}

it('boots an application (recorded, not asserted on)', function (): void {
    SuiteMemoryProbe::$previous = WeakReference::create($this->app);

    expect($this->app)->toBeInstanceOf(Application::class);
});

it('boots another application, which is the one that must not outlive its test', function (): void {
    SuiteMemoryProbe::$previous = WeakReference::create($this->app);

    expect($this->app)->toBeInstanceOf(Application::class);
});

it('has let the previous test\'s application go', function (): void {
    gc_collect_cycles();

    expect(SuiteMemoryProbe::$previous?->get())->toBeNull(
        'the application booted by the previous test is still reachable — something static is holding the container (a macro, registry or callback that closes over $app), and every test will now carry the one before it',
    );
});

/*
| CI's PHP is tuned in .github/php/ci.ini: Xdebug off and OPcache on for the CLI. The dev
| image ships Xdebug in coverage mode, which made the suite ~4.5x slower; OPcache keeps
| the per-boot recompilation of config, routes and compiled views off the heap. Both are
| invisible when they quietly stop applying — a renamed step, a new job that forgot it —
| so on GitHub Actions the suite checks it is running on the PHP it was tuned for.
*/
it('runs on the tuned PHP in CI', function (): void {
    $xdebugModes = function_exists('xdebug_info') ? xdebug_info('mode') : [];
    $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

    expect($xdebugModes)->toBe([], 'Xdebug is active in CI — copy .github/php/ci.ini into conf.d in this job (ci.yml)')
        ->and(is_array($opcache) && ($opcache['opcache_enabled'] ?? false) === true)->toBeTrue('OPcache is off for the CLI in CI — copy .github/php/ci.ini into conf.d in this job (ci.yml)');
})->skip(getenv('GITHUB_ACTIONS') !== 'true', 'checks the CI runner\'s PHP; there is nothing to check on a developer\'s machine');
