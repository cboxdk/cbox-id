<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\Appearance\BrandContext;
use Inertia\Response;
use Inertia\ResponseFactory;
use LogicException;

/**
 * A PAGE HAS A NAME, and the server is the one that says it.
 *
 * Not the page's own `<Head>`: that runs after the bundle parses, so the first paint of
 * every screen would say nothing but the product's name, and a person restoring twenty
 * tabs would get twenty identical ones. The controller states it once — the root view
 * renders it into `<title>` on the first byte, the layout re-renders the same string
 * through `<Head>` across client-side navigations, and `<PageHeader>` takes its `<h1>`
 * from the same prop.
 *
 * Three consumers, one statement. ConsoleAreasTest holds that they agree; this is what
 * makes them agree by construction rather than by three people remembering.
 */
abstract readonly class PageController
{
    public function __construct(protected ResponseFactory $inertia) {}

    /**
     * @param  array<string, mixed>  $props
     */
    protected function page(string $component, string $title, array $props = []): Response
    {
        $this->refuseShadowedProps($component, $props);

        if (self::isDoor($component)) {
            app(BrandContext::class)->atTheDoor();
        }

        return $this->inertia
            ->render($component, [...$props, 'title' => $title])
            ->withViewData(['title' => $title]);
    }

    /**
     * The pages drawn in the sign-in layout — every `auth/*` and `oauth/*` page but the
     * console's own step-up, which sits inside the console. Named by component because
     * the component IS what decides the layout; a new door under either folder is branded
     * without anybody remembering to ask.
     */
    private static function isDoor(string $component): bool
    {
        return $component !== 'auth/sudo'
            && (str_starts_with($component, 'auth/') || str_starts_with($component, 'oauth/'));
    }

    /**
     * A PAGE MAY NOT TAKE A NAME THE CHROME OWNS.
     *
     * Inertia merges shared props and page props into one bag and the page wins, so a
     * controller that calls one of its own props `environment` does not add a prop — it
     * replaces the one the sandbox banner and the realm badge read, and the SHELL throws
     * on a page that is itself entirely correct. The stack trace then points at the
     * chrome, which is the one place the bug is not.
     *
     * Thrown rather than logged, and in every environment rather than only locally: this
     * is a programming error with a one-word fix, and the alternative is a page that
     * renders an error boundary in production for reasons nobody can see. The browser
     * sweep found the first one; this is what stops the second.
     *
     * @param  array<string, mixed>  $props
     */
    private function refuseShadowedProps(string $component, array $props): void
    {
        $shadowed = array_intersect(array_keys($props), HandleInertiaRequests::SHARED_KEYS);

        if ($shadowed === []) {
            return;
        }

        throw new LogicException(sprintf(
            'The page [%s] passes prop(s) [%s], which the shell already shares. Rename them — a page prop of the same name replaces the shell\'s, and the chrome breaks rather than the page.',
            $component,
            implode(', ', $shadowed),
        ));
    }
}
