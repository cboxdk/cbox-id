<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Radar\RadarDevices;
use App\Platform\Signals\SignalsAuthenticationAssessment;
use App\Platform\Signals\SignalsLoginEvents;
use Cbox\Id\Identity\Contracts\AuthenticationAssessment;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Signals\HttpSignals;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class SignalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! config()->boolean('signals-auth.enabled')) {
            return;
        }
        $url = config()->string('signals-auth.url');
        $token = config()->string('signals-auth.token');
        $environment = config()->string('signals-auth.id_environment');
        $parts = parse_url($url);
        if ($environment === '' || $token === '' || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Signals authentication requires an explicit ID environment, HTTPS API origin and scoped API token.');
        }
        $this->app->extend(EventBus::class, static fn (EventBus $events, Application $app): EventBus => new SignalsLoginEvents(
            $events, $app->make(EnvironmentContext::class), $app->make(RadarDevices::class),
            static fn (): Request => $app->make(Request::class), $environment,
        ));
        $this->app->bind(AuthenticationAssessment::class, static fn (Application $app): AuthenticationAssessment => new SignalsAuthenticationAssessment(
            new HttpSignals($app->make(Factory::class), $url, $token, 0.080),
            $app->make(EnvironmentContext::class), $app->make(RadarDevices::class),
            $app->make(Request::class), $app->make(AuditLog::class), $environment,
            config()->integer('signals-auth.fresh_seconds'), config()->string('signals-auth.entity_namespace'),
        ));
    }
}
