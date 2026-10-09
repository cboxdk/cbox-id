<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use Cbox\Risk\Contracts\DisposableDomains;

/**
 * Throwaway mail providers: the list bundled with the app, merged with the refreshed copy
 * `radar:refresh-disposable-domains` keeps and any list `risk.disposable_domains_path`
 * names.
 *
 * Bound as the risk package's {@see DisposableDomains} too, so the scored
 * `email.disposable` signal and Radar's `disposable_email` rule never disagree about a
 * domain. A subdomain of a listed domain is listed (`x.mailinator.com`).
 *
 * The bundled list is a curated starter, not complete coverage — the providers are many and
 * new ones appear weekly. Schedule the refresh, or point the path at your own list.
 */
final class RadarDisposableDomains implements DisposableDomains
{
    /** @var array<string, true>|null */
    private ?array $set = null;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(private readonly array $paths) {}

    public static function bundledPath(): string
    {
        return resource_path('radar/disposable-domains.txt');
    }

    public function contains(string $domain): bool
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        if ($domain === '') {
            return false;
        }

        $set = $this->set ??= $this->load();
        $labels = explode('.', $domain);

        // The domain itself, then each parent with at least two labels.
        while (count($labels) >= 2) {
            if (isset($set[implode('.', $labels)])) {
                return true;
            }

            array_shift($labels);
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function load(): array
    {
        $set = [];

        foreach ($this->paths as $path) {
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            foreach ($lines as $line) {
                $line = strtolower(trim($line));

                if ($line !== '' && ! str_starts_with($line, '#') && preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $line) === 1) {
                    $set[$line] = true;
                }
            }
        }

        return $set;
    }
}
