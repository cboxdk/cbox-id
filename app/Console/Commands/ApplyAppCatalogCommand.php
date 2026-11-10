<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppCatalog\CatalogEntryData;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Apply a catalog file kept in the repository (`resources/app-catalog/cbox.json`) to one
 * environment's app catalog: an operator action, repeatable and reviewable in a diff.
 *
 * A PATCH, never a replace. Each app in the file sets only the fields it names, through the
 * same validation and audit trail as the console; everything else, icons included, stays.
 * An app the catalog does not have yet is created only when the file gives its `name` and
 * `launch_url`; otherwise it is reported, so the command never invents where an app lives.
 * Running it twice changes nothing the second time.
 */
final class ApplyAppCatalogCommand extends Command
{
    private const array FIELDS = ['name', 'description', 'purpose', 'hue', 'launch_url', 'admission_url', 'promote_when_unavailable', 'enabled', 'position'];

    protected $signature = 'app-catalog:apply
        {file : A JSON file: {"environment": "…", "apps": [{"key": "…", "purpose": "…", "hue": "…"}, …]}}
        {--environment= : The environment to apply it to. Defaults to the file\'s, then the configured default.}';

    protected $description = 'Apply a catalog file to an environment\'s app catalog';

    public function handle(EnvironmentContext $context, AppCatalog $catalog): int
    {
        $path = $this->argument('file');
        $path = is_string($path) ? $path : '';
        $raw = is_file($path) ? file_get_contents($path) : false;
        try {
            $file = $raw === false ? null : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $file = null;
        }
        if (! is_array($file) || ! is_array($file['apps'] ?? null)) {
            $this->error("{$path} is not a catalog file: it needs an \"apps\" list.");

            return self::FAILURE;
        }
        $option = $this->option('environment');
        $configured = config('cbox-id.environments.default');
        $environment = match (true) {
            is_string($option) && $option !== '' => $option,
            is_string($file['environment'] ?? null) && $file['environment'] !== '' => $file['environment'],
            is_string($configured) => $configured,
            default => '',
        };
        if ($environment === '') {
            $this->error('No environment in the file or configured. Pass --environment.');

            return self::FAILURE;
        }

        /** @var list<array<string, mixed>> $apps */
        $apps = array_values(array_filter($file['apps'], is_array(...)));

        return $context->runAs(GenericEnvironment::of($environment), fn (): int => $this->apply($catalog, $apps, $environment));
    }

    /** @param  list<array<string, mixed>>  $apps */
    private function apply(AppCatalog $catalog, array $apps, string $environment): int
    {
        $failed = false;
        foreach ($apps as $app) {
            $key = is_string($app['key'] ?? null) ? $app['key'] : '';
            $given = array_intersect_key($app, array_flip(self::FIELDS));
            $entry = CatalogEntry::query()->where('key', $key)->first();
            if ($entry === null && ! (isset($given['name'], $given['launch_url']))) {
                $this->warn("  {$key}: not in the {$environment} catalog. Add it in the console, or give name and launch_url here.");

                continue;
            }
            $input = $entry === null
                ? ['enabled' => false, 'position' => 0, 'audience' => 'everyone', 'version' => 0, ...$given]
                : ['name' => $entry->name, 'description' => $entry->description, 'launch_url' => $entry->launch_url, 'enabled' => $entry->enabled,
                    'position' => $entry->position, 'audience' => $entry->audience, 'organization_ids' => $entry->organization_ids,
                    'user_ids' => $entry->user_ids, 'client_ids' => $entry->client_ids, 'required_feature' => $entry->required_feature,
                    ...$given, 'version' => $entry->version];
            try {
                $saved = $catalog->save($key, CatalogEntryData::fromArray($input));
            } catch (ValidationException $e) {
                $failed = true;
                $this->error("  {$key}: ".implode(' ', array_filter(Arr::flatten($e->errors()), is_string(...))));

                continue;
            }
            $this->line(match (true) {
                $entry === null => "  {$key}: created (draft).",
                $saved->version !== $entry->version => "  {$key}: updated.",
                default => "  {$key}: unchanged.",
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
