<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\DeviceLabel;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Where you are signed in, what can act as you, and what has happened to your account.
 *
 * THE THING YOU CANNOT REVOKE IS THE THING YOU CANNOT SEE. Before this page, a person
 * signed in here could see the session they were holding and a COUNT of the others — one
 * button, all or nothing — and could not see connected applications at all. So a device
 * grant approved on a TV, a CLI or somebody else's laptop held refresh tokens for as long
 * as it liked, invisibly, and the only lever was to sign out everywhere.
 *
 * That is backwards for the flow it matters most in: RFC 8628 exists precisely for inputs
 * you do not control, which is the same as saying you will not have that device later.
 *
 * IT IS THE PERSON'S OWN VIEW, and every read on it is keyed to the signed-in subject
 * rather than to a parameter. There is nothing here an administrator does not already have
 * on the user detail page; what was missing is that the person themselves could not.
 *
 * WHY ACTIVITY IS ON THE SAME PAGE. "Sign out that session" and "was that me?" are one
 * question asked twice. Somebody who sees a session they do not recognise immediately wants
 * to know when it signed in and from where, and a separate page for it would mean noticing
 * the problem in one place and investigating it in another.
 */
new #[Layout('components.layouts.app', ['title' => 'Sessions & activity'])] class extends Component
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
     * for "did somebody else get in" is not helped by rows about a role assignment. Each
     * of these is something that happened TO their ability to sign in.
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

    public function boot(): void
    {
        if (! app(CurrentUser::class)->check()) {
            throw new AuthorizationException('Sign in to see your sessions.');
        }
    }

    /**
     * Sign one session out.
     *
     * The id is checked against THIS subject's sessions before anything is revoked — a
     * component action is a POST anybody signed in can make, and an id from the page is an
     * id from the client.
     */
    public function revokeSession(SessionManager $sessions, string $id): void
    {
        $me = app(CurrentUser::class);

        $session = Session::query()
            ->where('id', $id)
            ->where('user_id', $me->id())
            ->whereNull('revoked_at')
            ->first();

        if (! $session instanceof Session) {
            return;
        }

        $sessions->revoke($session->id);

        // Signing out the one you are holding is a legitimate thing to want, and it means
        // what it says: the next request has no session, so send them to the door rather
        // than re-rendering a page they are no longer entitled to.
        if ($session->id === session()->get(PlatformAuth::SESSION_KEY)) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $this->dispatch('toast', message: 'Signed out of that session.');
    }

    /**
     * Withdraw one application's access.
     *
     * Not `revokeForUser()`, which signs the person out of everything: that is the right
     * answer to "my account is compromised" and the wrong one to "I do not use that CLI
     * any more", and offering only the blunt version is why people do not use either.
     */
    public function revokeApplication(RefreshTokens $tokens, string $clientId): void
    {
        $me = app(CurrentUser::class);

        $tokens->revokeForUserAndClient($me->id(), $clientId);

        $this->dispatch('toast', message: 'Access withdrawn. That application can no longer act as you.');
    }

    public function signOutEverywhereElse(SessionManager $sessions): void
    {
        $me = app(CurrentUser::class);
        $current = session()->get(PlatformAuth::SESSION_KEY);

        Session::query()
            ->where('user_id', $me->id())
            ->whereNull('revoked_at')
            ->when(is_string($current), fn ($query) => $query->whereKeyNot($current))
            ->pluck('id')
            ->each(function (mixed $id) use ($sessions): void {
                if (is_string($id)) {
                    $sessions->revoke($id);
                }
            });

        $this->dispatch('toast', message: 'Signed out everywhere else.');
    }

    /** @return array<string, mixed> */
    public function with(RefreshTokens $tokens): array
    {
        $me = app(CurrentUser::class);
        $currentId = session()->get(PlatformAuth::SESSION_KEY);

        $sessions = Session::query()
            ->where('user_id', $me->id())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            // The one they are holding first, then most recently active: a person opens
            // this page to find something they do not recognise, and "which of these is
            // me" is the question standing in the way of every other one.
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [is_string($currentId) ? $currentId : ''])
            ->orderByDesc('last_active_at')
            ->get();

        return [
            'sessions' => $sessions,
            'currentSessionId' => is_string($currentId) ? $currentId : null,
            'applications' => $tokens->connectedApplications($me->id()),
            // BY ACTOR **OR** BY TARGET, because the trail records the two honestly and
            // differently: a sign-in has the person as the ACTOR and the session as the
            // target, while a lockout or an administrator setting their password has them
            // as the TARGET. Asking only one of the two silently drops half the events —
            // and the half it dropped was every sign-in, which is the row people come here
            // to read.
            'activity' => AuditEntry::query()
                ->where(fn ($query) => $query
                    ->where('actor_id', $me->id())
                    ->orWhere(fn ($inner) => $inner->where('target_type', 'user')->where('target_id', $me->id())))
                ->whereIn('action', array_keys(self::ACTIVITY))
                // By the chain's own sequence, which is monotonic per environment — two
                // events in the same second are still ordered, and a clock that moves
                // cannot reorder somebody's history.
                ->orderByDesc('sequence')
                ->limit(self::ACTIVITY_ROWS)
                ->get(),
            'labels' => self::ACTIVITY,
        ];
    }
}; ?>

