<?php

declare(strict_types=1);

namespace App\Http\Props;

use Illuminate\Contracts\Support\Arrayable;
use Inertia\PropsResolver;

/**
 * A page's data, as a typed object rather than an associative array.
 *
 * Inertia resolves `Arrayable` props recursively ({@see PropsResolver}), so an
 * implementation of this interface can be handed straight to `Inertia::render()` and
 * arrives in React as a plain JSON object. The interface exists for what an array cannot
 * give us:
 *
 *  - a NAME, so `php artisan inertia:types` can generate the matching TypeScript
 *    declaration and a React page's props are checked against what the server sends;
 *  - a CONSTRUCTOR, so a prop cannot be assembled half-built and reach the browser with
 *    a key missing that the page assumed;
 *  - a place for the derivation to live, so two controllers rendering the same page
 *    cannot disagree about what "the organization" means.
 *
 * @extends Arrayable<string, mixed>
 */
interface Prop extends Arrayable {}
