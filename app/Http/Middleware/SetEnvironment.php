<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the current environment for every web request. The console operates within
 * one environment at a time (staging vs production, or a per-product plane): the
 * operator's selection is held in the session, defaulting to the first
 * environment. When none exist yet (fresh install) a bootstrap default keeps the
 * console — including the create-environment screen — renderable.
 */
final class SetEnvironment
{
    public const SESSION_KEY = 'cbox.environment';

    public function __construct(private readonly EnvironmentContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->session()->get(self::SESSION_KEY);

        $environment = is_string($slug) && $slug !== ''
            ? Environment::query()->where('slug', $slug)->first()
            : null;

        $environment ??= Environment::query()->orderBy('created_at')->first();

        if ($environment !== null) {
            $this->context->set($environment);
            $request->session()->put(self::SESSION_KEY, $environment->slug);

            return $next($request);
        }

        // No environment provisioned yet — a bootstrap default.
        $default = config('cbox-id.environments.default');
        $this->context->set(GenericEnvironment::of(is_string($default) && $default !== '' ? $default : 'default'));

        return $next($request);
    }
}
