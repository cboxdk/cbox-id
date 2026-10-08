<?php

declare(strict_types=1);

namespace App\Platform\Connect;

use App\Http\Props\Console\ApiEquivalentProps;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Approvals\ApprovalInput;
use App\Platform\Actions\Input\Field;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * "</> API" FOR EVERY CONSOLE ACTION: which actions a console page hosts, and the facts each
 * one's API twin is written from.
 *
 * Every console write is an action, and every action is also a REST endpoint, an MCP tool
 * and (for some) a CLI command. The console never said so. A developer who had just done a
 * thing by hand and wanted to script it had to find the endpoint in the reference, guess
 * which fields the form had been sending, and work out the scope — for an operation the
 * console had the exact answer to, a few pixels away.
 *
 * WHICH PAGE HOSTS AN ACTION is not written down anywhere, and should not have to be: the
 * registry names an action's console ROUTES (the POST a form submits to), and the page
 * holding that form is the GET route its URL nests under. `PATCH /admin/apps/{client}` is
 * answered on `GET /admin/apps/{client}`; `POST /admin/apps` on `GET /admin/apps` — and on
 * the pages one literal step below the host (`/admin/apps/new`, `/admin/apps/{client}/settings`),
 * which are its create form and its tabs. A page that does not nest that way names its host
 * in {@see self::HOSTS}, and `ApiEquivalentCoverageTest` fails until one does.
 *
 * THE SNIPPETS ARE NOT WRITTEN HERE. The browser writes them ({@see ApiEquivalentProps}),
 * because "copy as curl" fills in the form's values as they are now.
 */
final class ActionSnippets
{
    /**
     * The `cbox` CLI's commands that exist today, by the action they run. Every other action
     * is shown with the name its command will have, marked as not shipped yet.
     *
     * @var array<string, string>
     */
    private const array SHIPPED_CLI = [
        'keys.create' => 'cbox id keys:create',
        'keys.rotate' => 'cbox id keys:rotate',
        'keys.revoke' => 'cbox id keys:revoke',
        'keys.list' => 'cbox id keys',
        'apis.create' => 'cbox id apis:create',
        'apis.delete' => 'cbox id apis:delete',
        'apis.list' => 'cbox id apis',
        'apis.scopes.define' => 'cbox id apis:scope-set',
        'apis.scopes.remove' => 'cbox id apis:scope-remove',
        'organizations.list' => 'cbox id organizations',
        'users.list' => 'cbox id users',
    ];

    /**
     * A console write route whose form lives on a page its URL does not nest under.
     *
     * @var array<string, string>
     */
    private const array HOSTS = [
        // Ended from the user's page, where their support sessions are listed; the route is
        // flat because a session is ended by its own id.
        'environment.support-sessions.end' => 'environment.users.show',
    ];

    /**
     * Pages that submit to a write route living under ANOTHER page's URL, besides its host —
     * the AI agents pages mint, rotate and revoke management keys through the keys routes.
     *
     * @var array<string, list<string>>
     */
    private const array ALSO = [
        'environment.keys.store' => ['environment.agents', 'environment.agents.create'],
        'environment.keys.rotate' => ['environment.agents'],
        'environment.keys.destroy' => ['environment.agents'],
    ];

    /** The console homes: a write route nesting only under one of these has no host page. */
    private const array ROOTS = ['', 'admin'];

    /** @var array<string, list<string>>|null Page route name → the action names it hosts. */
    private ?array $hosted = null;

    /** @var array<string, string|null>|null Console write route → the page route hosting it. */
    private ?array $hosts = null;

    private int $routeCount = -1;

