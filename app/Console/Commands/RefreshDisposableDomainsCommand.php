<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Radar\RadarDisposableDomains;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Refresh Radar's disposable-domain list from the community-maintained source.
 *
 * The bundled list is a curated starter; the providers are many and new ones appear weekly.
 * This fetches `cbox-id.radar.disposable_domains_url` (by default the
 * disposable-email-domains project's blocklist, CC0) and writes it to
 * `cbox-id.radar.disposable_domains_path`, where {@see RadarDisposableDomains} merges it with
 * the bundled list. NOT scheduled by default — it is an outbound request, and whether this
 * deployment makes one is the operator's decision. Schedule it weekly if you want it.
 *
 * Refuses to replace a good list with a bad one: a response that is not a plain domain list,
 * or that shrank to almost nothing, leaves the current file in place.
 */
class RefreshDisposableDomainsCommand extends Command
{
    /** Fewer than this many valid domains is a broken response, not a list. */
    private const int MINIMUM = 100;

    protected $signature = 'radar:refresh-disposable-domains';

    protected $description = 'Fetch the latest disposable mail-domain list for Radar and the risk score';

    public function handle(HttpClient $http): int
    {
        $url = config('cbox-id.radar.disposable_domains_url');
        $path = config('cbox-id.radar.disposable_domains_path');

        if (! is_string($url) || $url === '' || ! is_string($path) || $path === '') {
            $this->error('Set cbox-id.radar.disposable_domains_url and cbox-id.radar.disposable_domains_path first.');

            return self::FAILURE;
        }

        try {
            $response = $http->timeout(20)->get($url);
        } catch (Throwable $e) {
            $this->error('Could not fetch the list: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("The list answered HTTP {$response->status()}; the current list is kept.");

            return self::FAILURE;
        }

        $domains = [];

        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            $line = strtolower(trim($line));

            if (preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $line) === 1) {
                $domains[$line] = true;
            }
        }

        if (count($domains) < self::MINIMUM) {
            $this->error('The response held '.count($domains).' domains, fewer than '.self::MINIMUM.'; the current list is kept.');

            return self::FAILURE;
        }

        $names = array_keys($domains);
        sort($names);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '# Fetched from '.$url.' at '.now()->toIso8601String()."\n".implode("\n", $names)."\n");

        $this->info('Wrote '.count($names).' disposable domains to '.$path.'.');

        return self::SUCCESS;
    }
}
