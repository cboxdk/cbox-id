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

    private const string CALLER = 'cbox_id.bare_id_caller';

    public static function record(string $kind): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $request = app('request');
        $route = $request->route();

        if (! $route instanceof Route) {
            return;
        }

        $uri = ltrim($route->uri(), '/');

        if ($uri === 'api' || str_starts_with($uri, 'api/')) {
            return;
        }

        $surface = $uri === 'v1' || str_starts_with($uri, 'v1/') ? 'v1' : 'console';
        [$callerKind, $caller] = self::caller($request, $surface);

        Telemetry::counter(self::METRIC, 'Bare ULIDs read where a public id belongs, by surface, kind, caller and route.')
            ->inc(1, [
                'surface' => $surface,
                'kind' => $kind,
                'caller_kind' => $callerKind,
                'caller' => $caller,
                'route' => $request->method().' /'.$uri,
            ]);
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
