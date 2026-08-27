<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use App\Platform\DeviceLabel;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\ValueObjects\ConnectedApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * WHERE YOU ARE SIGNED IN, WHAT CAN ACT AS YOU, AND WHAT HAS HAPPENED TO YOUR ACCOUNT.
 *
 * THE THING YOU CANNOT REVOKE IS THE THING YOU CANNOT SEE. Before this page, a person
 * signed in here could see the session they were holding and a COUNT of the others — one
 * button, all or nothing — and could not see connected applications at all. So a device
 * grant approved on a TV, a CLI or somebody else's laptop held refresh tokens for as long
 * as it liked, invisibly, and the only lever was to sign out everywhere. That is backwards
 * for the flow it matters most in: RFC 8628 exists precisely for inputs you do not
 * control, which is the same as saying you will not have that device later.
 *
 * IT IS THE PERSON'S OWN VIEW. Every read is keyed to the signed-in subject rather than to
 * a parameter, and every write re-resolves its target against that subject before touching
 * anything — an id that came from the page is an id that came from the client.
 *
 * WHY ACTIVITY IS ON THE SAME PAGE. "Sign out that session" and "was that me?" are one
 * question asked twice. Somebody who sees a session they do not recognise immediately wants
 * to know when it signed in and from where, and a separate page would mean noticing the
 * problem in one place and investigating it in another.
 */
