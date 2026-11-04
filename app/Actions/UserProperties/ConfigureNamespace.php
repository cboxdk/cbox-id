<?php

declare(strict_types=1);

namespace App\Actions\UserProperties;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\UserProperties\Contracts\PropertyNamespaces;
use Illuminate\Validation\ValidationException;

#[AsAction(
    name: 'user_property_namespaces.configure',
    summary: 'Create or replace the app and webhook grants for a user-property namespace. Empty grants revoke access without deleting user data.',
    scope: 'user_property_namespaces:write',
    danger: Danger::Critical,
    rest: ['PUT', '/user-property-namespaces/{name}'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
    tag: 'User properties',
)]
final readonly class ConfigureNamespace implements Action
{
    public function __construct(private PropertyNamespaces $namespaces) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->inPath()->max(64),
            Field::list('readers', Field::string('client_id')->max(190))->max(100)->describe('OAuth client ids allowed to read. Omit or use an empty list to revoke all access.'),
            Field::list('writers', Field::string('client_id')->max(190))->max(100)->describe('Reader client ids also allowed to write.'),
            Field::object('webhooks', [])->describe('Map webhook endpoint ids to reader client ids. Omit to remove all webhook grants.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $webhooks = [];
        foreach ($context->array('webhooks') as $endpoint => $client) {
            if (! is_string($endpoint) || ! is_string($client)) {
                throw ValidationException::withMessages(['webhooks' => 'Map endpoint ids to OAuth client ids.']);
            }
            $webhooks[$endpoint] = $client;
        }
        $namespace = $this->namespaces->configure($context->string('name'), $this->clients($context, 'readers'), $this->clients($context, 'writers'), $webhooks);

        return ActionResult::item($namespace, NamespaceFields::present($namespace));
    }

    /** @return list<string> */
    private function clients(ActionContext $context, string $field): array
    {
        $clients = $context->array($field);
        if (! array_is_list($clients)) {
            throw ValidationException::withMessages([$field => 'Use a list of OAuth client ids.']);
        }
        $result = [];
        foreach ($clients as $client) {
            if (! is_string($client)) {
                throw ValidationException::withMessages([$field => 'Use a list of OAuth client ids.']);
            }
            $result[] = $client;
        }

        return $result;
    }
}
