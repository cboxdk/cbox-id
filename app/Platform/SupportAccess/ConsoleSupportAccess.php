<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess;

use App\Platform\EnvironmentAdminAuth;
use App\Platform\SupportAccess\Contracts\SupportAccess;
use App\Platform\SupportAccess\ValueObjects\ActiveSupportSession;
use App\Platform\SupportAccess\ValueObjects\SupportApp;
use App\Platform\SupportAccess\ValueObjects\SupportSignInRequest;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\SupportSessions;
use Cbox\Id\OAuthServer\Enums\SupportActorKind;
use Cbox\Id\OAuthServer\Enums\SupportSessionRefusal;
use Cbox\Id\OAuthServer\Exceptions\SupportSessionRefused;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\SupportSession;
use Cbox\Id\OAuthServer\Support\GrantPolicy;
use Cbox\Id\OAuthServer\SupportSessionService;
use Cbox\Id\OAuthServer\ValueObjects\NewSupportSession;
use Cbox\Id\OAuthServer\ValueObjects\SupportCodeRequest;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Database\Eloquent\Builder;

/**
 * {@see SupportAccess} over the framework's {@see SupportSessions}.
 */
class ConsoleSupportAccess implements SupportAccess
{
    public function __construct(
        private readonly SupportSessions $sessions,
        private readonly SupportHandoff $handoff,
        private readonly EnvironmentAdminAuth $admins,
        private readonly Subjects $subjects,
        private readonly PlatformRoot $platformRoot,
    ) {}

    public function eligibleApps(): array
    {
        $apps = [];

        foreach ($this->eligibleQuery()->orderBy('name')->get() as $client) {
            $app = $this->toApp($client);

            if ($app !== null) {
                $apps[] = $app;
            }
        }

        return $apps;
    }

    public function isEligible(string $clientId): bool
    {
        $client = $this->eligibleQuery()->where('client_id', $clientId)->first();

        return $client !== null && $this->toApp($client) !== null;
    }

    public function maxMinutes(): int
    {
        $configured = config('cbox-id.oauth.support_sessions.max_ttl', SupportSessionService::MAX_TTL_SECONDS);
        $seconds = is_numeric($configured) ? (int) $configured : SupportSessionService::MAX_TTL_SECONDS;

        // The framework's own bounds: the configuration can only LOWER the one-hour ceiling,
        // and nothing goes under a minute. Offering more than it grants would be a promise
        // the session then quietly breaks.
        $seconds = max(SupportSessionService::MIN_TTL_SECONDS, min(SupportSessionService::MAX_TTL_SECONDS, $seconds));

        return intdiv($seconds, 60);
    }

    public function start(SupportSignInRequest $request): string
    {
        // The console's own rule, on top of the framework's: the app must be one a browser
        // can be SENT to. The framework's eligibility (first-party, environment-owned,
        // authorization code) is asked again inside begin().
        $client = $this->eligibleQuery()->where('client_id', $request->clientId)->first();
        $app = $client === null ? null : $this->toApp($client);

        if ($app === null) {
            throw SupportSessionRefused::because(SupportSessionRefusal::ClientNotEligible);
        }

        $started = $this->sessions->begin(new NewSupportSession(
            actorId: $request->actorId,
            // Asserted by the caller, which is the only party that can know: the console
            // route this is reached from is behind the environment-admin gate.
            actorKind: SupportActorKind::EnvironmentAdmin,
            targetUserId: $request->targetUserId,
            organizationId: $request->organizationId,
            clientId: $request->clientId,
            reason: $request->reason,
            ttlSeconds: min($request->minutes, $this->maxMinutes()) * 60,
        ));

        $this->handoff->put($request->clientId, $started->session->id, $request->actorId);

        return $app->launchUrl;
    }

    public function activeForUser(string $userId): array
    {
        return $this->describe(SupportSession::query()->active()->where('target_user_id', $userId));
    }

    public function activeForOrganization(string $organizationId): array
    {
        return $this->describe(SupportSession::query()->active()->where('organization_id', $organizationId));
    }

    public function end(string $sessionId, string $endedBy): bool
    {
        // Through the environment-scoped model first: a session id from another
        // environment is nothing here, and the framework's end() is not asked about it.
        if (! SupportSession::query()->whereKey($sessionId)->exists()) {
            return false;
        }

        $this->sessions->end($sessionId, $endedBy);
        $this->handoff->forgetSession($sessionId);

        return true;
    }

