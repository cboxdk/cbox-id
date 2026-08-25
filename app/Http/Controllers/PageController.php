<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Response;
use Inertia\ResponseFactory;

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
        return $this->inertia
            ->render($component, [...$props, 'title' => $title])
            ->withViewData(['title' => $title]);
    }
}
