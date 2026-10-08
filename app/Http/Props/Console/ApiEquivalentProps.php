<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Connect\ActionSnippets;

/**
 * One action, as the console's "</> API" disclosure needs it to write the same operation
 * as curl, an MCP tool call, a CLI command and an SDK call.
 *
 * FACTS, NOT SNIPPETS. The snippets are written in the browser, because "Copy as curl" fills
 * in what the form holds NOW — a value the server never saw. What the server knows and the
 * browser cannot is everything else: the method and the path with this page's ids already
 * in it, the scope a key needs, the tool name, which fields are secrets. Built by
 * {@see ActionSnippets} from the action registry, so a renamed route or a new field
 * reaches every snippet without anyone editing one.
 *
 * Mirrored by `ApiAction` in `resources/js/lib/apiSnippets.ts`.
 */
final readonly class ApiEquivalentProps implements Prop
{
    /**
     * @param  list<array{name: string, type: string, required: bool, in: 'path'|'query'|'body', secret: bool, description: string|null, enum: list<scalar>|null, value: string|null}>  $fields
     */
    public function __construct(
        public ActionDefinition $action,
        public string $url,
        public string $path,
        public array $fields,
        public string $cli,
        public bool $cliShipped,
        public string $sdk,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->action->name,
            'summary' => $this->action->summary,
            'method' => $this->action->method,
            'path' => $this->path,
            'url' => $this->url,
            'scope' => $this->action->scope,
            'danger' => $this->action->danger->value,
            'plane' => $this->action->plane->value,
            'tool' => $this->action->toolName(),
            'cli' => $this->cli,
            'cliShipped' => $this->cliShipped,
            // `@cboxdk/id-js/management`, generated from the same OpenAPI documents.
            'sdk' => $this->sdk,
            'sdkPreview' => false,
            'fields' => $this->fields,
            'redact' => $this->action->redact,
        ];
    }
}
