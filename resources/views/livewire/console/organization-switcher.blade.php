<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleOrganization;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Volt\Component;

/**
 * Console chrome › the acting organization.
 *
 * Every page in the environment console is scoped to one organization, so which one is
 * breadcrumb information — it sits beside the environment switcher rather than being
 * repeated as a field on each page, which is how the two consoles came to disagree about
 * which organization a form was writing to.
 *
 * A SEARCH, NOT A LIST. This was a `@foreach` over every organization in the environment,
 * each rendered as its own `<form>` with its own CSRF token. That is fine for the seven a
 * developer has locally and it is an outage for the customer with four thousand: the
 * document went from 59 KB to 3.5 MB, on every console page, before anybody clicked
 * anything. An unbounded set needs a chooser that is bounded whatever the set does, so
 * this offers a page of {@see ConsoleScope::SWITCHER_LIMIT} and lets the administrator
 * type to narrow it — which is also just faster than reading a list of four thousand.
 *
 * It stays a control on the organization plane too, where it holds exactly one entry and
 * cannot be changed: a member has one organization and choosing is the authorization that
 * plane exists to withhold. Rendering it there anyway means the chrome reads the same on
 * both planes, which is the whole point of {@see ConsoleScope}.
 */
new class extends Component
{
    /** What the administrator has typed. Not a URL parameter — chrome, not a page. */
    public string $search = '';

    /**
     * The gate the rest of the console keeps in boot() rather than mount(), for the same
     * reason: only boot() runs on a Livewire action, so a check in mount() would guard the
     * first render and nothing afterwards. Reaching the console at all is the
     * authorization here — this component only reads names and writes a selection that
     * {@see ConsoleScope::chooseOrganization()} refuses on its own terms.
     */
    public function boot(): void
    {
        if (! app(ConsoleScope::class)->signedIn()) {
            throw new AuthorizationException('Sign in to choose an organization.');
        }
    }

    /**
     * Act on behalf of this organization from now on.
     *
     * Refused rather than ignored when it is not this administrator's to choose — the
     * scope throws on the organization plane and on an organization outside this
     * environment, and a silent no-op would leave somebody looking at one organization's
     * name while acting on another's data.
     */
    public function choose(string $organizationId): void
    {
        try {
            app(ConsoleScope::class)->chooseOrganization($organizationId);
        } catch (AuthorizationException $e) {
            $this->addError('search', $e->getMessage());

            return;
        }

        // A full reload rather than a redirect. Every part of the page is scoped to the
        // acting organization — the rail's counts, the sub-nav, the page itself — so
        // re-rendering this one component would leave the rest of the screen describing
        // the organization they just left. Reloading in place also needs no redirect
        // TARGET, so there is no referer to trust and no way to point it off-site.
        $this->js('window.location.reload()');
    }

    /**
     * Go back to acting on the whole environment.
     *
     * The missing half of choosing, and its absence made the control a one-way door: an
     * administrator who picked an organization had every list, count and form in the
     * console filtered to it for the rest of the session, with signing out as the only way
     * back to the environment-wide view they arrived on.
     */
    public function clear(): void
    {
        try {
            app(ConsoleScope::class)->clearOrganization();
        } catch (AuthorizationException $e) {
            $this->addError('search', $e->getMessage());

            return;
        }

        $this->js('window.location.reload()');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);
        $actingId = $scope->organizationId();

        /** @var list<ConsoleOrganization> $results */
        $results = $scope->searchOrganizations($this->search);

        return [
            // Whether this is a control or a label. The organization plane has exactly one
            // organization and choosing is the authorization it exists to withhold.
            'canChoose' => $scope->plane() !== ConsolePlane::Organization,
            'actingId' => $actingId,
            'actingName' => $scope->organizationName(),
            'results' => $results,
            // Only asked once the list is full, because "8 of 8" is noise and a COUNT over
            // every organization in the environment is exactly the unbounded read this
            // component exists to stop paying.
            'total' => count($results) < ConsoleScope::SWITCHER_LIMIT ? count($results) : $scope->organizationCount(),
        ];
    }
}; ?>