    /** A route parameter, whatever it is named. */
    private const string PARAMETER = '{}';

    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly Router $router,
    ) {}

    /**
     * What the current console page hosts, keyed by action name — the shared prop.
     *
     * @return array<string, ApiEquivalentProps>
     */
    public function forRequest(Request $request): array
    {
        $route = $request->route();

        if (! $route instanceof Route || $route->getName() === null) {
            return [];
        }

        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
            $parameters[(string) $name] = $value;
        }

        return $this->forPage($route->getName(), $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters  The page's own route parameters, to fill ids in.
     * @return array<string, ApiEquivalentProps>
     */
    public function forPage(string $pageRoute, array $parameters = []): array
    {
        $out = [];

        foreach ($this->map()[$pageRoute] ?? [] as $name) {
            $out[$name] = $this->describe($this->registry->named($name), $parameters);
        }

        return $out;
    }

    /** The page route whose form submits to $consoleRoute, or null when none hosts it. */
    public function hostOf(string $consoleRoute): ?string
    {
        $this->map();

        return $this->hosts[$consoleRoute] ?? null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function describe(ActionDefinition $action, array $parameters = []): ApiEquivalentProps
    {
        $pathFields = $action->input()->pathFields();
        $filled = $this->fill($action->documentedPath(), $parameters);
        $fields = [];

        foreach ($action->input()->fields as $field) {
            $schema = $field->schema();
            // `['string', 'null']` for a nullable field: the first is the type a caller sends.
            $declared = $schema['type'];
            $type = is_array($declared) ? ($declared[0] ?? 'string') : $declared;
            $type = is_string($type) ? $type : 'string';
            $inPath = in_array($field->name, $pathFields, true);
            $enum = $schema['enum'] ?? null;

            $fields[] = [
                'name' => $field->name,
                'type' => $type,
                'required' => $field->isRequired(),
                'in' => $inPath ? 'path' : (in_array($action->method, ['GET', 'DELETE'], true) ? 'query' : 'body'),
                'secret' => self::secret($action, $field),
                'description' => isset($schema['description']) && is_string($schema['description']) ? $schema['description'] : null,
                'enum' => is_array($enum) ? array_values(array_filter($enum, 'is_scalar')) : null,
                'value' => $inPath ? ($filled['values'][$field->name] ?? null) : null,
            ];
        }

        return new ApiEquivalentProps(
            action: $action,
            // The TEMPLATE, placeholders and all: the browser fills it from the form, which may
            // name a different id than the page's own. The filled path is for reading.
            url: url('/api/v1'.$action->documentedPath()),
            path: $filled['path'],
            fields: $fields,
            cli: self::SHIPPED_CLI[$action->name] ?? 'cbox id '.str_replace('.', ':', $action->name),
            cliShipped: isset(self::SHIPPED_CLI[$action->name]),
            sdk: self::sdk($action),
        );
    }

    /**
     * The path with the page's ids in it.
     *
     * By name where the two agree (`{organization}` in both), and otherwise in order — the
     * console and the API both nest outer to inner, so the page's first id is the path's
     * first. A placeholder left over stays a placeholder, which is what the reader replaces.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{path: string, values: array<string, string>}
     */
    private function fill(string $path, array $parameters): array
    {
        $ids = [];

        foreach ($parameters as $name => $value) {
            $id = match (true) {
                $value instanceof Model => $value->getRouteKey(),
                $value instanceof UnitEnum => null,
                default => $value,
            };

            if (is_string($id) || is_int($id)) {
                $ids[$name] = (string) $id;
            }
        }

        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        $placeholders = $matches[1];
        $values = [];
        $positional = array_values($ids);

        foreach ($placeholders as $index => $placeholder) {
            $value = $ids[$placeholder] ?? (count($placeholders) === count($positional) ? $positional[$index] : null);

            if ($value !== null) {
                $values[$placeholder] = $value;
                $path = str_replace('{'.$placeholder.'}', rawurlencode($value), $path);
            }
        }

        return ['path' => $path, 'values' => $values];
    }

    /** A field whose value is a secret — by name, or because the action says so. */
    private static function secret(ActionDefinition $action, Field $field): bool
    {
        return ApprovalInput::isSecretName($field->name) || in_array($field->name, $action->redact, true);
    }

    /**
     * The method on `@cboxdk/id-js/management`'s client for the action's plane: the action's
     * name, camel-cased per segment — `apps.secrets.rotate` is `env.apps.secrets.rotate`,
     * `sso.connections.require_sso` is `env.sso.connections.requireSso`, the SDK's own rule.
     */
    private static function sdk(ActionDefinition $action): string
    {
        $segments = array_map(static fn (string $segment): string => Str::camel(str_replace('-', '_', $segment)), explode('.', $action->name));
        $client = match ($action->plane) {
            ActionPlane::Environment => 'env',
            ActionPlane::Workspace => 'workspace',
            ActionPlane::Platform => 'platform',
            ActionPlane::Account => 'account',
        };

        return $client.'.'.implode('.', $segments);
    }

    /**
     * Page route → hosted actions, worked out once per route table.
     *
     * By hash lookup on each URL with its parameters blanked (`admin/apps/{}`), so it costs
     * one pass over the routes and a few lookups per action, not pages × actions.
     *
     * @return array<string, list<string>>
     */
    private function map(): array
    {
        $routes = $this->router->getRoutes();

        if ($this->hosted !== null && $this->routeCount === count($routes->getRoutes())) {
            return $this->hosted;
        }

        /** @var array<string, string> $pages Normalised URL → page route name. */
        $pages = [];
        /** @var array<string, list<string>> $children Normalised URL → its pages one literal step down. */
        $children = [];

        foreach ($routes->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! in_array('GET', $route->methods(), true) || str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $segments = self::segments($route->uri());
            $pages[implode('/', $segments)] ??= $name;

            $last = array_pop($segments);

            if ($last !== null && $last !== self::PARAMETER) {
                $children[implode('/', $segments)][] = $name;
            }
        }

        $hosted = [];
        $hosts = [];

        foreach ($this->registry->all() as $action) {
            foreach ($action->consoleRoutes as $consoleRoute) {
                $route = $routes->getByName($consoleRoute);

                $host = match (true) {
                    isset(self::HOSTS[$consoleRoute]) => ['name' => self::HOSTS[$consoleRoute], 'uri' => null],
                    $route === null => null,
                    default => self::host($pages, self::segments($route->uri())),
                };

                $hosts[$consoleRoute] = $host['name'] ?? null;

                if ($host === null) {
                    continue;
                }

                // The host, and the pages one literal step below it — its create form, its tabs.
                $alongside = $host['uri'] === null ? [] : ($children[$host['uri']] ?? []);

                foreach ([$host['name'], ...$alongside, ...(self::ALSO[$consoleRoute] ?? [])] as $page) {
                    $hosted[$page][] = $action->name;
                }
            }
        }

        $this->routeCount = count($routes->getRoutes());
        $this->hosts = $hosts;

        return $this->hosted = array_map(static fn (array $names): array => array_values(array_unique($names)), $hosted);
    }

    /**
     * The longest GET page whose URL the write route's URL nests under — none when that is
     * only a console's home.
     *
     * @param  array<string, string>  $pages
     * @param  list<string>  $segments
     * @return array{name: string, uri: string}|null
     */
    private static function host(array $pages, array $segments): ?array
    {
        for ($depth = count($segments); $depth > 0; $depth--) {
            $uri = implode('/', array_slice($segments, 0, $depth));

            if (isset($pages[$uri])) {
                return in_array($uri, self::ROOTS, true) ? null : ['name' => $pages[$uri], 'uri' => $uri];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $uri): array
    {
        $uri = trim($uri, '/');

        // Every parameter alike: `{client}` on the page and `{id}` on the form are one slot.
        return $uri === '' ? [] : array_map(
            static fn (string $segment): string => str_starts_with($segment, '{') ? self::PARAMETER : $segment,
            explode('/', $uri),
        );
    }
}