<div>
    <x-page-header
        title="Sessions & activity"
        subtitle="Where you are signed in, what can act as you, and what has happened to your account. If you see something you do not recognise, sign it out and change your password." />

    {{-- Sessions --}}
    <section class="cbx-panel mt-6">
        <div class="cbx-panel-header">
            <div>
                <h2 class="cbx-panel-title">Where you are signed in</h2>
                <p class="cbx-panel-desc">Every browser and device holding a live session as you.</p>
            </div>
            @if ($sessions->count() > 1)
                <button type="button" wire:click="signOutEverywhereElse" class="btn btn-ghost btn-sm"
                        wire:confirm="Sign out of every session except this one?"
                        wire:loading.attr="disabled" wire:target="signOutEverywhereElse">
                    <span class="spinner" wire:loading wire:target="signOutEverywhereElse" aria-hidden="true"></span>
                    Sign out everywhere else
                </button>
            @endif
        </div>

        <div class="cbx-panel-body" style="display:flex;flex-direction:column;gap:8px">
            @foreach ($sessions as $s)
                @php $isCurrent = $s->id === $currentSessionId; @endphp
                <div wire:key="session-{{ $s->id }}" class="flex items-center gap-3 rounded-lg border px-3 py-2"
                     style="border-color:{{ $isCurrent ? 'var(--accent-edge)' : 'var(--border)' }};{{ $isCurrent ? 'background:var(--accent-soft)' : '' }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm truncate">
                            {{ \App\Platform\DeviceLabel::for($s->user_agent) }}
                            @if ($isCurrent)
                                <span class="badge badge-success ml-1">This device</span>
                            @endif
                            @if (in_array('impersonation', $s->amr ?? [], true))
                                {{-- Said out loud: somebody is acting as you with permission,
                                     and you are entitled to know it is happening. --}}
                                <span class="badge badge-warn ml-1">Support session</span>
                            @endif
                        </p>
                        <p class="text-xs truncate" style="color:var(--faint)">
                            {{ $s->ip ?? 'no address recorded' }} ·
                            signed in {{ $s->created_at?->diffForHumans() ?? 'unknown' }} ·
                            last active {{ $s->last_active_at?->diffForHumans() ?? 'never' }}
                        </p>
                    </div>
                    {{-- The accessible name names the DEVICE. A screen-reader user pulling
                         up the button list on a page with six sessions otherwise gets six
                         buttons called "Sign out" and no way to tell which is which — on
                         the one control that can sign them out of the browser they are
                         holding. The visible label stays short. --}}
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)"
                            aria-label="Sign out {{ $isCurrent ? 'this device' : \App\Platform\DeviceLabel::for($s->user_agent) }}"
                            wire:click="revokeSession('{{ $s->id }}')"
                            wire:confirm="{{ $isCurrent ? 'Sign out of this device? You will have to sign in again.' : 'Sign out of that session?' }}">
                        Sign out
                    </button>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Connected applications --}}
    <section class="cbx-panel mt-6">
        <div class="cbx-panel-header">
            <div>
                <h2 class="cbx-panel-title">Applications that can act as you</h2>
                <p class="cbx-panel-desc">Anything you approved — including a device or a command line you signed in from.</p>
            </div>
        </div>

        <div class="cbx-panel-body" style="display:flex;flex-direction:column;gap:8px">
            @forelse ($applications as $app)
                @php
                    // Built here rather than inline: a Blade `:attr` value cannot itself
                    // contain the quotes the action string needs around the id, and
                    // interpolating it inline renders the attribute as text on the page.
                    $withdrawAction = "revokeApplication('{$app->clientId}')";
                @endphp
                <div wire:key="app-{{ $app->clientId }}" class="flex items-center gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm truncate">
                            {{ $app->name }}
                            @if ($app->actsOffline())
                                {{-- The distinction that matters to a person: this one keeps
                                     working when they are not there. --}}
                                <span class="badge badge-info ml-1">Works when you are away</span>
                            @endif
                        </p>
                        <p class="text-xs truncate" style="color:var(--faint)">
                            approved {{ $app->firstAuthorizedAt?->diffForHumans() ?? 'unknown' }} ·
                            last used {{ $app->lastUsedAt?->diffForHumans() ?? 'never' }}
                        </p>
                        @if ($app->scopes !== [])
                            <p class="text-xs mt-1 mono truncate" style="color:var(--faint)">{{ implode(' · ', $app->scopes) }}</p>
                        @endif
                    </div>
                    {{-- Type-to-confirm: this destroys a credential somebody else is holding,
                         and it cannot be undone from here — the application has to ask again. --}}
                    <x-confirm-delete
                        :name="$app->name"
                        :action="$withdrawAction"
                        label="Withdraw access"
                        verb="Withdraw"
                        trigger-class="btn btn-ghost btn-sm shrink-0"
                        consequence="It stops being able to act as you immediately, and has to ask for your approval again." />
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
                    <h3>Nothing can act as you</h3>
                    <p>Applications you approve — a command line, a device, another product signing you in with Cbox ID — appear here, and you can withdraw any of them.</p>
                </div>
            @endforelse
        </div>
    </section>

    {{-- Activity --}}
    <section class="cbx-panel mt-6">
        <div class="cbx-panel-header">
            <div>
                <h2 class="cbx-panel-title">Recent activity</h2>
                {{-- Guarded: "The most recent 0 events." rendered directly above the empty state that
                     says nothing is recorded yet. --}}
                <p class="cbx-panel-desc">Sign-ins and changes to how you sign in.@if (count($activity) > 0) The most recent {{ count($activity) }} {{ \Illuminate\Support\Str::plural('event', count($activity)) }}.@endif</p>
            </div>
        </div>

        <div class="cbx-panel-body">
            @forelse ($activity as $entry)
                <div wire:key="activity-{{ $entry->id }}" class="flex items-baseline gap-3 py-2 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm">{{ $labels[$entry->action] ?? $entry->action }}</p>
                        {{-- Only when there IS one. An audit entry written outside a request
                             — a scheduled job, a console command — has no address, and
                             printing "no address recorded" on every such row is a column of
                             noise between the reader and the rows that do carry one. --}}
                        @if ($entry->ip !== null)
                            <p class="text-xs" style="color:var(--faint)">from {{ $entry->ip }}</p>
                        @endif
                    </div>
                    {{-- `recorded_at`, which is the audit chain's own timestamp. The model
                         declares no Eloquent timestamps, so `created_at` is null on every
                         row and the column rendered as a line of em dashes. --}}
                    <time class="text-xs shrink-0" style="color:var(--faint)"
                          datetime="{{ $entry->recorded_at?->toIso8601String() }}"
                          title="{{ $entry->recorded_at?->format('M j, Y g:i A') }}">
                        {{ $entry->recorded_at?->diffForHumans() ?? '—' }}
                    </time>
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="audit" class="w-5 h-5" /></div>
                    <h3>Nothing recorded yet</h3>
                    <p>Sign-ins and changes to your password, passkeys and two-factor settings appear here.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>
