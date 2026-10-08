<?php

declare(strict_types=1);

namespace App\Platform\Portal;

use Cbox\Id\Federation\ProviderCatalog;

/**
 * STEP-BY-STEP GUIDES for the identity providers an IT administrator actually runs, as the
 * Admin Portal shows them: which screen to open, what to click, and — the part people get
 * wrong — WHICH of our values goes into WHICH of their fields, by the name their admin
 * screen gives that field.
 *
 * Not {@see ProviderCatalog}. That is the framework's list of providers WE sign in to with
 * OAuth credentials the customer creates (Google, GitHub, Apple…); this is the opposite
 * direction — enterprise identity providers that sign their people in to US over SAML or
 * OIDC, and directories that push their people to us over SCIM. The framework's catalogue
 * deliberately leaves SCIM out for the same reason, and the two would share nothing but a
 * few vendor names.
 *
 * The sentences are translated (`lang/{locale}/portal.php`, `portal.guides.*`); the field
 * names are NOT — "Audience URI (SP Entity ID)" is what the person will find on Okta's
 * screen, whatever language this page is in.
 *
 * `fields` maps each of OUR values to THEIR field: `acs_url`, `entity_id`, `redirect_uri`,
 * `scim_base_url`, `scim_token`, plus `acs_regex` for OneLogin's validator and a `literal`
 * for a value that is the same for everybody. `returns` names what they bring back from
 * their screen: a metadata URL or file, or an issuer and client credentials.
 */
