<?php

declare(strict_types=1);

namespace App\SignalsData;

use App\Platform\Impersonation;
use App\Platform\Radar\RadarPseudonyms;
use Carbon\CarbonImmutable;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final readonly class IdentityCapture
{
    private const string ATTEMPT = 'signals.source.attempt';

    public function __construct(private SourceLedger $ledger, private IdentityContext $context, private RadarPseudonyms $pseudonyms) {}

    public function begin(Request $request, ?string $subject = null, ?string $email = null): void
    {
        if ($this->ledger->environment() === null) {
            return;
        }
        if (is_array($request->attributes->get(self::ATTEMPT))) {
            return;
        }
        $attempt = ['id' => (string) Str::uuid(), 'target' => $email === null ? null : $this->pseudonyms->subject($email), 'environment' => $this->ledger->environment(), 'issued_at' => CarbonImmutable::now()->getTimestamp(), 'subject' => $subject];
        $request->attributes->set(self::ATTEMPT, $attempt);
        if ($request->hasSession()) {
            $request->session()->put(self::ATTEMPT, $attempt);
        }
        $this->authentication($request, $attempt, 'pending', $subject);
    }

    public function failed(Request $request, ?string $subject = null, string $reason = 'invalid_credentials'): void
    {
        $attempt = $this->attempt($request);
        if ($attempt === null) {
            return;
        }
        $this->authentication($request, $attempt, 'failed', $subject, reason: $reason);
        $this->clear($request);
    }

    public function started(Session $session, Request $request): void
    {
        if ($this->ledger->environment() === null) {
            return;
        }
        $pending = $this->attempt($request);
        $email = $pending !== null && $pending['target'] !== null ? app(Subjects::class)->find($session->user_id)?->email : null;
        if ($pending === null || ($pending['subject'] !== null && $pending['subject'] !== $session->user_id)
            || ($pending['target'] !== null && ($email === null || ! hash_equals($pending['target'], $this->pseudonyms->subject($email))))) {
            $this->clear($request);
            $this->begin($request, $session->user_id);
        }
        $attempt = $this->attempt($request);
        if ($attempt !== null) {
            $this->authentication($request, $attempt, 'succeeded', $session->user_id, $session);
        }
        $this->session($session, 'started', $request, $attempt['id'] ?? null);
        $this->clear($request);
    }

    public function session(Session $session, string $action, Request $request, ?string $attempt = null): void
    {
        if ($this->ledger->environment() === null) {
            return;
        }
        $this->ledger->append(new SourceFact(
            'session:'.$session->id.':'.$action, 'identity.session', [
                'subject' => $this->subject($session->user_id), 'session_id' => $session->id, 'action' => $action,
                'context' => $action === 'started' ? $this->context->collect($request, $session->ip, $session->user_agent) : $this->context->collect($request), 'amr' => array_values($session->amr ?? []),
                'aal' => self::aal(array_values($session->amr ?? [])), 'reason' => $action, 'application_id' => 'cbox-id', 'attempt_id' => $attempt,
            ], CarbonImmutable::now(), [$session->user_id], $session->organization_id,
        ));
    }

    public function activity(Session $session, Request $request): void
    {
        if ($this->ledger->environment() === null) {
            return;
        }
        $route = $request->route();
        if (! $route instanceof Route || $route->getName() === null) {
            return;
        }
        $activity = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'user_action' : 'background';
        if (app(Impersonation::class)->active() !== null) {
            $activity = 'impersonation';
        }
        $this->ledger->append(new SourceFact((string) Str::uuid(), 'identity.activity', [
            'subject' => $this->subject($session->user_id), 'session_id' => $session->id,
            'context' => $this->context->collect($request), 'activity' => $activity,
            'application_id' => 'cbox-id', 'resource_class' => $route->getName(),
        ], CarbonImmutable::now(), [$session->user_id], $session->organization_id));
    }

    public function token(RefreshToken $token, string $action, Request $request): void
    {
        if ($this->ledger->environment() === null) {
            return;
        }
        $subject = $token->user_id;
        $this->ledger->append(new SourceFact(
            'refresh:'.$token->id.':'.$action, 'identity.token', [
                'subject' => $subject === null ? ['namespace' => $this->namespace(), 'type' => 'service_account', 'id' => $token->client_id] : $this->subject($subject),
                // Database identifiers, never the token, its digest or its encrypted successor.
                'token_reference' => $token->id, 'family_reference' => $token->family_id,
                'application_id' => $token->client_id, 'action' => $action,
                'scopes' => array_values($token->scopes), 'context' => $this->context->collect($request), 'issuer_verified' => true,
            ], CarbonImmutable::now(), $subject === null ? [] : [$subject], $token->organization_id,
        ));
    }

    public function account(string $subject, string $action, Request $request, ?string $organization = null): void
    {
        if ($this->ledger->environment() === null) {
            return;
        }
        $this->ledger->append(new SourceFact((string) Str::uuid(), 'identity.account', [
            'subject' => $this->subject($subject), 'action' => $action, 'attempt_id' => $this->attempt($request)['id'] ?? null,
            'context' => $this->context->collect($request), 'actor' => null,
        ], CarbonImmutable::now(), [$subject], $organization));
    }

    /** @param array{id: string, target: string|null, environment: string, issued_at: int, subject: string|null} $attempt */
    private function authentication(Request $request, array $attempt, string $outcome, ?string $subject, ?Session $session = null, ?string $reason = null): void
    {
        $this->ledger->append(new SourceFact('auth:'.$attempt['id'].':'.$outcome, 'identity.authentication', [
            'attempt_id' => $attempt['id'], 'subject' => $subject === null ? null : $this->subject($subject),
            'stage' => $outcome === 'pending' ? 'attempted' : 'completed', 'outcome' => $outcome,
            'context' => $this->context->collect($request, $session?->ip, $session?->user_agent), 'session_id' => $session?->id,
            'amr' => array_values($session->amr ?? []), 'aal' => $session === null ? 0 : self::aal(array_values($session->amr ?? [])),
            'challenge_id' => null, 'failure_class' => $reason, 'application_id' => 'cbox-id', 'target_reference' => $attempt['target'],
        ], CarbonImmutable::now(), array_values(array_filter([$subject, $attempt['target'] === null ? null : 'target:'.$attempt['target']], is_string(...))), $session?->organization_id));
    }

    /** @return array{id: string, target: string|null, environment: string, issued_at: int, subject: string|null}|null */
    private function attempt(Request $request): ?array
    {
        $attempt = $request->attributes->get(self::ATTEMPT);
        if (! is_array($attempt) && $request->hasSession()) {
            $attempt = $request->session()->get(self::ATTEMPT);
        }
        if (! is_array($attempt) || ! is_string($attempt['id'] ?? null) || ($attempt['environment'] ?? null) !== $this->ledger->environment()
            || ! is_int($attempt['issued_at'] ?? null) || $attempt['issued_at'] < CarbonImmutable::now()->subMinutes(15)->getTimestamp() || $attempt['issued_at'] > CarbonImmutable::now()->getTimestamp()
            || ! array_key_exists('subject', $attempt) || ($attempt['subject'] !== null && ! is_string($attempt['subject']))
            || ! array_key_exists('target', $attempt) || (! is_string($attempt['target']) && $attempt['target'] !== null) || ! is_string($attempt['environment'] ?? null)) {
            return null;
        }

        return ['id' => $attempt['id'], 'target' => $attempt['target'], 'environment' => $attempt['environment'], 'issued_at' => $attempt['issued_at'], 'subject' => $attempt['subject']];
    }

    private function clear(Request $request): void
    {
        $request->attributes->remove(self::ATTEMPT);
        if ($request->hasSession()) {
            $request->session()->forget(self::ATTEMPT);
        }
    }

    private function namespace(): string
    {
        return 'cbox-id:'.($this->ledger->environment() ?? 'disabled');
    }

    /** @return array{namespace: string, type: string, id: string} */
    private function subject(string $id): array
    {
        return ['namespace' => $this->namespace(), 'type' => 'user', 'id' => $id];
    }

    /** @param list<string> $amr */
    private static function aal(array $amr): int
    {
        return in_array('mfa', $amr, true) || (in_array('pwd', $amr, true) && array_intersect($amr, ['otp', 'totp', 'hwk']) !== []) ? 2 : 1;
    }
}
