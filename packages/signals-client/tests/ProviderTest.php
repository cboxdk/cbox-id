<?php

use Cbox\Signals\Contracts\ManagedIntegration;
use Cbox\Signals\Contracts\Signals;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\SignalsServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Http\Client\Factory;

function providerContainer(array $config): Container
{
    $app = new class extends Container
    {
        public function runningInConsole(): bool
        {
            return false;
        }
    };
    Container::setInstance($app);
    $app->instance('config', new Repository(['signals' => $config]));
    $app->alias('config', ConfigContract::class);
    $app->instance(Factory::class, new Factory);
    $app->instance('db', new class
    {
        public function connection(): never
        {
            throw new LogicException('not used');
        }
    });
    (new SignalsServiceProvider($app))->register();

    return $app;
}

it('accepts the integer timeout the environment delivers and falls back on nonsense', function (): void {
    foreach ([2, '2', 2.5, 'fast', -1, null] as $timeout) {
        $app = providerContainer(['url' => 'https://signals.test', 'token' => 'key', 'timeout' => $timeout, 'managed' => ['token' => null]]);
        expect($app->make(Signals::class))->toBeInstanceOf(Signals::class)
            ->and($app->make(ManagedIntegration::class))->toBeInstanceOf(ManagedIntegration::class);
    }
    $app = providerContainer(['url' => null, 'token' => null, 'timeout' => null, 'webhook_secret' => null, 'managed' => ['token' => null]]);
    expect(fn () => $app->make(ManagedIntegration::class)->enrollment('cbox_id', 'env'))->toThrow(SignalsFailure::class, 'not_configured');
    Container::setInstance(null);
});
