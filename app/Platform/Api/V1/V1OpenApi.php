<?php

declare(strict_types=1);

namespace App\Platform\Api\V1;

use App\Platform\Api\Problem;
use Symfony\Component\Yaml\Yaml;

/**
 * THE ONE CONTRACT: `openapi.json` for `https://api.cboxid.com/v1`, every plane in one
 * document, a tag per plane.
 *
 * Built from the per-plane documents (`resources/openapi/*.yaml`, themselves built from the
 * action registry), so an operation is described once: its path moved by {@see V1Paths},
 * its responses written in the `/v1` shapes — a resource with `object`, a list as
 * `{object: "list", data, has_more, next_cursor}`, a failure as an RFC 9457 problem. The
 * onboarding service is internal and not in it.
 */
final class V1OpenApi
{
    /** The per-plane documents, in the order their names win a collision, and their tag prefix. */
    private const array SOURCES = [
        'environment' => 'Environment',
        'app-services' => 'Apps',
        'account' => 'Account',
        'organization' => 'Organization',
        'platform' => 'Admin',
    ];

    private const array METHODS = ['get', 'put', 'post', 'patch', 'delete'];

    /** @var array<string, array<string, mixed>> shared responses, already in the `/v1` shape */
    private array $sharedResponses = [];

    public static function path(): string
    {
        return resource_path('openapi/openapi.json');
    }

