<?php

declare(strict_types=1);

use App\Platform\Health\ProductionConfigDoctorCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;

/*
| The production settings whose defaults are right on a laptop and silently wrong in a
| container. None of them errors; the doctor is the only place they are said out loud.
*/

/** The doctor's verdicts in production, keyed by label. @return array<string, string> */
function productionVerdicts(array $config): array
{
    config($config);
    app()->detectEnvironment(fn (): string => 'production');

    $results = app(ProductionConfigDoctorCheck::class)->run();

    return collect($results)->mapWithKeys(fn (HealthResult $r): array => [$r->label => $r->status->value])->all();
}

/** A production configuration with nothing wrong in it. @return array<string, mixed> */
function soundProductionConfig(): array
{
    return [
        'mail.default' => 'smtp',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'queue.default' => 'redis',
        'logging.default' => 'stderr',
        'health.security.token' => 'probe-token',
    ];
}

it('reports nothing to fix outside production', function (): void {
    $results = app(ProductionConfigDoctorCheck::class)->run();

    expect($results)->toHaveCount(1)
        ->and($results[0]->status->value)->toBe('ok');
});

it('passes a sound production configuration', function (): void {
    expect(array_unique(array_values(productionVerdicts(soundProductionConfig()))))->toBe(['ok']);
});

it('fails production when mail goes to a log file', function (): void {
    expect(productionVerdicts([...soundProductionConfig(), 'mail.default' => 'log']))
        ->toHaveKey('Mail is not sent', 'fail');
});

it('fails production when the cache or sessions live in one process', function (): void {
    $verdicts = productionVerdicts([...soundProductionConfig(), 'cache.default' => 'file', 'session.driver' => 'file']);

    expect($verdicts)->toHaveKey('Cache is local to one process', 'fail')
        ->and($verdicts)->toHaveKey('Sessions are local to one process', 'fail');
});

it('fails production with no health token', function (): void {
    expect(productionVerdicts([...soundProductionConfig(), 'health.security.token' => null]))
        ->toHaveKey('No health token', 'fail');
});

it('warns when every log channel writes to the local disk, following stacks', function (): void {
    $verdicts = productionVerdicts([
        ...soundProductionConfig(),
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single'],
    ]);

    expect($verdicts)->toHaveKey('Logs are written to the local disk', 'warn');
});

it('warns when the queue runs inline', function (): void {
    expect(productionVerdicts([...soundProductionConfig(), 'queue.default' => 'sync']))
        ->toHaveKey('Queue runs inline', 'warn');
});
