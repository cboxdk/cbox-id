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
                summary: __('console.an_organization_s_own_saml_or_oidc_identity__277b4568'),
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
                summary: __('console.google_github_apple_and_other_buttons_on_the_d7f545d7'),
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
                summary: __('console.an_organization_s_identity_provider_creates__959e80cf'),
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
                name: __('console.hr_systems_79a21a1e'),
                summary: __('console.people_arrive_on_their_start_date_and_leave__69a0a1b9'),
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
                name: __('console.outbound_scim_provisioning_437b9b49'),
                summary: __('console.push_your_people_into_another_app_over_its_s_7a73c970'),
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
                summary: __('console.an_app_that_accepts_this_environment_as_its__fe815b51'),
                group: self::PEOPLE_OUT,
                indexRoute: 'sso-providers',
                createRoute: 'sso-providers.create',
                showRoute: 'sso-providers.show',
                helpTopic: HelpTopic::SamlApplications,
            ),
            new ConnectorDescriptor(
                key: 'webhook',
                category: ConnectorCategory::Webhook,
                name: __('console.event_webhooks_7d2326a1'),
                summary: __('console.a_signed_message_to_your_endpoint_after_some_a8f5805d'),
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
                summary: __('console.your_endpoint_is_asked_during_a_sign_in_or_a_5e7a7067'),
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
                summary: __('console.every_audit_log_entry_mirrored_into_your_sie_a5cc2e26'),
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
                summary: __('console.people_connect_their_own_github_google_or_sl_261cd163'),
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
            $options[$destination->value] = LogStreamController::destinations()[$destination->value];
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
