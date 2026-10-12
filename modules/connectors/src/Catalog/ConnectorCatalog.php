<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Catalog;

use App\Http\Controllers\Console\LogStreamController;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectorDescriptor;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Federation\Enums\ProviderCapability;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Pipes\PipeProviderCatalog;
use Cbox\LaravelSiem\Enums\Destination;

/**
 * Every connector TYPE the platform has, in the order the catalog shows them, each naming
 * the console pages where it is set up and managed.
 *
 * WHY THIS IS THE ONE PLACE TO START AN INTEGRATION, and not a duplicate of the rail. The
 * same ten kinds of connection are filed across four areas, each where its everyday reader
 * looks for it — Enterprise SSO and Directory Sync under Authentication, Webhooks, Hooks and
 * Pipes under Developers, Log streams under Monitoring, Outbound provisioning and SAML apps
 * under Advanced. That filing is right for the person who already knows which page they
 * want. It is no help to the person asking "what can this connect to, what is connected,
 * and what is broken" — the question an integration owner arrives with — and no other page
 * answers it. So the catalog sets nothing up itself: it shows what exists, counts what is
 * live, and every card leads into the page that does the work, with the kind already chosen
 * where the form can take it.
 *
 * Deliberately a hand-written list rather than discovery. The kinds are fixed by the pages
 * that exist, and a discovery mechanism could list a kind no page can set up.
 */
class ConnectorCatalog
{
    public const string SIGN_IN = 'Sign-in';

    public const string PEOPLE_IN = 'Sync people in';

    public const string PEOPLE_OUT = 'Send people out';

    public const string EVENTS = 'Events and hooks';

    public const string APIS = 'Third-party APIs';

    public function __construct(private readonly DirectoryConnectors $directoryConnectors) {}

