<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\AppCatalog\DeleteCatalogApp;
use App\Actions\AppCatalog\SaveCatalogApp;
use App\Http\Props\Shared\HelpProps;
use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppCatalog\CatalogHue;
use App\Platform\Help\HelpTopic;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

final readonly class AppCatalogController extends ConsoleController
{
    public function index(AppCatalog $catalog): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->page('console/app-catalog/index', __('console.catalog_heading'), [
            'help' => HelpProps::for(HelpTopic::AppCatalog),
            'entries' => CatalogEntry::query()->orderBy('position')->orderBy('key')->get()->map(fn (CatalogEntry $entry): array => [
                ...$catalog->present($entry, true), 'editHref' => route('environment.app-catalog.edit', $entry->key),
            ])->all(),
            'createHref' => route('environment.app-catalog.create'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->editor(null);
    }

    public function edit(string $key, AppCatalog $catalog): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->editor($catalog->find($key));
    }

    public function save(Request $request, string $key): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();
        /** @var array<string, mixed> $input */
        $input = $request->all();
        $fields = ['key', 'name', 'description', 'purpose', 'hue', 'launch_url', 'admission_url', 'position', 'audience', 'required_feature', 'icon', 'icon_dark'];
        $result = $this->act(SaveCatalogApp::class, [...$input, 'key' => $key], array_combine($fields, $fields));

        return $result instanceof RedirectResponse ? $result : to_route('environment.app-catalog')->with('status', __('console.catalog_saved'));
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();
        $result = $this->act(DeleteCatalogApp::class, ['key' => $key, 'version' => $request->input('version')]);

        return $result instanceof RedirectResponse ? $result : to_route('environment.app-catalog')->with('status', __('console.catalog_removed'));
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->scope->assertMayAdministerEnvironment();
        $request->validate(['kind' => 'required|in:organizations,users,clients', 'q' => 'nullable|string|max:120']);
        $kind = $request->string('kind')->toString();
        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('q')->toString()).'%';
        $results = match ($kind) {
            'users' => User::query()->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('id', 'like', $term))->orderBy('name')->limit(20)->get()
                ->map(static fn (User $row): array => ['value' => $row->id, 'label' => ($row->name ?? $row->email).' ('.$row->email.')'])->all(),
            'clients' => Client::query()->whereNull('organization_id')->where('name', 'like', $term)->orderBy('name')->limit(20)->get()
                ->map(static fn (Client $row): array => ['value' => $row->client_id, 'label' => $row->name])->all(),
            default => Organization::query()->where('name', 'like', $term)->orderBy('name')->limit(20)->get()
                ->map(static fn (Organization $row): array => ['value' => $row->id, 'label' => $row->name])->all(),
        };

        return response()->json(['results' => $results])->header('Cache-Control', 'private, no-store');
    }

    private function editor(?CatalogEntry $entry): Response
    {
        return $this->page('console/app-catalog/edit', $entry === null ? __('console.catalog_new') : __('console.catalog_edit_named', ['name' => $entry->name]), [
            'entry' => $entry === null ? null : app(AppCatalog::class)->present($entry, true),
            'indexHref' => route('environment.app-catalog'),
            'saveBaseHref' => route('environment.app-catalog'),
            'selectedOptions' => [
                'organizations' => Organization::query()->whereIn('id', $entry->organization_ids ?? [])->get()->map(fn (Organization $row): array => ['value' => $row->id, 'label' => $row->name])->all(),
                'users' => User::query()->whereIn('id', $entry->user_ids ?? [])->get()->map(fn (User $row): array => ['value' => $row->id, 'label' => ($row->name ?? $row->email).' ('.$row->email.')'])->all(),
                'clients' => Client::query()->whereIn('client_id', $entry->client_ids ?? [])->get()->map(fn (Client $row): array => ['value' => $row->client_id, 'label' => $row->name])->all(),
            ],
            'lookupHref' => route('environment.app-catalog.lookup'),
            'hues' => CatalogHue::values(),
            'features' => array_map(fn ($flag): array => ['value' => $flag->key, 'label' => $flag->key], app(FeatureFlags::class)->all()),
        ]);
    }
}