    public function codeFor(string $clientId, string $redirectUri, string $codeChallenge, ?string $nonce): ?string
    {
        $entry = $this->handoff->for($clientId);

        if ($entry === null) {
            return null;
        }

        // The administrator who started it, still signed in to THIS environment's console
        // — asked of the live session, not of the note. Anybody else at this browser, or
        // the same person signed out or revoked, gets an ordinary sign-in.
        $actor = $this->admins->subjectId();

        if ($actor === null || $actor !== $entry->actorId) {
            $this->handoff->forgetApp($clientId);

            return null;
        }

        try {
            // The framework binds the actor into the session lookup and re-checks the app
            // and the redirect URI; the code is bound to the app's own PKCE challenge.
            return $this->sessions->issueCode(
                $entry->sessionId,
                $actor,
                new SupportCodeRequest($redirectUri, $codeChallenge, $nonce),
            );
        } catch (SupportSessionRefused) {
            // Ended or expired: this browser no longer holds a session for the app.
            $this->handoff->forgetApp($clientId);

            return null;
        }
    }

    /**
     * The apps support sessions may reach, as the framework decides it — first-party and
     * owned by the environment — narrowed further in {@see toApp()}.
     *
     * @return Builder<Client>
     */
    private function eligibleQuery(): Builder
    {
        return Client::query()
            ->where('first_party', true)
            ->whereNull('organization_id');
    }

    /**
     * The app, or null when a support session cannot reach it from a browser: no
     * authorization-code grant, or no web redirect URI to start its sign-in from.
     */
    private function toApp(Client $client): ?SupportApp
    {
        if (! GrantPolicy::allows($client, 'authorization_code')) {
            return null;
        }

        $launch = $this->launchUrl($client);

        return $launch === null ? null : new SupportApp($client->client_id, $client->name, $launch);
    }

    /**
     * The app's own entry point: the origin of its first web redirect URI — the rule the
     * app launcher uses, so the console and the launcher send a browser to the same place.
     * A native app's private-use scheme is no address a browser can be sent to.
     */
    private function launchUrl(Client $client): ?string
    {
        foreach (array_values($client->redirect_uris) as $uri) {
            $parts = parse_url($uri);

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
                || ! in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
                continue;
            }

            return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        return null;
    }

    /**
     * @param  Builder<SupportSession>  $query
     * @return list<ActiveSupportSession>
     */
    private function describe(Builder $query): array
    {
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $targets = [];
        foreach ($this->subjects->findMany($rows->map(static fn (SupportSession $row): string => $row->target_user_id)->unique()->values()->all()) as $subject) {
            $targets[$subject->id] = $subject->name ?? $subject->email;
        }

        $organizations = Organization::query()
            ->whereIn('id', $rows->map(static fn (SupportSession $row): string => $row->organization_id)->unique()->values()->all())
            ->pluck('name', 'id')
            ->all();

        $apps = [];
        foreach (Client::query()->whereIn('client_id', $rows->map(static fn (SupportSession $row): string => $row->client_id)->unique()->values()->all())->get() as $client) {
            $apps[$client->client_id] = [$client->name, $this->launchUrl($client)];
        }

        // The administrators are subjects of the PLATFORM ROOT, not of this environment,
        // so their names are read there — under this environment's scope they are nobody.
        $actorIds = $rows->map(static fn (SupportSession $row): string => $row->actor_id)->unique()->values()->all();
        $resolved = $this->platformRoot->run(function () use ($actorIds): array {
            $labels = [];

            foreach ($this->subjects->findMany($actorIds) as $subject) {
                $labels[$subject->id] = $subject->name ?? $subject->email;
            }

            return $labels;
        });
        $actors = is_array($resolved) ? $resolved : [];

        $out = [];

        foreach ($rows as $session) {
            $organizationName = $organizations[$session->organization_id] ?? null;

            $out[] = new ActiveSupportSession(
                id: $session->id,
                targetUserId: $session->target_user_id,
                targetLabel: $targets[$session->target_user_id] ?? null,
                organizationId: $session->organization_id,
                organizationName: is_string($organizationName) ? $organizationName : null,
                clientId: $session->client_id,
                appName: $apps[$session->client_id][0] ?? null,
                launchUrl: $apps[$session->client_id][1] ?? null,
                reason: $session->reason,
                actorLabel: $actors[$session->actor_id] ?? null,
                expiresAt: $session->expires_at->toImmutable(),
            );
        }

        return $out;
    }
}
