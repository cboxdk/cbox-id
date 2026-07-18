<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Organizations. Lists and creates the organizations
 * (the environment's own tenants) — the env-scoped, account-member-driven equivalent
 * of the account API's organizations endpoint.
 *
 * The org id is a ULID, so the slug is a convenience handle, never something the admin
 * must invent: we derive it from the name and only expose an override (plus metadata)
 * under "Advanced". Reads query the hard env-scoped model; create delegates to the
 * {@see Organizations} service.
 */
new #[Layout('components.layouts.environment', ['title' => 'Organizations'])] class extends Component
{
    public string $name = '';

    public string $slug = '';

    public bool $advanced = false;

    /** @var list<array{key: string, value: string}> */
    public array $metadata = [];

    public function addMetaRow(): void
    {
        $this->metadata[] = ['key' => '', 'value' => ''];
    }

    public function removeMetaRow(int $i): void
    {
        unset($this->metadata[$i]);
        $this->metadata = array_values($this->metadata);
    }

    public function create(Organizations $organizations): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'alpha_dash'],
            'metadata.*.key' => ['nullable', 'string', 'max:120'],
            'metadata.*.value' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = $this->uniqueSlug(
            $organizations,
            $this->slug !== '' ? Str::slug($this->slug) : Str::slug($this->name),
        );

        $settings = [];
        foreach ($this->metadata as $row) {
            $key = trim($row['key']);
            if ($key !== '') {
                $settings['metadata'][$key] = trim($row['value']);
            }
        }

        $organizations->create(new NewOrganization(
            name: trim($this->name),
            slug: $slug,
            settings: $settings,
        ));

        $this->reset('name', 'slug', 'advanced', 'metadata');
        session()->flash('status', 'Organization created.');
    }

    /** Derive a collision-free slug so the admin never has to invent one. */
    private function uniqueSlug(Organizations $organizations, string $base): string
    {
        $base = $base !== '' ? $base : 'org';
        $slug = $base;
        $n = 2;
        while ($organizations->bySlug($slug) !== null) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return ['organizations' => Organization::query()->orderBy('name')->limit(100)->get()];
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Organizations</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The tenants inside this environment. Each has its own users, roles, and SSO.</p>

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($organizations as $org)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $org->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $org->slug }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $org->status->value }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No organizations yet.</p>
        @endforelse
    </div>

    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Create an organization</p>
        <form wire:submit="create" class="mt-4 space-y-4">
            <div>
                <label for="org-name" class="label">Name</label>
                <input wire:model="name" id="org-name" type="text" class="input" placeholder="Acme Inc">
                <p class="mt-1 text-xs" style="color:var(--faint)">The organization's display name. Its ID and URL handle are generated for you.</p>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ open: @entangle('advanced') }">
                <button type="button" class="text-xs font-medium inline-flex items-center gap-1" style="color:var(--accent)" x-on:click="open = !open">
                    <x-icon name="settings" class="w-3.5 h-3.5" /> Advanced <span x-text="open ? '−' : '+'"></span>
                </button>

                <div x-show="open" x-cloak class="mt-3 space-y-4 rounded-lg border p-4" style="border-color:var(--border)">
                    <div>
                        <label for="org-slug" class="label">URL handle <span style="color:var(--faint)">(optional)</span></label>
                        <input wire:model="slug" id="org-slug" type="text" class="input mono" placeholder="{{ \Illuminate\Support\Str::slug($name) ?: 'acme' }}">
                        <p class="mt-1 text-xs" style="color:var(--faint)">Used in URLs and SSO endpoints. Leave blank to derive it from the name.</p>
                        @error('slug') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">Metadata <span style="color:var(--faint)">(optional)</span></label>
                        <p class="mb-2 text-xs" style="color:var(--faint)">Arbitrary key/value pairs stored on the organization.</p>
                        <div class="space-y-2">
                            @foreach ($metadata as $i => $row)
                                <div class="flex items-center gap-2" wire:key="meta-{{ $i }}">
                                    <input wire:model="metadata.{{ $i }}.key" type="text" class="input mono" placeholder="tier">
                                    <input wire:model="metadata.{{ $i }}.value" type="text" class="input" placeholder="enterprise">
                                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="removeMetaRow({{ $i }})" aria-label="Remove">
                                        <x-icon name="close" class="w-4 h-4" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm mt-2" wire:click="addMetaRow">+ Add field</button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create organization</button>
        </form>
    </div>
</div>