<div class="relative min-w-0" x-data="{ org: false }" @keydown.escape.stop="org = false">
    <button type="button"
            class="cbx-switcher-item flex items-center gap-1.5 rounded-lg px-1.5 py-1 {{ $canChoose ? '' : 'pointer-events-none' }}"
            style="transition:background-color var(--dur-hover) var(--ease)"
            @if ($canChoose)
                @click="org = !org; if (org) $nextTick(() => $refs.orgSearch?.focus())"
                :aria-expanded="org" aria-haspopup="dialog"
            @endif>
        {{-- "Choose organization" read as an instruction — as though the console could
             not work until you picked one — when unselected is the ordinary state and
             means "everything in this environment". It says what you are looking at. --}}
        <span class="truncate {{ $actingId === null ? 'italic' : 'font-semibold' }}"
              style="{{ $actingId === null ? 'color:var(--muted-foreground)' : '' }}">
            {{ $actingId === null ? 'All organizations' : ($actingName ?? 'Unknown organization') }}
        </span>
        @if ($canChoose)
            <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />
        @endif
    </button>

    @if ($canChoose)
        <div x-show="org" x-transition.opacity.duration.150ms @click.outside="org = false" x-cloak
             role="dialog" aria-label="Choose the organization to act on"
             class="cbx-panel"
             style="position:absolute;top:calc(100% + 6px);left:0;width:288px;z-index:40;box-shadow:var(--shadow-popover);padding:6px">
            <p class="cbx-nav-group" style="padding:6px 10px 4px">Act on behalf of</p>

            {{-- The default, and a real row rather than a link tucked under the list: it
                 is where an administrator starts, and it was the one destination the
                 control could not reach. --}}
            <button type="button" wire:click="clear" role="option"
                    aria-selected="{{ $actingId === null ? 'true' : 'false' }}"
                    class="cbx-row w-full text-start"
                    style="padding:8px 10px;border-radius:6px;gap:10px;{{ $actingId === null ? 'background:var(--secondary)' : '' }}">
                <span class="min-w-0 flex-1 truncate">
                    All organizations
                    <span class="block text-[11px]" style="color:var(--muted-foreground)">The whole environment, unfiltered</span>
                </span>
                @if ($actingId === null)
                    <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />
                @endif
            </button>

            <div style="padding:0 6px 6px">
                <input x-ref="orgSearch" type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search organizations…" aria-label="Search organizations"
                       autocomplete="off" spellcheck="false"
                       class="w-full rounded-md px-2.5 py-1.5 text-[13px]"
                       style="background:var(--background);color:var(--foreground);border:1px solid var(--border);outline-color:var(--primary)">
            </div>

            <div role="listbox" aria-label="Organizations" class="max-h-64 overflow-y-auto space-y-0.5">
                @forelse ($results as $option)
                    <button type="button" wire:click="choose(@js($option->id))" wire:key="org-{{ $option->id }}"
                            role="option" aria-selected="{{ $option->id === $actingId ? 'true' : 'false' }}"
                            class="cbx-row w-full text-start"
                            style="padding:8px 10px;border-radius:6px;gap:10px;{{ $option->id === $actingId ? 'background:var(--secondary)' : '' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $option->name }}</span>
                        @if ($option->id === $actingId)
                            <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />
                        @endif
                    </button>
                @empty
                    <p class="text-[13px]" style="padding:10px;color:var(--muted-foreground)">
                        @if (trim($search) !== '')
                            No organization matches “{{ $search }}”.
                        @else
                            This environment has no organizations yet. Create one to start administering it.
                        @endif
                    </p>
                @endforelse
            </div>

            {{-- WCAG 4.1.3. The list morphs in on a debounced keystroke, so the count is
                 the only thing that can tell a screen-reader user that their search
                 narrowed to nothing — or that there are 499 more behind the eight shown. --}}
            <p role="status" aria-live="polite" class="text-[11px]" style="padding:6px 10px 4px;color:var(--muted-foreground)">
                @if ($results === [])
                    No organizations to show.
                @elseif ($total > count($results))
                    Showing {{ count($results) }} of {{ number_format($total) }} — type to narrow.
                @else
                    {{ count($results) }} {{ count($results) === 1 ? 'organization' : 'organizations' }}.
                @endif
            </p>

            @error('search')
                <p class="text-[11px]" style="padding:6px 10px 4px;color:var(--destructive)">{{ $message }}</p>
            @enderror
        </div>
    @endif
</div>