final class PortalGuides
{
    /**
     * @var list<array{key: string, name: string, protocol: 'saml'|'oidc', fields: list<array{ours: string, theirs: string, literal?: string}>, returns: array{kind: 'url'|'xml'|'url_or_xml'|'oidc', theirs: string}, docs: ?string}>
     */
    private const array SSO = [
        [
            'key' => 'okta',
            'name' => 'Okta',
            'protocol' => 'saml',
            'fields' => [
                ['ours' => 'acs_url', 'theirs' => 'Single sign-on URL'],
                ['ours' => 'entity_id', 'theirs' => 'Audience URI (SP Entity ID)'],
                ['ours' => 'literal', 'theirs' => 'Name ID format', 'literal' => 'EmailAddress'],
                ['ours' => 'literal', 'theirs' => 'Application username', 'literal' => 'Email'],
            ],
            'returns' => ['kind' => 'url', 'theirs' => 'Metadata URL'],
            'docs' => 'https://help.okta.com/en-us/content/topics/apps/apps_app_integration_wizard_saml.htm',
        ],
        [
            'key' => 'entra',
            'name' => 'Microsoft Entra ID',
            'protocol' => 'saml',
            'fields' => [
                ['ours' => 'entity_id', 'theirs' => 'Identifier (Entity ID)'],
                ['ours' => 'acs_url', 'theirs' => 'Reply URL (Assertion Consumer Service URL)'],
                ['ours' => 'literal', 'theirs' => 'Unique User Identifier (Name ID)', 'literal' => 'user.mail'],
            ],
            'returns' => ['kind' => 'url', 'theirs' => 'App Federation Metadata Url'],
            'docs' => 'https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/add-application-portal-setup-sso',
        ],
        [
            'key' => 'google',
            'name' => 'Google Workspace',
            'protocol' => 'saml',
            'fields' => [
                ['ours' => 'acs_url', 'theirs' => 'ACS URL'],
                ['ours' => 'entity_id', 'theirs' => 'Entity ID'],
                ['ours' => 'literal', 'theirs' => 'Name ID format', 'literal' => 'EMAIL'],
                ['ours' => 'literal', 'theirs' => 'Name ID', 'literal' => 'Basic Information > Primary email'],
            ],
            'returns' => ['kind' => 'xml', 'theirs' => 'IdP metadata (DOWNLOAD METADATA)'],
            'docs' => 'https://support.google.com/a/answer/6087519',
        ],
        [
            'key' => 'onelogin',
            'name' => 'OneLogin',
            'protocol' => 'saml',
            'fields' => [
                ['ours' => 'entity_id', 'theirs' => 'Audience (EntityID)'],
                ['ours' => 'acs_url', 'theirs' => 'Recipient'],
                ['ours' => 'acs_regex', 'theirs' => 'ACS (Consumer) URL Validator'],
                ['ours' => 'acs_url', 'theirs' => 'ACS (Consumer) URL'],
                ['ours' => 'literal', 'theirs' => 'SAML nameID format', 'literal' => 'Email'],
            ],
            'returns' => ['kind' => 'url', 'theirs' => 'Issuer URL'],
            'docs' => null,
        ],
        [
            'key' => 'jumpcloud',
            'name' => 'JumpCloud',
            'protocol' => 'saml',
            'fields' => [
                ['ours' => 'entity_id', 'theirs' => 'SP Entity ID'],
                ['ours' => 'acs_url', 'theirs' => 'ACS URLs'],
                ['ours' => 'literal', 'theirs' => 'SAMLSubject NameID', 'literal' => 'email'],
                ['ours' => 'literal', 'theirs' => 'SAMLSubject NameID Format', 'literal' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'],
            ],
            'returns' => ['kind' => 'xml', 'theirs' => 'Export Metadata'],
            'docs' => null,
        ],
        [
            'key' => 'pingfederate',
            'name' => 'PingFederate',
            'protocol' => 'saml',
            'fields' => [
                ['ours' => 'entity_id', 'theirs' => 'Partner\'s Entity ID (Connection ID)'],
                ['ours' => 'acs_url', 'theirs' => 'Assertion Consumer Service URL — Endpoint URL (binding POST)'],
                ['ours' => 'literal', 'theirs' => 'SAML_SUBJECT', 'literal' => 'mail'],
            ],
            'returns' => ['kind' => 'xml', 'theirs' => 'Metadata Export'],
            'docs' => null,
        ],
        [
            'key' => 'saml',
            'name' => 'SAML 2.0',
            'protocol' => 'saml',
            'fields' => [
                ['ours' => 'entity_id', 'theirs' => 'SP Entity ID / Audience'],
                ['ours' => 'acs_url', 'theirs' => 'ACS URL / Reply URL'],
                ['ours' => 'literal', 'theirs' => 'NameID format', 'literal' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'],
            ],
            'returns' => ['kind' => 'url_or_xml', 'theirs' => 'IdP metadata'],
            'docs' => null,
        ],
        [
            'key' => 'oidc',
            'name' => 'OpenID Connect',
            'protocol' => 'oidc',
            'fields' => [
                ['ours' => 'redirect_uri', 'theirs' => 'Redirect URI / Callback URL'],
                ['ours' => 'literal', 'theirs' => 'Scopes', 'literal' => 'openid email profile'],
            ],
            'returns' => ['kind' => 'oidc', 'theirs' => 'Issuer URL, Client ID, Client secret'],
            'docs' => null,
        ],
    ];

    /**
     * @var list<array{key: string, name: string, fields: list<array{ours: string, theirs: string, literal?: string}>, docs: ?string}>
     */
    private const array DIRECTORIES = [
        [
            'key' => 'okta',
            'name' => 'Okta',
            'fields' => [
                ['ours' => 'scim_base_url', 'theirs' => 'SCIM connector base URL'],
                ['ours' => 'literal', 'theirs' => 'Unique identifier field for users', 'literal' => 'userName'],
                ['ours' => 'literal', 'theirs' => 'Authentication Mode', 'literal' => 'HTTP Header'],
                ['ours' => 'scim_token', 'theirs' => 'Authorization (Bearer)'],
            ],
            'docs' => 'https://help.okta.com/en-us/content/topics/apps/apps_app_integration_wizard_scim.htm',
        ],
        [
            'key' => 'entra',
            'name' => 'Microsoft Entra ID',
            'fields' => [
                ['ours' => 'scim_base_url', 'theirs' => 'Tenant URL'],
                ['ours' => 'scim_token', 'theirs' => 'Secret Token'],
            ],
            'docs' => 'https://learn.microsoft.com/en-us/entra/identity/app-provisioning/use-scim-to-provision-users-and-groups',
        ],
        [
            'key' => 'onelogin',
            'name' => 'OneLogin',
            'fields' => [
                ['ours' => 'scim_base_url', 'theirs' => 'SCIM Base URL'],
                ['ours' => 'scim_token', 'theirs' => 'SCIM Bearer Token'],
            ],
            'docs' => null,
        ],
        [
            'key' => 'jumpcloud',
            'name' => 'JumpCloud',
            'fields' => [
                ['ours' => 'scim_base_url', 'theirs' => 'Base URL'],
                ['ours' => 'scim_token', 'theirs' => 'Token Key'],
            ],
            'docs' => null,
        ],
        [
            'key' => 'scim',
            'name' => 'SCIM 2.0',
            'fields' => [
                ['ours' => 'scim_base_url', 'theirs' => 'SCIM base URL'],
                ['ours' => 'scim_token', 'theirs' => 'Authorization: Bearer'],
            ],
            'docs' => null,
        ],
    ];

    /**
     * The single sign-on guides, in the order the portal offers them.
     *
     * @return list<array{key: string, name: string, protocol: 'saml'|'oidc', fields: list<array{ours: string, theirs: string, literal?: string}>, returns: array{kind: 'url'|'xml'|'url_or_xml'|'oidc', theirs: string}, docs: ?string, steps: list<string>}>
     */
    public static function sso(): array
    {
        $guides = [];

        foreach (self::SSO as $guide) {
            $guides[] = [
                'key' => $guide['key'],
                'name' => $guide['name'],
                'protocol' => $guide['protocol'],
                'fields' => $guide['fields'],
                'returns' => $guide['returns'],
                'docs' => $guide['docs'],
                'steps' => self::steps('sso', $guide['key']),
            ];
        }

        return $guides;
    }

    /**
     * The directory-sync guides — SCIM 2.0, which the customer's directory speaks to us.
     *
     * @return list<array{key: string, name: string, fields: list<array{ours: string, theirs: string, literal?: string}>, docs: ?string, steps: list<string>}>
     */
    public static function directories(): array
    {
        $guides = [];

        foreach (self::DIRECTORIES as $guide) {
            $guides[] = [
                'key' => $guide['key'],
                'name' => $guide['name'],
                'fields' => $guide['fields'],
                'docs' => $guide['docs'],
                'steps' => self::steps('directory', $guide['key']),
            ];
        }

        return $guides;
    }

    /** @return list<string> */
    public static function ssoKeys(): array
    {
        return array_column(self::sso(), 'key');
    }

    /**
     * A guide's steps in the visitor's language.
     *
     * @return list<string>
     */
    private static function steps(string $kind, string $key): array
    {
        $steps = __("portal.guides.{$kind}.{$key}");

        return is_array($steps)
            ? array_values(array_filter($steps, 'is_string'))
            : [];
    }
}
