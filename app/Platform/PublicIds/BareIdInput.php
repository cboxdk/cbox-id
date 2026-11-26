<?php

declare(strict_types=1);

namespace App\Platform\PublicIds;

use App\Platform\Api\LegacyApiUsage;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * WHO STILL SENDS A BARE ULID where a public id belongs, counted per caller and route.
 *
 * Reading the bare form is a tolerance for this round (plan §3.2): it goes in the next minor,
 * once this counter has stood still for seven days, the same rule as {@see LegacyApiUsage}.
 * So it has to say who to talk to first.
 *
 * Counted on the surfaces that speak public ids — `/v1` and the console — and not on
 * `/api/v1`, whose own form is the bare id until it is retired (that surface has its own
 * counter). Nothing is counted outside a routed request: a job or a command reading a stored
 * id is not a caller.
 *
 * Labels: `surface` (`v1` or `console`), `kind` (a registry key), `caller_kind` and `caller`
 * (as {@see LegacyApiUsage} has them; a console caller is `session`), and `route` (the route's
 * pattern, never the path).
 */
final class BareIdInput
{
    public const string METRIC = 'cbox_id_bare_id_input_total';

    /**
     * Public ids read under a prefix their kind no longer wears ({@see PublicId::RETIRED_PREFIXES}),
     * on every surface, `/api/v1` too (surface `api`): a caller may poll there with an id `/v1`
     * gave it. The retired prefix goes on 2027-01-15; this says who to tell first. Labels as
     * {@see METRIC}'s, plus `prefix`.
     */
    public const string RETIRED_PREFIX_METRIC = 'cbox_id_retired_prefix_input_total';

    private const string CALLER = 'cbox_id.bare_id_caller';

    public static function record(string $kind): void
    {
        $at = self::at();

        if ($at === null || $at['surface'] === 'api') {
            return;
        }

        Telemetry::counter(self::METRIC, 'Bare ULIDs read where a public id belongs, by surface, kind, caller and route.')
            ->inc(1, ['surface' => $at['surface'], 'kind' => $kind, 'caller_kind' => $at['caller_kind'], 'caller' => $at['caller'], 'route' => $at['route']]);
    }

    public static function recordRetiredPrefix(string $kind, string $prefix): void
    {
        $at = self::at();

        if ($at === null) {
            return;
        }

        Telemetry::counter(self::RETIRED_PREFIX_METRIC, 'Public ids read under a retired prefix, by surface, kind, prefix, caller and route.')
            ->inc(1, ['surface' => $at['surface'], 'kind' => $kind, 'prefix' => $prefix, 'caller_kind' => $at['caller_kind'], 'caller' => $at['caller'], 'route' => $at['route']]);
    }

    /**
     * Where the current routed request read the id; null outside one.
     *
     * @return array{surface: string, caller_kind: string, caller: string, route: string}|null
     */
    private static function at(): ?array
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        $uri = ltrim($route->uri(), '/');
        $surface = match (true) {
            $uri === 'api' || str_starts_with($uri, 'api/') => 'api',
            $uri === 'v1' || str_starts_with($uri, 'v1/') => 'v1',
            default => 'console',
        };
        [$callerKind, $caller] = self::caller($request, $surface);

        return ['surface' => $surface, 'caller_kind' => $callerKind, 'caller' => $caller, 'route' => $request->method().' /'.$uri];
    }

    /** @return array{0: string, 1: string} */
    private static function caller(Request $request, string $surface): array
    {
        if ($surface === 'console') {
            return ['session', 'session'];
        }

        $known = $request->attributes->get(self::CALLER);

        if (is_array($known) && count($known) === 2 && is_string($known[0]) && is_string($known[1])) {
            return [$known[0], $known[1]];
        }

        $caller = LegacyApiUsage::caller($request);
        $request->attributes->set(self::CALLER, $caller);

        return $caller;
    }
}
