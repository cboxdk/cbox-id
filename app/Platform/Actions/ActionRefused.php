<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use RuntimeException;

/**
 * An action declining, with a stable code a machine switches on and a sentence a person
 * reads. `field` names the input it is about, which is where the console shows it.
 *
 * `fields` is for the refusal that is about several inputs at once — every field of a
 * policy that would loosen its floor, every blank parameter a provider needs. Reported one
 * at a time, somebody fixes the first and is refused for the second; so the console shows
 * each on its own field, and a machine reads all of them in the one message.
 */
final class ActionRefused extends RuntimeException
{
    private readonly ?ActionMessage $localizedMessage;

    /** @var array<string, ActionMessage> */
    private readonly array $localizedFields;

    /** @var array<string, string> */
    public readonly array $fields;

    /**
     * @param  array<string, string|ActionMessage>  $fields  Input name => what is wrong with it.
     */
    public function __construct(
        public readonly string $error,
        string|ActionMessage $message,
        public readonly int $status = 422,
        public readonly ?string $field = null,
        array $fields = [],
    ) {
        $this->localizedMessage = $message instanceof ActionMessage ? $message : null;
        $localizedFields = [];
        $plainFields = [];
        foreach ($fields as $name => $value) {
            if ($value instanceof ActionMessage) {
                $localizedFields[$name] = $value;
            }
            $plainFields[$name] = $value instanceof ActionMessage ? $value->render('en') : $value;
        }
        $this->localizedFields = $localizedFields;
        $this->fields = $plainFields;
        parent::__construct($message instanceof ActionMessage ? $message->render('en') : $message);
    }

    /**
     * @param  non-empty-array<string, string|ActionMessage>  $fields  Input name => what is wrong with it.
     */
    public static function onFields(string $error, array $fields): self
    {
        return new self($error, implode(' ', array_map(static fn (string|ActionMessage $message): string => $message instanceof ActionMessage ? $message->render('en') : $message, $fields)), 422, (string) array_key_first($fields), $fields);
    }

    /** Render only at the console boundary; API/MCP messages remain in source English. */
    public function forConsole(): self
    {
        $fields = $this->fields;
        foreach ($this->localizedFields as $name => $message) {
            $fields[$name] = $message->render();
        }

        return new self(
            $this->error,
            $this->localizedMessage?->render() ?? ($this->localizedFields !== [] ? implode(' ', $fields) : $this->getMessage()),
            $this->status,
            $this->field,
            $fields,
        );
    }

    public static function notFound(string $resource): self
    {
        $key = match ($resource) {
            'API' => 'console.missing_resource_api',
            'API key' => 'console.missing_resource_api_key',
            'SAML app' => 'console.missing_resource_saml_app',
            'access review' => 'console.missing_resource_access_review',
            'account' => 'console.missing_resource_account',
            'app' => 'console.missing_resource_app',
            'approval request' => 'console.missing_resource_approval_request',
            'audit log schema' => 'console.missing_resource_audit_log_schema',
            'connected service' => 'console.missing_resource_connected_service',
            'connection' => 'console.missing_resource_connection',
            'directory' => 'console.missing_resource_directory',
            'domain' => 'console.missing_resource_domain',
            'environment' => 'console.missing_resource_environment',
            'export' => 'console.missing_resource_export',
            'feature flag' => 'console.missing_resource_feature_flag',
            'frontend key' => 'console.missing_resource_frontend_key',
            'group' => 'console.missing_resource_group',
            'hook' => 'console.missing_resource_hook',
            'invitation' => 'console.missing_resource_invitation',
            'item' => 'console.missing_resource_item',
            'key' => 'console.missing_resource_key',
            'linked account' => 'console.missing_resource_linked_account',
            'log stream' => 'console.missing_resource_log_stream',
            'member' => 'console.missing_resource_member',
            'namespace' => 'console.missing_resource_namespace',
            'operator' => 'console.missing_resource_operator',
            'organization' => 'console.missing_resource_organization',
            'owner' => 'console.missing_resource_owner',
            'parent organization' => 'console.missing_resource_parent_organization',
            'passkey' => 'console.missing_resource_passkey',
            'payment_method' => 'console.missing_resource_payment_method',
            'permission' => 'console.missing_resource_permission',
            'pipe' => 'console.missing_resource_pipe',
            'portal link' => 'console.missing_resource_portal_link',
            'project' => 'console.missing_resource_project',
            'provider' => 'console.missing_resource_provider',
            'provisioning target' => 'console.missing_resource_provisioning_target',
            'radar decision' => 'console.missing_resource_radar_decision',
            'radar list entry' => 'console.missing_resource_radar_list_entry',
            'radar rule' => 'console.missing_resource_radar_rule',
            'role' => 'console.missing_resource_role',
            'rule' => 'console.missing_resource_rule',
            'scope' => 'console.missing_resource_scope',
            'secret' => 'console.missing_resource_secret',
            'service directory' => 'console.missing_resource_service_directory',
            'session' => 'console.missing_resource_session',
            'sign-in method' => 'console.missing_resource_sign_in_method',
            'social provider' => 'console.missing_resource_social_provider',
            'support session' => 'console.missing_resource_support_session',
            'user' => 'console.missing_resource_user',
            'webhook endpoint' => 'console.missing_resource_webhook_endpoint',
            'workspace' => 'console.missing_resource_workspace',
            default => 'console.missing_resource_unknown',
        };

        return new self('not_found', new ActionMessage($key, sourceEnglish: ucfirst($resource).' not found.'), 404);
    }

    public static function because(string $error, string|ActionMessage $message, ?string $field = null): self
    {
        return new self($error, $message, 422, $field);
    }
}