final readonly class AccountActivityController extends PageController
{
    /**
     * How far back the activity list reaches.
     *
     * Bounded rather than paginated: this answers "is anything happening that is not me",
     * which is a question about the recent past. The full trail is the audit log, and an
     * organization administrator has it.
     */
    private const ACTIVITY_ROWS = 25;

    /**
     * The account events worth showing a person, and what to call them.
     *
     * NAMED RATHER THAN LISTED WHOLESALE. The audit trail carries everything, including
     * entries whose subject is the actor rather than the account — and a person scanning
     * for "did somebody else get in" is not helped by rows about a role assignment. Each of
     * these is something that happened TO their ability to sign in.
     */
    private const ACTIVITY = [
        'user.session_started' => 'Signed in',
        'user.sign_in_failed' => 'Failed sign-in attempt',
        'user.locked_out' => 'Account locked after repeated failures',
        'user.session_revoked' => 'A session was signed out',
        'user.sessions_revoked' => 'All other sessions were signed out',
        'user.password_set' => 'Password changed',
        'user.password_reset' => 'Password reset',
        'user.password_reset_requested' => 'Password reset requested',
        'user.password_set_by_admin' => 'Password set by an administrator',
        'user.mfa_enrolled' => 'Two-factor authentication turned on',
        'user.mfa_disabled' => 'Two-factor authentication turned off',
        'user.mfa_recovery_used' => 'A recovery code was used',
        'user.mfa_recovery_generated' => 'New recovery codes generated',
        'user.passkey_registered' => 'Passkey added',
        'user.passkey_authenticated' => 'Signed in with a passkey',
        'user.email_verified' => 'Email address verified',
        'user.magic_link_requested' => 'Sign-in link requested',
    ];

    public function index(RefreshTokens $tokens): Response
    {
        $subjectId = $this->subjectId();
        $currentId = $this->currentSessionId();

        $sessions = Session::query()
            ->where('user_id', $subjectId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            /*
             * The one they are holding first, then most recently active: a person opens
             * this page to find something they do not recognise, and "which of these is me"
             * is the question standing in the way of every other one.
             */
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$currentId ?? ''])
            ->orderByDesc('last_active_at')
            ->get();

        return $this->page('account/activity', 'Sessions & activity', [
            'sessions' => $sessions->map(function (Session $session) use ($currentId): array {
                $label = DeviceLabel::for($session->user_agent);
                $isCurrent = $session->id === $currentId;

                return [
                    'id' => $session->id,
                    'label' => $label,
                    'isCurrent' => $isCurrent,
                    // Said out loud: somebody is acting as you with permission, and you are
                    // entitled to know it is happening.
                    'isSupport' => in_array('impersonation', $session->amr ?? [], true),
                    'ip' => $session->ip,
                    'signedIn' => $session->created_at?->diffForHumans(),
                    'lastActive' => $session->last_active_at?->diffForHumans(),
                    'revokeHref' => route('account.sessions.revoke', $session->id),
                    /*
                     * The accessible name names the DEVICE. A screen-reader user pulling up
                     * the button list on a page with six sessions otherwise gets six buttons
                     * called "Sign out" and no way to tell which is which — on the one
                     * control that can sign them out of the browser they are holding.
                     */
                    'revokeLabel' => 'Sign out '.($isCurrent ? 'this device' : $label),
                ];
            })->values()->all(),
            'applications' => array_map(static fn (ConnectedApplication $app): array => [
                'clientId' => $app->clientId,
                'name' => $app->name,
                // The distinction that matters to a person: this one keeps working when
                // they are not there.
                'actsOffline' => $app->actsOffline(),
                'scopes' => $app->scopes,
                'approved' => $app->firstAuthorizedAt?->diffForHumans(),
                'lastUsed' => $app->lastUsedAt?->diffForHumans(),
                'withdrawHref' => route('account.applications.destroy', $app->clientId),
            ], $tokens->connectedApplications($subjectId)),
            'activity' => $this->activity($subjectId),
            'revokeOthersHref' => route('account.sessions.revoke-others'),
        ]);
    }

    /**
     * Sign one session out.
     *
     * The id is checked against THIS subject's sessions before anything is revoked. Under
     * Volt the same check had to be written by hand in the action because every action was
     * a POST anybody signed in could make; it is written by hand here too, and for the same
     * reason — a route parameter is still the client's.
     */
    public function revokeSession(string $session, SessionManager $sessions): RedirectResponse
    {
        $subjectId = $this->subjectId();

        $model = Session::query()
            ->where('id', $session)
            ->where('user_id', $subjectId)
            ->whereNull('revoked_at')
            ->first();

        // 404, not 403: another person's session id is not a control this reader is failing
        // to press, it is a row they have no business learning exists.
        abort_if(! $model instanceof Session, 404);

        $sessions->revoke($model->id);

        /*
         * Signing out the one you are holding is a legitimate thing to want, and it means
         * what it says: the next request has no session, so send them to the door rather
         * than back to a page they are no longer entitled to.
         */
        if ($model->id === $this->currentSessionId()) {
            return redirect()->route('login');
        }

        return back()->with('status', 'Signed out of that session.');
    }

    public function revokeOtherSessions(SessionManager $sessions): RedirectResponse
    {
        $subjectId = $this->subjectId();
        $current = $this->currentSessionId();

        Session::query()
            ->where('user_id', $subjectId)
            ->whereNull('revoked_at')
            ->when($current !== null, fn (Builder $query): Builder => $query->whereKeyNot($current))
            ->pluck('id')
            ->each(function (mixed $id) use ($sessions): void {
                if (is_string($id)) {
                    $sessions->revoke($id);
                }
            });

        return back()->with('status', 'Signed out everywhere else.');
    }

    /**
     * Withdraw one application's access.
     *
     * Not `revokeForUser()`, which signs the person out of everything: that is the right
     * answer to "my account is compromised" and the wrong one to "I do not use that CLI any
     * more", and offering only the blunt version is why people use neither.
     */
    public function revokeApplication(string $client, RefreshTokens $tokens): RedirectResponse
    {
        // Scoped to the acting subject by the call itself — the client id is all this takes
        // from the request, and it can only ever withdraw the reader's own grants.
        $tokens->revokeForUserAndClient($this->subjectId(), $client);

        return back()->with('status', 'Access withdrawn. That application can no longer act as you.');
    }

    /**
     * The reader's own recent history.
     *
     * BY ACTOR **OR** BY TARGET, because the trail records the two honestly and
     * differently: a sign-in has the person as the ACTOR and the session as the target,
     * while a lockout or an administrator setting their password has them as the TARGET.
     * Asking only one of the two silently drops half the events — and the half it dropped
     * was every sign-in, which is the row people come here to read.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(string $subjectId): array
    {
        $entries = AuditEntry::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('actor_id', $subjectId)
                ->orWhere(fn (Builder $inner): Builder => $inner
                    ->where('target_type', 'user')
                    ->where('target_id', $subjectId)))
            ->whereIn('action', array_keys(self::ACTIVITY))
            /*
             * By the chain's own sequence, which is monotonic per environment — two events
             * in the same second are still ordered, and a clock that moves cannot reorder
             * somebody's history.
             */
            ->orderByDesc('sequence')
            ->limit(self::ACTIVITY_ROWS)
            ->get();

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = [
                'id' => $entry->id,
                'label' => self::ACTIVITY[$entry->action] ?? $entry->action,
                // Only when there IS one. An entry written outside a request — a scheduled
                // job, a console command — has no address, and printing "no address
                // recorded" on every such row is a column of noise.
                'ip' => $entry->ip,
                /*
                 * `recorded_at`, which is the audit chain's own timestamp. The model
                 * declares no Eloquent timestamps, so `created_at` is null on every row and
                 * the column rendered as a line of em dashes.
                 */
                'at' => $entry->recorded_at?->diffForHumans(),
                'atIso' => $entry->recorded_at?->toIso8601String(),
                'atExact' => $entry->recorded_at?->format('M j, Y g:i A'),
            ];
        }

        return $rows;
    }

    /** The signed-in subject. The route requires one, so its absence is a 403 and not a page. */
    private function subjectId(): string
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        return $me->id();
    }

    private function currentSessionId(): ?string
    {
        $current = session()->get(PlatformAuth::SESSION_KEY);

        return is_string($current) ? $current : null;
    }
}
