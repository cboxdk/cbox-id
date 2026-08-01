<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Onboarding\SetupChecklist;
use App\Platform\Onboarding\SetupProgress;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The guided first run — the same measured steps as the dashboard card, given room
 * to explain themselves.
 *
 * It is a GUIDE, not a wizard: every step links out to the real page that does the
 * work, and nothing is gated behind finishing the one before it. A wizard that owns
 * the flow has to reimplement each screen inside itself, drifts from the real one the
 * moment either changes, and traps someone who only wanted step three. This stays a
 * map — it says what to do, in what order, and gets out of the way.
 */
new #[Layout('components.layouts.app', ['title' => 'Get started'])] class extends Component
{
    public function mount(): void
    {
        // Every step is an administrative action; a plain member has nothing to do here.
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    /** Put the guidance away — the dashboard card goes with it. */
    public function dismiss(SetupChecklist $checklist): void
    {
        $me = app(CurrentUser::class);
        $orgId = $me->organizationId();

        if ($orgId === null) {
            return;
        }

        $checklist->dismiss($orgId, $me->id());

        $this->redirectRoute('dashboard', navigate: true);
    }

    /** @return array<string, mixed> */
    public function with(SetupChecklist $checklist): array
    {
        $me = app(CurrentUser::class);
        $orgId = $me->organizationId();

        return [
            'me' => $me,
            'progress' => $orgId !== null ? $checklist->for($orgId) : new SetupProgress([]),
        ];
    }
}; ?>

<div>
    <x-page-header eyebrow="Getting started" title="Set up {{ $me->organization()?->name ?? 'your organization' }}"
                   subtitle="{{ $progress->total() }} things worth doing, roughly in this order. Each one ticks itself off as soon as it is genuinely done — there is nothing to mark complete by hand.">
        <x-slot:actions>
            <button type="button" wire:click="dismiss" class="btn btn-ghost">Don't show this again</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card p-5 mb-6">
        <div class="flex items-center gap-3">
            <div class="cbx-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                 aria-valuenow="{{ $progress->percent() }}" aria-label="Setup progress">
                <span style="width:{{ $progress->percent() }}%"></span>
            </div>
            <span class="text-sm mono shrink-0" style="color:var(--muted-foreground)">{{ $progress->completed() }} of {{ $progress->total() }}</span>
        </div>

        @if ($progress->isComplete())
            <p class="mt-3 text-sm" style="color:var(--muted-foreground)">
                That is everything on the list. From here the console is mostly somewhere you
                come back to — the <a href="{{ route('audit') }}" wire:navigate class="underline" style="color:var(--accent-strong)">activity log</a>
                and <a href="{{ route('governance') }}" wire:navigate class="underline" style="color:var(--accent-strong)">access reviews</a>
                are the two worth a standing habit.
            </p>
        @elseif ($progress->next() !== null)
            <p class="mt-3 text-sm" style="color:var(--muted-foreground)">
                Next up: <span style="color:var(--foreground);font-weight:500">{{ $progress->next()->title() }}</span>.
            </p>
        @endif
    </div>

    <ol class="space-y-3">
        @foreach ($progress->steps as $i => $step)
            <li class="card p-5 flex items-start gap-4 {{ $step->done ? 'is-done' : '' }}"
                @if ($step->done) style="background:var(--secondary)" @endif>
                <span class="grid place-items-center rounded-full shrink-0 mt-0.5"
                      style="width:1.75rem;height:1.75rem;{{ $step->done
                          ? 'background:var(--success-soft);color:var(--success-strong)'
                          : 'border:1px solid var(--border);color:var(--muted-foreground);font-family:var(--font-mono);font-size:12px;font-weight:600' }}">
                    @if ($step->done)<x-icon name="check" class="w-4 h-4" />@else{{ $i + 1 }}@endif
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1">
                        <h2 class="font-semibold" style="{{ $step->done ? 'color:var(--muted-foreground)' : '' }}">{{ $step->title() }}</h2>
                        <x-help :topic="$step->helpTopic()" />
                        @if ($step->done)
                            <span class="badge badge-success ml-1">Done</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm" style="color:var(--muted-foreground)">{{ $step->description() }}</p>
                </div>

                <a href="{{ route($step->route()) }}" wire:navigate
                   class="btn {{ $step->done ? 'btn-ghost' : 'btn-primary' }} btn-sm shrink-0 self-center">
                    {{ $step->done ? 'Review' : $step->actionLabel() }}
                </a>
            </li>
        @endforeach
    </ol>

    <p class="mt-6 text-sm" style="color:var(--muted-foreground)">
        Stuck on one of these? Every page in the console has a
        <span class="inline-flex align-middle"><x-icon name="help" class="w-4 h-4" /></span>
        beside its title with the short version, and a link to the full guide.
    </p>
</div>
