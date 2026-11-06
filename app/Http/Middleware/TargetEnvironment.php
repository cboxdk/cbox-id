<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/** Resolve operator reads and writes from this URL, after authenticating in the root. */
final readonly class TargetEnvironment
{
    public function __construct(private EnvironmentContext $context, private UrlGenerator $urls) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('targetEnvironment');
        // The stable root alias works for a self-hosted install too. It never reads
        // a session selection and cannot silently target a different tenant.
        if ($slug !== 'root') {
            abort_unless(is_string($slug) && $slug !== '', 404);
            $environment = Environment::query()->where('slug', $slug)->firstOrFail();
            $this->context->set($environment);
        }
        $request->attributes->set('operator_target', $slug);
        $request->route()?->forgetParameter('targetEnvironment');
        $this->urls->defaults(['targetEnvironment' => $slug]);

        try {
            return $next($request);
        } finally {
            $this->urls->defaults(['targetEnvironment' => 'root']);
        }
    }
}
