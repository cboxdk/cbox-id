<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\IssueFrontendKeyRequest;
use App\Http\Requests\Console\SaveFrontendKeyOriginsRequest;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\Exceptions\UnusableOrigin;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * CONSOLE › FRONTEND KEYS — the publishable keys a browser-side app presents to the
 * Frontend API. They go in a JavaScript bundle; the ALLOW-LIST of origins is the control,
 * not the key's secrecy.
 *
 * SHOWN IN FULL, ALWAYS. Every other credential screen in this console reveals a secret
 * once and then shows a prefix forever, because it is a secret. Doing that here would
 * teach the opposite of the truth — somebody who cannot re-read their publishable key
 * concludes it must be sensitive, starts handling it like one, and usually ends up
 * proxying it through their own server and losing the entire point.
 *
 * ENVIRONMENT-OWNED, so this page is the environment plane's alone: publishable keys have
 * no organization column, and on the organization plane every tenant's administrator would
 * be administering every other tenant's — see `ConsoleScope::assertMayAdministerEnvironment()`.
 */
final readonly class FrontendKeyController extends ConsoleController
{
    public function index(): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->page('console/frontend-keys', 'Frontend keys', [
            'keys' => PublishableKey::query()
                ->with('origins')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (PublishableKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    // IN FULL. See the class docblock — hiding it would teach somebody to
                    // treat a public value as a secret.
                    'key' => $key->key,
                    'mode' => $key->mode->value,
                    'origins' => $key->origins->pluck('origin')->all(),
                    'active' => $key->isActive(),
                    'urls' => [
                        'origins' => $this->url('frontend-keys.origins', $key->id),
                        'revoke' => $this->url('frontend-keys.destroy', $key->id),
                    ],
                ])
                ->all(),
            'modes' => array_map(static fn (KeyMode $mode): array => [
                'value' => $mode->value,
                'label' => ucfirst($mode->value),
            ], KeyMode::cases()),
            'storeHref' => $this->url('frontend-keys.store'),
        ]);
    }

    public function store(IssueFrontendKeyRequest $request, PublishableKeys $keys): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        try {
            $keys->issue($request->name(), $request->mode(), $request->origins());
        } catch (UnusableOrigin $e) {
            /*
             * On the FIELD rather than as a toast: the person has to see which line they
             * must fix, and a toast disappears while they are still reading it. The
             * exception's message names the offending value.
             */
            return back()->withInput()->withErrors(['origins' => $e->getMessage()]);
        }

        return back()->with('status', 'Key created. Paste it into your frontend — it is meant to be public.');
    }

    /**
     * Replace a key's allow-list.
     *
     * THE ALLOW-LIST IS THE CONTROL, and a control that can only be set once is not one:
     * adding a staging domain otherwise meant minting a second key and shipping a new
     * bundle, and revoking a key to change a list is a change with an outage in it.
     */
    public function origins(SaveFrontendKeyOriginsRequest $request, string $key, PublishableKeys $keys): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $model = PublishableKey::query()->find($key);

        // A revoked key's allow-list decides nothing, so editing one is a change that
        // looks like it took effect and did not.
        if (! $model instanceof PublishableKey || ! $model->isActive()) {
            return back();
        }

        try {
            $keys->setOrigins($model->id, $request->origins());
        } catch (UnusableOrigin $e) {
            /*
             * The WHOLE list is refused rather than the bad line being dropped: a silently
             * shortened allow-list is a key that stops working somewhere nobody looked.
             */
            return back()->withInput()->withErrors(['origins' => $e->getMessage()]);
        }

        return back()->with('status', 'Origins updated. The change is live on the next request.');
    }

    public function destroy(string $key, PublishableKeys $keys): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $keys->revoke($key);

        return back()->with('status', 'Key revoked. Pages still holding it stop working immediately.');
    }
}
