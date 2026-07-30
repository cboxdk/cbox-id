<?php

declare(strict_types=1);

namespace Cbox\Id\Devices;

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Devices\Console\CreateAuthenticatorClientCommand;
use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Decorators\PushNotifyingBackchannelAuthentication;
use Cbox\Id\Devices\Listeners\SendSecurityAlert;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Support\DeviceCircuitBreaker;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Devices\Support\DeviceRateLimiter;
use Cbox\Id\Devices\Transports\FcmPushTransport;
use Cbox\Id\Devices\Transports\NullPushTransport;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Psr\Log\LoggerInterface;

/**
 * The Cbox ID devices module: a registry of a user's enrolled handsets, and a push
 * pipeline that notifies them.
 *
 * WHAT THIS IS FOR
 * ----------------
 * CIBA has always been able to ASK a user to approve something out of band —
 * CibaAuthenticationService persists the request and emits a domain event, with its own
 * docblock stating that the notification surface belongs to the host. Until now that
 * surface did not exist: the only way to learn an approval was waiting was to open the
 * console. This module is that surface's server half.
 *
 * WHY THE CIBA PUSH DOES NOT RIDE THE EVENT BUS
 * ---------------------------------------------
 * `oauth.backchannel_authentication_requested` goes to the transactional outbox, and
 * `EventDelivered` is dispatched by the relay on a schedule — every minute by default.
 * A CIBA request lives for 300 seconds. Spending up to a fifth of that window waiting
 * for a scheduler tick is not acceptable for something a person is sitting and waiting
 * on, so approvals are pushed from a decorator around BackchannelAuthentication instead
 * (see Decorators/PushNotifyingBackchannelAuthentication) and reach the handset in the
 * same request that created them.
 *
 * Security ALERTS take the opposite trade and ride the ordinary EventDelivered path:
 * "you signed in on a new device" is worth knowing, but not worth putting on the login
 * critical path.
 *
 * A LIMIT WORTH KNOWING BEFORE YOU EXTEND `alerts`
 * ------------------------------------------------
 * Domain events and audit actions are two different sets, and only the former is
 * reachable from a listener. The outbox carries `user.login`, `user.session_started`,
 * `user.session_revoked`, `identity.linked` and friends. It does NOT carry
 * `user.password_changed`, `user.locked_out`, `user.mfa_enrolled` or
 * `user.passkey_registered` — those exist only as audit-log actions. Alerting on them
 * would mean decorating AuditLog, which sits on the hash-chained audit hot path where
 * appends are serialised on a single anchor row; that is a real cost and it has not
 * been paid here. Adding an audit-only action to `id-devices.alerts` will silently do
 * nothing rather than fail, so check the outbox first.
 *
 * Vendored in-tree, but it registers itself the way an external package would — its own
 * provider, nav, routes, views and gates through the public console-kit sockets, with
 * no edit to app/. The module's scopes are set on the OAuth client by its own console
 * command rather than added to the host's ScopeCatalog: that catalog is a described
 * picker for humans filling in a form, and these scopes belong to exactly one
 * first-party client that a command provisions, so there is nothing to pick.
 */
class DevicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Inert by default. A deployment with no FCM credentials records notifications
        // and sends nothing, rather than failing at send time — a misconfigured push
        // must never be able to break a login.
        $this->app->bindIf(PushTransport::class, static function (Application $app): PushTransport {
            if (DeviceConfig::string('id-devices.transport') !== 'fcm') {
                return new NullPushTransport;
            }

            $credentials = DeviceConfig::string('id-devices.fcm.credentials');
            $projectId = DeviceConfig::string('id-devices.fcm.project_id');

            // Half-configured stays inert rather than becoming a runtime failure on the
            // login path. The console's delivery history still shows the attempts, so
            // the misconfiguration is visible rather than silent.
            if ($credentials === '' || $projectId === '') {
                return new NullPushTransport;
            }

            return new FcmPushTransport(
                $app->make(Http::class),
                $app->make(Cache::class),
                $credentials,
                $projectId,
                DeviceConfig::int('id-devices.fcm.timeout', 10),
            );
        });

        $this->app->singleton(DeviceCircuitBreaker::class);

        $this->app->bindIf(PushDispatcher::class, static fn (Application $app): QueuedPushDispatcher => new QueuedPushDispatcher(
            $app,
            $app->make(DeviceCircuitBreaker::class),
            $app->make(SecretBox::class),
        ));

        // The approval prompt has to be prompt. Decorating the contract rather than
        // listening for the domain event is what gets it onto the handset in the same
        // request instead of on the relay's next tick — see the decorator's docblock.
        // Registered here rather than in boot() so it is in place before anything can
        // resolve the singleton.
        $this->app->extend(
            BackchannelAuthentication::class,
            static fn (BackchannelAuthentication $inner, Application $app): PushNotifyingBackchannelAuthentication => new PushNotifyingBackchannelAuthentication(
                $inner,
                // The container, not a resolved dispatcher, so nothing in the push path
                // — transport, secret box, delivery pipeline — is constructed until a
                // request actually needs notifying. Construction happens inside the
                // decorator's own try/catch, which is where a fail-open guarantee needs
                // it; wiring it here would put it outside.
                $app,
                $app->make(LoggerInterface::class),
            ),
        );
    }

    public function boot(): void
    {
        // Loaded unconditionally, even when the module is disabled. A table that exists
        // and stays empty costs nothing, and it means enabling the feature later is a
        // config change rather than a migration window.
        // Registered before the routes load, since the route group names the limiter.
        DeviceRateLimiter::register();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'devices');
        Volt::mount([__DIR__.'/../resources/views/livewire']);
        $this->loadRoutesFrom(__DIR__.'/../routes/devices.php');

        // Security alerts take the opposite trade to approvals: they ride the ordinary
        // relay, so they are never on the login critical path.
        $this->app->make(Dispatcher::class)->listen(EventDelivered::class, SendSecurityAlert::class);

        Console::features()->register('devices', static fn (): bool => DeviceConfig::bool('id-devices.enabled', false));

        // Two surfaces, two areas. The fleet inventory (everyone's handsets, delivery
        // errors) is org administration and sits beside SSO; enrolment and one's own
        // devices are account security and sit beside passkeys, reachable by every
        // signed-in user — a member cannot even see the Sign-in area.
        Console::nav()->area('authentication')
            ->page('devices.index', 'Trusted devices', feature: 'devices', order: 50);
        Console::nav()->area('account')
            ->page('devices.mine', 'Trusted devices', feature: 'devices', order: 20);

        if ($this->app->runningInConsole()) {
            $this->commands([CreateAuthenticatorClientCommand::class]);
        }

        $this->scheduleRetrySweep();
        $this->warnAboutPerProcessCache();
    }

    /**
     * FCM access tokens are cached for an hour. An array cache is per-process, so every
     * worker re-exchanges the service-account JWT on every single send — two extra round
     * trips per push, and Google rate-limits its token endpoint long before FCM
     * complains about the sends. Worth saying out loud at boot rather than leaving to be
     * discovered as mysterious latency.
     */
    private function warnAboutPerProcessCache(): void
    {
        if (! DeviceConfig::bool('id-devices.enabled', false)
            || DeviceConfig::string('id-devices.transport') !== 'fcm'
            || DeviceConfig::string('cache.default') !== 'array') {
            return;
        }

        $this->app->make(LoggerInterface::class)->warning(
            'id-devices is using the FCM transport with CACHE_STORE=array; every worker will '
            .'re-exchange the service-account JWT on every push. Use a shared cache store.',
        );
    }

    /**
     * Re-enqueue due retries and rescue stranded notifications once a minute.
     *
     * The sweep is pure bookkeeping — it selects rows and queues jobs; the sends happen
     * on workers — so it is cheap enough to run unconditionally and is what makes the
     * "durable row before enqueue" promise real rather than aspirational.
     */
    private function scheduleRetrySweep(): void
    {
        if (! DeviceConfig::bool('id-devices.enabled', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $limit = max(1, DeviceConfig::int('id-devices.retry_limit', 50));

            $schedule->call(fn (): int => $this->app->make(PushDispatcher::class)->retryPending($limit))
                ->everyMinute()
                ->name('cbox-id:devices:retry')
                ->withoutOverlapping();

            // The delivery history grows with traffic, so it needs a sweep of its own.
            // Scheduled here rather than added to the host's routes/console.php prune
            // list, which the module does not edit — and run under withoutScope() for
            // the same reason the retry sweep is: MassPrunable builds a scoped query,
            // and a scheduler process has no environment, so it would delete nothing.
            $schedule->call(function (): void {
                $this->app->make(EnvironmentContext::class)->withoutScope(function (): void {
                    Artisan::call('model:prune', ['--model' => [PushNotification::class]]);
                });
            })
                ->daily()
                ->name('cbox-id:devices:prune')
                ->withoutOverlapping();
        });
    }
}