    /**
     * The document as committed, encoded the one way the build and the check agree on.
     *
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $sources  plane documents by name; read from disk when null
     * @return array<string, mixed>
     */
    public function build(?array $sources = null): array
    {
        $this->sharedResponses = [];

        if ($sources === null) {
            $sources = [];

            foreach (array_keys(self::SOURCES) as $name) {
                $sources[$name] = SpecNode::map(Yaml::parseFile(resource_path("openapi/{$name}.yaml")));
            }
        }

        $components = ['schemas' => [], 'parameters' => [], 'responses' => [], 'securitySchemes' => []];
        $paths = [];
        $tags = [];
        $groups = [];

        foreach (self::SOURCES as $name => $plane) {
            $document = $sources[$name] ?? null;

            if ($document === null) {
                continue;
            }

            $renames = $this->claimComponents($document, $components, $plane);
            $document = SpecNode::map($this->rewriteRefs($document, $renames));
            $documentComponents = SpecNode::map($document['components'] ?? null);
            $own = [];

            foreach ($renames as $section => $map) {
                $entries = SpecNode::map($documentComponents[$section] ?? null);

                foreach ($map as $from => $to) {
                    if (isset($entries[$from])) {
                        $own[$section][$to] = $entries[$from];
                        $components[$section][$to] ??= $entries[$from];
                    }
                }
            }

            foreach (SpecNode::map($document['paths'] ?? null) as $oldPath => $item) {
                if (! is_array($item) || isset($item['servers'])) {
                    continue;
                }

                foreach (self::METHODS as $method) {
                    $operation = $item[$method] ?? null;
                    $target = is_array($operation) ? V1Paths::target($method, $oldPath) : null;

                    if ($target === null) {
                        continue;
                    }

                    $operation = $this->moveOperation(SpecNode::map($operation), array_values(SpecNode::list($item['parameters'] ?? null)), $target, $own, $plane);
                    $newPath = '/v1'.$target['path'];
                    $verb = strtolower($target['method']);

                    $paths[$newPath][$verb] = isset($paths[$newPath][$verb])
                        ? $this->mergeOperations($paths[$newPath][$verb], $operation)
                        : $operation;

                    foreach (SpecNode::list($operation['tags'] ?? null) as $tag) {
                        if (is_string($tag)) {
                            $tags[$tag] = true;
                            $groups[$plane][$tag] = true;
                        }
                    }
                }
            }
        }

        foreach ($this->takeSharedResponses() as $responseName => $response) {
            $components['responses'][$responseName] = $response;
        }

        foreach ($components['schemas'] as $schemaName => $schema) {
            $components['schemas'][$schemaName] = $this->allowObjectMember($schema);
        }

        $components['schemas']['Problem'] = self::problemSchema();
        $components['schemas']['List'] = self::listSchema();

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Cbox ID API',
                'version' => '1.0.0',
                'description' => self::description(),
            ],
            'servers' => [['url' => 'https://api.cboxid.com', 'description' => 'Cbox ID']],
            'tags' => array_map(static fn (string $tag): array => ['name' => $tag], array_keys($tags)),
            'x-tagGroups' => array_map(
                static fn (string $plane, array $planeTags): array => ['name' => $plane, 'tags' => array_keys($planeTags)],
                array_keys($groups),
                $groups,
            ),
            'paths' => $paths,
            'components' => array_filter($components, static fn (array $section): bool => $section !== []),
        ];
    }

    /**
     * The shared responses the operations collected, handed over once.
     *
     * @return array<string, array<string, mixed>>
     */
    private function takeSharedResponses(): array
    {
        $shared = $this->sharedResponses;
        $this->sharedResponses = [];

        return $shared;
    }

    /**
     * Every component of $document gets a name in the merged document: its own when that is
     * free or holds the same thing, `{Plane}{Name}` when another plane's differs.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, array<string, mixed>>  $components
     * @return array<string, array<string, string>>
     */
    private function claimComponents(array $document, array $components, string $plane): array
    {
        $renames = [];

        foreach (SpecNode::map($document['components'] ?? null) as $section => $entries) {
            foreach (SpecNode::map($entries) as $name => $value) {
                $taken = $components[$section][$name] ?? null;
                $renames[$section][$name] = $taken === null || $taken == $value ? $name : $plane.$name;
            }
        }

        return $renames;
    }

    /**
     * @param  array<string, array<string, string>>  $renames
     */
    private function rewriteRefs(mixed $node, array $renames): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && preg_match('#\A\#/components/([^/]+)/(.+)\z#', $value, $m) === 1) {
                $node[$key] = '#/components/'.$m[1].'/'.($renames[$m[1]][$m[2]] ?? $m[2]);

                continue;
            }

            $node[$key] = $this->rewriteRefs($value, $renames);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<mixed>  $itemParameters
     * @param  array{method: string, path: string, id: string|null, inputs: array<string, string>, when: string|null}  $target
     * @param  array<string, array<string, mixed>>  $own
     * @return array<string, mixed>
     */
    private function moveOperation(array $operation, array $itemParameters, array $target, array $own, string $plane): array
    {
        $parameters = [];

        foreach ([...$itemParameters, ...array_values(SpecNode::list($operation['parameters'] ?? null))] as $parameter) {
            $resolved = $this->deref($parameter, $own, 'parameters');

            if (! is_array($resolved) || ($resolved['in'] ?? null) !== 'path') {
                $parameters[] = $parameter;

                continue;
            }

            $name = SpecNode::string($resolved['name'] ?? null);

            if ($name === $target['id']) {
                $parameters[] = ['name' => 'id', ...array_diff_key($resolved, ['name' => true])];

                continue;
            }

            if (array_key_exists($name, $target['inputs'])) {
                $parameters[] = [
                    ...array_diff_key($resolved, ['name' => true, 'in' => true]),
                    'name' => $target['inputs'][$name],
                    'in' => 'query',
                    'required' => true,
                ];
            }
        }

        $operation['parameters'] = $parameters;
        $operation['tags'] = array_map(static fn (mixed $tag): string => $plane.' › '.SpecNode::string($tag), SpecNode::list($operation['tags'] ?? null) ?: ['General']);

        if (isset($operation['operationId']) && is_string($operation['operationId'])) {
            $operation['operationId'] = strtolower($plane).'_'.$operation['operationId'];
        }

        if ($target['when'] !== null) {
            $operation['x-when'] = $target['when'];
        }

        $responses = SpecNode::list($operation['responses'] ?? null);

        foreach ($responses as $status => $response) {
            $code = (int) $status;
            $ref = is_array($response) ? ($response['$ref'] ?? null) : null;

            // A shared response stays shared: the component itself is written in the `/v1`
            // shape, once, for the status it answers with.
            if (is_string($ref) && preg_match('#\A\#/components/responses/(.+)\z#', $ref, $m) === 1 && isset($own['responses'][$m[1]])) {
                $this->sharedResponses[$m[1]] ??= $this->moveResponse($code, $own['responses'][$m[1]]);

                continue;
            }

            $responses[$status] = $this->moveResponse($code, $response);
        }

        if (array_key_exists('responses', $operation)) {
            $operation['responses'] = $responses;
        }

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    private function moveResponse(int $status, mixed $response): array
    {
        if (! is_array($response)) {
            return ['description' => ''];
        }

        $response = SpecNode::map($response);

        if ($status >= 400) {
            return array_filter([
                'description' => $response['description'] ?? '',
                'headers' => $response['headers'] ?? null,
                'content' => [Problem::CONTENT_TYPE => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
            ], static fn (mixed $value): bool => $value !== null);
        }

        $content = SpecNode::map($response['content'] ?? null);
        $json = SpecNode::map($content['application/json'] ?? null);
        $schema = $json['schema'] ?? null;

        if (! is_array($schema)) {
            return $response;
        }

        $properties = SpecNode::map($schema['properties'] ?? null);

        if (isset($properties['approval'])) {
            $json['schema'] = [
                'allOf' => [
                    $properties['approval'],
                    ['type' => 'object', 'required' => ['object'], 'properties' => ['object' => ['const' => 'approval'], 'detail' => ['type' => ['string', 'null']]]],
                ],
            ];
            $content['application/json'] = $json;
            $response['content'] = $content;

            return $response;
        }

        if (! isset($properties['data']) || ! is_array($properties['data'])) {
            return $response;
        }

        $data = $properties['data'];
        $isList = ($data['type'] ?? null) === 'array' || isset($data['items']);

        $json['schema'] = $isList
            ? ['allOf' => [['$ref' => '#/components/schemas/List'], ['type' => 'object', 'properties' => ['data' => $data]]]]
            : ['allOf' => [$this->allowObjectMember($data), ['type' => 'object', 'required' => ['object'], 'properties' => ['object' => ['type' => 'string']]]]];
        $content['application/json'] = $json;
        $response['content'] = $content;

        return $response;
    }

    /** A schema that refuses unknown members must still admit `object`. */
    private function allowObjectMember(mixed $schema): mixed
    {
        if (is_array($schema) && ($schema['additionalProperties'] ?? null) === false) {
            $properties = SpecNode::map($schema['properties'] ?? null);
            $properties['object'] ??= ['type' => 'string'];
            $schema['properties'] = $properties;
        }

        return $schema;
    }

    /**
     * Two operations at one path and method: the planes that share a resource, or the old
     * operations one update is made of. One description, every credential either accepts,
     * every response either documents, every field either takes.
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return array<string, mixed>
     */
    private function mergeOperations(array $first, array $second): array
    {
        $first['security'] = array_values(array_unique([...array_values(SpecNode::list($first['security'] ?? null)), ...array_values(SpecNode::list($second['security'] ?? null))], SORT_REGULAR));
        $first['responses'] = SpecNode::list($first['responses'] ?? null) + SpecNode::list($second['responses'] ?? null);
        $tags = array_map(SpecNode::string(...), [...array_values(SpecNode::list($first['tags'] ?? null)), ...array_values(SpecNode::list($second['tags'] ?? null))]);
        $first['tags'] = array_values(array_unique($tags));
        $description = SpecNode::string($second['description'] ?? null);
        $first['description'] = trim(SpecNode::string($first['description'] ?? null)."\n\n".SpecNode::string($second['summary'] ?? null).($description !== '' ? ': '.$description : ''));

        $parameters = array_values(SpecNode::list($first['parameters'] ?? null));
        $names = array_map(static fn (mixed $p): string => is_array($p) ? SpecNode::string($p['$ref'] ?? $p['name'] ?? null) : '', $parameters);

        foreach (SpecNode::list($second['parameters'] ?? null) as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = SpecNode::string($parameter['$ref'] ?? $parameter['name'] ?? null);

            if (! in_array($name, $names, true)) {
                $parameters[] = ['required' => false, ...SpecNode::map($parameter), ...(($parameter['in'] ?? null) === 'query' ? ['required' => false] : [])];
            }
        }

        $first['parameters'] = $parameters;

        $firstBody = SpecNode::map($first['requestBody'] ?? null);
        $secondBody = SpecNode::map($second['requestBody'] ?? null);
        $a = SpecNode::map(SpecNode::map($firstBody['content'] ?? null)['application/json'] ?? null)['schema'] ?? null;
        $b = SpecNode::map(SpecNode::map($secondBody['content'] ?? null)['application/json'] ?? null)['schema'] ?? null;

        if (is_array($a) && is_array($b) && isset($a['properties'], $b['properties'])) {
            $firstBody['content'] = ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [...SpecNode::map($a['properties']), ...SpecNode::map($b['properties'])],
            ]]];
            $firstBody['required'] = true;
            $first['requestBody'] = $firstBody;
        } elseif ($a === null && is_array($b)) {
            $first['requestBody'] = $second['requestBody'];
        }

        unset($first['x-when']);

        return $first;
    }

    /**
     * @param  array<string, array<string, mixed>>  $own
     */
    private function deref(mixed $node, array $own, string $section): mixed
    {
        if (is_array($node) && isset($node['$ref']) && is_string($node['$ref'])
            && preg_match('#\A\#/components/'.$section.'/(.+)\z#', $node['$ref'], $m) === 1) {
            // The document's own components, under the names they have in the merged one.
            return $own[$section][$m[1]] ?? $node;
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private static function problemSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'An RFC 9457 problem. `error` is the stable machine code; `type` resolves to its entry in the reference.',
            'required' => ['type', 'title', 'status', 'detail', 'error'],
            'properties' => [
                'type' => ['type' => 'string', 'format' => 'uri'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'error' => ['type' => 'string'],
                'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function listSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'A page of a list. Pass `next_cursor` as `after` for the next one.',
            'required' => ['object', 'data', 'has_more', 'next_cursor'],
            'properties' => [
                'object' => ['const' => 'list'],
                'data' => ['type' => 'array'],
                'has_more' => ['type' => 'boolean'],
                'next_cursor' => ['type' => ['string', 'null']],
            ],
        ];
    }

    private static function description(): string
    {
        return <<<'MD'
        Every Cbox ID management plane on one host: `https://api.cboxid.com/v1`.

        **The credential decides where a request acts, never the host.**

        - An environment key (`Bearer cbid_env_…`) acts in the environment it was minted in.
        - An access token acts in the environment that issued it (`iss`). A token for the platform root acts for its person: the organization they are on, their own account (`/v1/me`), and — for an operator — the deployment (`/v1/admin`). To act inside one environment of the organization, name it in `Cbox-Environment` (its id or slug).
        - An organization key (`Bearer cbid_org_…`) acts on its organization: `/v1/organization`, `/v1/projects`, `/v1/environments`, `/v1/members`, `/v1/invitations`, `/v1/organization-keys`.

        A token is accepted for the audience `https://api.cboxid.com` or `https://app.cboxid.com/mcp`, and, during the transition, an issuer's own `{issuer}/mcp`.

        **Shapes.** A resource is the object itself, with `object` naming what it is. A list is `{"object": "list", "data": […], "has_more": …, "next_cursor": …}`; pass `next_cursor` as `after` for the next page. A failure is an RFC 9457 problem (`application/problem+json`) whose `error` is a stable code and whose `type` links to its explanation; a validation failure adds `errors`, keyed by field. An action that needs a person's approval answers `202` with the approval it waits for (`object: "approval"`) and its `poll_url`.

        **A child is addressed directly.** `/v1/organization-memberships/{id}` is a member of an organization; the organization is named in the query (`organization_id`). The same holds for invitations, role assignments, portal links and the other children listed below.

        **The old paths.** `https://{environment}.cboxid.com/api/v1/…` keeps answering, with `Deprecation` and `Sunset` headers, until its callers have moved.
        MD;
    }
}
