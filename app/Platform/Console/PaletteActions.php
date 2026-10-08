<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shell\NavAreaProps;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Connect\ActionSnippets;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * ⌘K's ACTIONS — "Create app", "Rotate app secret…" — read from the action registry, so a
 * new action with a console form is offered in the palette without anyone listing it.
 *
 * Each is a DEEP LINK, never a run: the palette takes a person to the form, where the
 * console's own step-up, confirmation and explanations are. "Create" lands on the create
 * page; a verb that needs a record first ("Rotate…") lands on the list to pick one from —
 * hence the ellipsis.
 *
 * OFFERED ONLY WHERE THE RAIL OFFERS THE PAGE. An action whose list page this console does
 * not show — an entitlement it lacks, a customer's narrowed console — is not offered,
 * because a palette entry that 404s is exactly the dead affordance that taught people to
 * stop pressing ⌘K the last time.
 */
final readonly class PaletteActions
{
    /** The verbs offered, and how each is said. */
    private const array VERBS = ['create' => 'Create', 'rotate' => 'Rotate', 'invite' => 'Invite'];

    /**
     * Where the derived name reads wrong.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'keys.create' => 'Create agent key',
        'keys.workspace.create' => 'Create workspace key',
        'keys.environment.create' => 'Create environment key',
        'team.invite' => 'Invite a teammate',
        'apps.secrets.rotate' => 'Rotate app secret',
        'webhooks.secret.rotate' => 'Rotate webhook signing secret',
        'directories.token.rotate' => 'Rotate directory token',
        'hooks.create' => 'Create inline hook',
        'signin.social.set' => 'Add social sign-in',
    ];

    /** @var array<string, string> */
    private const array WORDS = ['sso' => 'SSO', 'api' => 'API', 'saml' => 'SAML', 'sod' => 'SoD'];

    public function __construct(
        private ActionRegistry $registry,
        private ActionSnippets $snippets,
        private Router $router,
    ) {}

    /**
     * @param  list<NavAreaProps>  $areas  The rail this person sees.
     * @return list<array{name: string, label: string, href: string}>
     */
    public function for(array $areas): array
    {
        $offered = [];

        foreach ($areas as $area) {
            foreach ($area->pages as $page) {
                $offered[$page->route] = true;
            }
        }

        $out = [];

        foreach ($this->registry->all() as $action) {
            $verb = self::VERBS[substr($action->name, (int) strrpos($action->name, '.') + 1)] ?? null;

            if ($verb === null) {
                continue;
            }

            foreach ($action->consoleRoutes as $consoleRoute) {
                $href = $this->href($consoleRoute, $offered, $verb === 'Create');

                if ($href !== null) {
                    $out[$action->name] = ['name' => $action->name, 'label' => $this->label($action, $verb), 'href' => $href];

                    break;
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * The page the palette sends a person to for this write route, on THIS console — or
     * null when it is another console's, or its list is not on this person's rail.
     *
     * @param  array<string, true>  $offered
     */
    private function href(string $consoleRoute, array $offered, bool $creates): ?string
    {
        $host = $this->snippets->hostOf($consoleRoute);

        if ($host === null) {
            return null;
        }

        // The list the host belongs to: `environment.clients.show` → `environment.clients`.
        $list = isset($offered[$host]) ? $host : preg_replace('/\.(show|create|edit|settings|secrets|scopes)$/', '', $host);

        if (! is_string($list) || ! isset($offered[$list])) {
            return null;
        }

        // A create form of its own, when the list has one; the list otherwise — where a verb
        // that needs a record first picks it.
        foreach ($creates ? [$list.'.create', $host, $list] : [$list] as $candidate) {
            $route = $this->router->getRoutes()->getByName($candidate);

            if ($route instanceof Route && $route->parameterNames() === []) {
                return route($candidate);
            }
        }

        return null;
    }

    private function label(ActionDefinition $action, string $verb): string
    {
        if (isset(self::LABELS[$action->name])) {
            $label = self::LABELS[$action->name];
        } else {
            $segments = explode('.', $action->name);
            array_pop($segments);

            $words = [];

            foreach ($segments as $segment) {
                $parts = explode('_', $segment);
                $last = array_key_last($parts);
                $parts[$last] = self::singular($parts[$last]);

                foreach ($parts as $part) {
                    $words[] = self::WORDS[$part] ?? $part;
                }
            }

            $label = $verb.' '.implode(' ', $words);
        }

        // A verb that needs a record first opens the list to pick one from.
        return $verb === 'Create' ? $label : $label.'…';
    }

    private static function singular(string $word): string
    {
        return match (true) {
            str_ends_with($word, 'ies') => substr($word, 0, -3).'y',
            str_ends_with($word, 'ss') => $word,
            str_ends_with($word, 's') => substr($word, 0, -1),
            default => $word,
        };
    }
}
