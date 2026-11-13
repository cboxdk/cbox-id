<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Shared\HelpProps;
use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppLauncher;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Inertia\Response;

/**
 * YOUR APPS: the first of a person's own pages, and where the account's home leads.
 *
 * Every app the person may open here, from the same catalog the header's Switch app reads
 * ({@see AppLauncher}), so the two never disagree. What this page adds is what a launcher
 * with a dozen tiles needs to stay findable: the apps the person used most recently first,
 * the rest grouped by what the catalog says each is for (its `purpose`), and a search box.
 *
 * "Recently used" is the last time the app used the person's sign-in (a refresh grant
 * belonging to one of the catalog entry's OAuth clients), the same date the Sessions page
 * shows beside an app with access. Nothing is recorded to build it.
 *
 * The apps the person could ask for ("not yet" in the catalog) are listed apart, each with
 * where to ask. An app the person cannot see is never named.
 */
final readonly class AccountAppsController extends PageController
{
    public function index(AppLauncher $launcher, RefreshTokens $tokens, CurrentUser $me): Response
    {
        $catalog = $launcher->catalog();
        $used = $this->lastUsed($catalog['apps'], $tokens, $me->id());

        $present = static fn (array $app, bool $available): array => [
            'key' => self::string($app['key'] ?? null) ?? '',
            'name' => self::string($app['name'] ?? null) ?? '',
            'description' => self::string($app['description'] ?? null),
            'purpose' => self::string($app['purpose'] ?? null),
            'hue' => self::string($app['hue'] ?? null),
            'iconUrl' => self::string($app['icon_url'] ?? null),
            'iconDarkUrl' => self::string($app['icon_dark_url'] ?? null),
            'url' => self::string($available ? ($app['launch_url'] ?? null) : ($app['admission_url'] ?? null)),
            'lastUsedIso' => $available ? ($used[self::string($app['key'] ?? null) ?? ''] ?? null) : null,
        ];

        return $this->page('account/apps', __('console.account_apps_title'), [
            'apps' => array_map(static fn (array $app): array => $present($app, true), $catalog['apps']),
            'help' => HelpProps::for(HelpTopic::YourApps),
            'requestable' => array_map(static fn (array $app): array => $present($app, false), $catalog['unavailable']),
        ]);
    }

    /**
     * When each app last used the person's sign-in, by catalog key, as ISO 8601.
     *
     * @param  list<array<string, mixed>>  $apps
     * @return array<string, string>
     */
    private function lastUsed(array $apps, RefreshTokens $tokens, string $subjectId): array
    {
        if ($apps === []) {
            return [];
        }

        $byClient = [];

        foreach ($tokens->connectedApplications($subjectId) as $application) {
            if ($application->lastUsedAt !== null) {
                $byClient[$application->clientId] = $application->lastUsedAt;
            }
        }

        if ($byClient === []) {
            return [];
        }

        $keys = array_values(array_filter(array_map(static fn (array $app): ?string => self::string($app['key'] ?? null), $apps)));
        $used = [];

        foreach (CatalogEntry::query()->whereIn('key', $keys)->get(['key', 'client_ids']) as $entry) {
            $latest = null;

            foreach ($entry->client_ids as $clientId) {
                $at = $byClient[$clientId] ?? null;

                if ($at !== null && ($latest === null || $at->greaterThan($latest))) {
                    $latest = $at;
                }
            }

            if ($latest !== null) {
                $used[$entry->key] = $latest->toIso8601String();
            }
        }

        return $used;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Whether the rail offers this page: the environment publishes at least one app. */
    public static function offered(): bool
    {
        return app(CurrentUser::class)->check() && app(AppCatalog::class)->hasPublishedApps();
    }
}