    /**
     * Every connector type, in console display order.
     *
     * @return list<ConnectorDescriptor>
     */
    public function all(): array
    {
        return [
            new ConnectorDescriptor(
                key: 'enterprise-sso',
                category: ConnectorCategory::Federation,
                name: Vocabulary::ENTERPRISE_SSO,
                summary: 'An organization\'s own SAML or OIDC identity provider — Okta, Entra ID, Google Workspace — so its people sign in with their work account.',
                group: self::SIGN_IN,
                indexRoute: 'connections',
                createRoute: 'connections.create',
                showRoute: 'connections.show',
                helpTopic: HelpTopic::SingleSignOn,
                organizationFilter: true,
            ),
            new ConnectorDescriptor(
                key: 'social-login',
                category: ConnectorCategory::Social,
                name: Vocabulary::SOCIAL_LOGIN,
                summary: 'Google, GitHub, Apple and other buttons on the sign-in page, for people who would rather not make another password.',
                group: self::SIGN_IN,
                indexRoute: 'social-providers',
                // The list IS where a provider is added: its form opens with `?provider=`.
                createRoute: 'social-providers',
                showRoute: null,
                helpTopic: HelpTopic::SocialSignIn,
                optionParameter: 'provider',
                options: self::socialProviders(),
                organizationFilter: true,
                rowParameter: 'edit',
            ),
            new ConnectorDescriptor(
                key: 'directory-sync',
                category: ConnectorCategory::Directory,
                name: Vocabulary::DIRECTORY_SYNC,
                summary: 'An organization\'s identity provider creates, updates and removes its people here — over SCIM, or pulled from Google Workspace or Entra.',
                group: self::PEOPLE_IN,
                indexRoute: 'directories',
                createRoute: 'directories.create',
                showRoute: 'directories.show',
                helpTopic: HelpTopic::SyncUsersIn,
                optionParameter: 'provider',
                options: $this->directoryProviders(hris: false),
                organizationFilter: true,
            ),
            new ConnectorDescriptor(
                key: 'hr-systems',
                category: ConnectorCategory::Hris,
                name: 'HR systems',
                summary: 'People arrive on their start date and leave on their last day, read from the HR system the company already keeps.',
                group: self::PEOPLE_IN,
                indexRoute: 'directories',
                createRoute: 'directories.create',
                showRoute: 'directories.show',
                docsPath: 'guides/hris',
                optionParameter: 'provider',
                options: $this->directoryProviders(hris: true),
                organizationFilter: true,
            ),
            new ConnectorDescriptor(
                key: 'scim-provisioning',
                category: ConnectorCategory::Provisioning,
                name: 'Outbound SCIM provisioning',
                summary: 'Push your people into another app over its SCIM 2.0 endpoint, so onboarding and offboarding happen in one place.',
                group: self::PEOPLE_OUT,
                indexRoute: 'provisioning',
                createRoute: 'provisioning.create',
                showRoute: 'provisioning.show',
                helpTopic: HelpTopic::SyncUsersOut,
                organizationFilter: true,
                opens: ConnectorDescriptor::OPENS_OWN,
            ),
            new ConnectorDescriptor(
                key: 'saml-apps',
                category: ConnectorCategory::SamlApp,
                name: Vocabulary::SAML_APPS,
                summary: 'An app that accepts this environment as its SAML identity provider, so your users sign in to it with the account they have here.',
                group: self::PEOPLE_OUT,
                indexRoute: 'sso-providers',
                createRoute: 'sso-providers.create',
                showRoute: 'sso-providers.show',
                helpTopic: HelpTopic::SamlApplications,
            ),
            new ConnectorDescriptor(
                key: 'webhook',
                category: ConnectorCategory::Webhook,
                name: 'Event webhooks',
                summary: 'A signed message to your endpoint after something happens — a member joined, a role changed — so your systems react without polling.',
                group: self::EVENTS,
                indexRoute: 'webhooks',
                createRoute: 'webhooks.create',
                showRoute: 'webhooks.show',
                helpTopic: HelpTopic::Webhooks,
                organizationFilter: true,
            ),
            new ConnectorDescriptor(
                key: 'hooks',
                category: ConnectorCategory::Hook,
                name: Vocabulary::HOOKS,
                summary: 'Your endpoint is asked during a sign-in or a token issuance and can add claims or refuse — use it when the answer has to change the outcome.',
                group: self::EVENTS,
                indexRoute: 'hooks',
                createRoute: 'hooks.create',
                showRoute: 'hooks.show',
                helpTopic: HelpTopic::InlineHooks,
                organizationFilter: true,
            ),
            new ConnectorDescriptor(
                key: 'log-streams',
                category: ConnectorCategory::LogStream,
                name: Vocabulary::LOG_STREAMS,
                summary: 'Every audit log entry mirrored into your SIEM or a bucket as it is written — Splunk, Datadog, Elastic, S3.',
                group: self::EVENTS,
                indexRoute: 'audit-streams',
                createRoute: 'audit-streams.create',
                showRoute: 'audit-streams.show',
                helpTopic: HelpTopic::LogStreaming,
                optionParameter: 'destination',
                options: self::destinations(),
                opens: ConnectorDescriptor::OPENS_PLANE,
            ),
            new ConnectorDescriptor(
                key: 'pipes',
                category: ConnectorCategory::Pipe,
                name: Vocabulary::PIPES,
                summary: 'People connect their own GitHub, Google or Slack account, and the apps you grant call those APIs as them with a token kept fresh here.',
                group: self::APIS,
                indexRoute: 'pipes',
                createRoute: 'pipes.create',
                showRoute: 'pipes.show',
                helpTopic: HelpTopic::Pipes,
                optionParameter: 'provider',
                options: self::pipeProviders(),
            ),
        ];
    }

    /**
     * The section headings, in order.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        return [self::SIGN_IN, self::PEOPLE_IN, self::PEOPLE_OUT, self::EVENTS, self::APIS];
    }

    public function find(string $key): ?ConnectorDescriptor
    {
        foreach ($this->all() as $descriptor) {
            if ($descriptor->key === $key) {
                return $descriptor;
            }
        }

        return null;
    }

    public function forCategory(ConnectorCategory $category): ?ConnectorDescriptor
    {
        foreach ($this->all() as $descriptor) {
            if ($descriptor->category === $category) {
                return $descriptor;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private static function socialProviders(): array
    {
        $options = [];

        foreach (ProviderCatalog::withCapability(ProviderCapability::Login) as $template) {
            $options[$template->key] = $template->name;
        }

        return $options;
    }

    /**
     * The directory kinds the create form offers — the same filter it applies, so a shortcut
     * never preselects a provider the form then leaves out.
     *
     * @return array<string, string>
     */
    private function directoryProviders(bool $hris): array
    {
        $options = [];

        foreach (DirectoryProvider::cases() as $provider) {
            if ($provider->isHris() !== $hris || ($provider->isPull() && ! $this->directoryConnectors->has($provider))) {
                continue;
            }

            $options[$provider->value] = $provider->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function destinations(): array
    {
        $options = [];

        foreach (Destination::cases() as $destination) {
            $options[$destination->value] = LogStreamController::DESTINATIONS[$destination->value];
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function pipeProviders(): array
    {
        $options = [];

        foreach (PipeProviderCatalog::all() as $provider) {
            $options[$provider->key] = $provider->name;
        }

        return $options;
    }
}
