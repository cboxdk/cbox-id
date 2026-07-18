<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Organizations › New. A dedicated, deep-linkable create
 * page. The org id is a ULID, so the slug is derived from the name — the admin never
 * invents one; an override plus metadata live under "Advanced". On success we route
 * to the new org's detail page.
 */
new #[Layout('components.layouts.environment', ['title' => 'New organization'])] class extends Component
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

    public function create(Organizations $organizations): mixed
    {
        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'alpha_dash'],
            'metadata.*.key' => ['nullable', 'string', 'max:120'],
            'metadata.*.value' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = $this->uniqueSlug($organizations, $this->slug !== '' ? Str::slug($this->slug) : Str::slug($this->name));

        $settings = [];
        foreach ($this->metadata as $row) {
            $key = trim($row['key']);
            if ($key !== '') {
                $settings['metadata'][$key] = trim($row['value']);
            }
        }

        $org = $organizations->create(new NewOrganization(name: trim($this->name), slug: $slug, settings: $settings));

        session()->flash('status', 'Organization created.');

        return $this->redirectRoute('environment.organizations.show', ['organization' => $org->id], navigate: true);
    }

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
}; ?>

<div>
    <a href="{{ route('environment.organizations') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Organizations</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New organization</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Its ID and URL handle are generated for you.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label for="org-name" class="label">Name</label>
            <input wire:model="name" id="org-name" type="text" class="input" placeholder="Acme Inc" autofocus>
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
                    <div class="space-y-2">
                        @foreach ($metadata as $i => $row)
                            <div class="flex items-center gap-2" wire:key="meta-{{ $i }}">
                                <input wire:model="metadata.{{ $i }}.key" type="text" class="input mono" placeholder="tier">
                                <input wire:model="metadata.{{ $i }}.value" type="text" class="input" placeholder="enterprise">
                                <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="removeMetaRow({{ $i }})" aria-label="Remove"><x-icon name="close" class="w-4 h-4" /></button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm mt-2" wire:click="addMetaRow">+ Add field</button>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create organization</button>
            <a href="{{ route('environment.organizations') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
