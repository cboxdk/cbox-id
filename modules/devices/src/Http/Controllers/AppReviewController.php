<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use App\Platform\CurrentUser;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

/** A self-service exercise of the normal approval path, with no token redemption. */
final class AppReviewController
{
    public function show(): View
    {
        $client = $this->reviewClient();
        $subject = app(CurrentUser::class)->id();

        return view('authenticator-review', [
            'email' => app(CurrentUser::class)->email(),
            'connected' => $this->hasDevice($subject),
            'latest' => BackchannelAuthRequest::query()
                ->where('client_id', $client->client_id)
                ->where('user_id', $subject)
                ->latest('id')->first(),
        ]);
    }

    public function send(BackchannelAuthentication $backchannel): RedirectResponse
    {
        $client = $this->reviewClient();
        $subject = app(CurrentUser::class)->id();

        if (! $this->hasDevice($subject)) {
            return to_route('account.authenticator.review.show')->withErrors(['review' => 'Connect your iPhone first, then return here.']);
        }

        // Refreshes and ordinary double taps keep the same pending exercise.
        if (BackchannelAuthRequest::query()
            ->where('client_id', $client->client_id)->where('user_id', $subject)
            ->where('status', GrantPollStatus::Pending)->where('expires_at', '>', now())->exists()) {
            return to_route('account.authenticator.review.show');
        }

        $key = 'authenticator-review:'.$client->environment_id.':'.$subject;
        if (RateLimiter::increment($key, 60) > 3) {
            return to_route('account.authenticator.review.show')->withErrors(['review' => 'Please wait a minute before starting another test.']);
        }

        // Never accept a subject, client, message or scope from the browser. The polling
        // secret is deliberately discarded: this page cannot exchange an approval for tokens.
        $backchannel->request($client, ['openid'], $subject, 'REVIEW '.random_int(1000, 9999), null, 300);

        return to_route('account.authenticator.review.show');
    }

    private function reviewClient(): Client
    {
        $environment = config('id-devices.app_review.environment_id');
        $subject = config('id-devices.app_review.subject_id');
        $client = config('id-devices.app_review.client_id');

        abort_unless(config('id-devices.enabled')
            && is_string($environment) && $environment !== ''
            && is_string($subject) && $subject !== ''
            && is_string($client) && $client !== ''
            && app(EnvironmentContext::class)->current()?->environmentKey() === $environment
            && app(CurrentUser::class)->check()
            && app(CurrentUser::class)->id() === $subject, 404);

        return Client::query()->where('client_id', $client)->firstOrFail();
    }

    private function hasDevice(string $subject): bool
    {
        return Device::query()->where('subject_id', $subject)->where('status', DeviceStatus::Active)->exists();
    }
}
