<?php

declare(strict_types=1);

namespace App\Platform\Help;

/**
 * The console's explanation layer: one case per thing an administrator has to
 * understand before the page in front of them means anything.
 *
 * The copy lives HERE, not in the Blade views, for three reasons. It has to be
 * identical wherever the same concept surfaces — a page header, an empty state, the
 * setup checklist all explain "single sign-on" with the same words. It has to be
 * legible to a non-expert: these strings are the difference between a console you can
 * use without the manual and one you cannot. And it has to stay honest about which
 * concepts we have actually written a guide for — {@see docsPath()} returns null
 * where none exists yet, and the UI simply omits the link rather than shipping a 404.
 *
 * WRITING RULES for anything added here. Say what the thing IS in the first
 * sentence, in words a competent IT administrator who has never used this product
 * would recognise. Say WHEN you need it in the second. Never open with the acronym;
 * introduce it in passing ("…automatically, over a standard called SCIM") so the
 * person who came looking for "SCIM" still finds it.
 */
enum HelpTopic: string
{
    case Overview = 'overview';
    case Usage = 'usage';
    case AgentApprovals = 'agent-approvals';
    case TrustedDevices = 'trusted-devices';
    case Members = 'members';
    case Roles = 'roles';
    case Permissions = 'permissions';
    case SingleSignOn = 'single-sign-on';
    case Domains = 'domains';
    case SocialSignIn = 'social-sign-in';
    case SyncUsersIn = 'sync-users-in';
    case SyncUsersOut = 'sync-users-out';
    case AccessReviews = 'access-reviews';
    case RoleConflicts = 'role-conflicts';
    case Apps = 'apps';
    case Apis = 'apis';
    case Webhooks = 'webhooks';
    case InlineHooks = 'inline-hooks';
    case AppCatalog = 'app-catalog';
    case FeatureFlags = 'feature-flags';
    case TokenVault = 'token-vault';
    case Pipes = 'pipes';
    case ActivityLog = 'activity-log';
    case Settings = 'settings';
    case Appearance = 'appearance';
    case AccountSecurity = 'account-security';
    case SessionsAndActivity = 'sessions-and-activity';
    case YourApps = 'your-apps';
    case Preferences = 'preferences';
    case ApiKeys = 'api-keys';
    case MemberApiKeys = 'member-api-keys';
    case OrganizationSettings = 'organization-settings';
    case Projects = 'projects';
    case Team = 'team';
    case Keys = 'keys';
    case EnvironmentDomains = 'environment-domains';
    case Billing = 'billing';
    case EnvironmentOverview = 'environment-overview';
    case EnvironmentGetStarted = 'environment-get-started';
    case ReviewAgentRequests = 'review-agent-requests';
    case Agents = 'agents';
    case ConnectAgent = 'connect-agent';
    case Organizations = 'organizations';
    case Users = 'users';
    case Staff = 'staff';
    case SupportAccess = 'support-access';
    case SignInRules = 'sign-in-rules';
    case SignInMethods = 'sign-in-methods';
    case SamlApplications = 'saml-applications';
    case LegacyLogin = 'legacy-login';
    case Connectors = 'connectors';
    case LogStreaming = 'log-streaming';
    case AuditLogs = 'audit-logs';
    case FineGrainedAuthorization = 'fine-grained-authorization';
    case DataExports = 'data-exports';
    case RiskEvents = 'risk-events';
    case Radar = 'radar';
    case SignInActivity = 'sign-in-activity';
    case Branding = 'branding';
    case AllOrganizations = 'all-organizations';
    case Environments = 'environments';
    case PlatformOrganizations = 'platform-organizations';
    case PlatformUsage = 'platform-usage';
    case PlatformSearch = 'platform-search';
    case PlatformQueues = 'platform-queues';
    case Operators = 'operators';

    /** The popover heading — the concept's name, not the page's. */
    public function title(): string
    {
        return match ($this) {
            self::AppCatalog => __('console.catalog_title'),
            self::Overview => __('console.your_organization_at_a_glance_5abc1baf'),
            self::Usage => __('console.what_counts_as_usage_eb49e1ed'),
            self::AgentApprovals => __('console.approving_on_someone_else_s_screen_1b86f9a5'),
            self::TrustedDevices => __('console.your_phone_as_the_key_afb28fcb'),
            self::Members => __('console.members_and_invitations_aff5e395'),
            self::Roles => __('console.what_roles_do_585ede9d'),
            self::Permissions => __('console.what_a_role_is_made_of_ab5b67f4'),
            self::SingleSignOn => __('console.signing_in_with_your_own_identity_provider_57c0a4a2'),
            self::Domains => __('console.proving_which_email_domains_are_yours_9c5e31e1'),
            self::SocialSignIn => __('console.signing_in_with_an_account_people_already_have_922d7973'),
            self::SyncUsersIn => __('console.keeping_people_up_to_date_automatically_2cf0c98f'),
            self::SyncUsersOut => __('console.pushing_people_out_to_your_other_apps_d57642fb'),
            self::AccessReviews => __('console.certifying_who_still_needs_access_0c8f1d84'),
            self::RoleConflicts => __('console.roles_that_must_not_be_combined_81a47d39'),
            self::Apps => __('console.connecting_an_app_to_cbox_id_10b4e73f'),
            self::Apis => __('console.your_apis_and_who_may_call_them_2052cd06'),
            self::Webhooks => __('console.getting_told_when_something_happens_2816559c'),
            self::InlineHooks => __('console.having_a_say_while_it_happens_15efcb5d'),
            self::FeatureFlags => __('console.features_on_for_some_not_all_57ba7057'),
            self::TokenVault => __('console.credentials_your_apps_use_elsewhere_db36cae9'),
            self::Pipes => __('console.calling_other_services_as_your_users_aed68939'),
            self::ActivityLog => __('console.the_record_of_what_changed_faa475a1'),
            self::Settings => __('console.organization_settings_4fd62541'),
            self::Appearance => __('console.your_branded_sign_in_page_24194071'),
            self::AccountSecurity => __('console.protecting_your_own_sign_in_2ee0bb08'),
            self::SessionsAndActivity => __('console.where_you_are_signed_in_a07510ca'),
            self::YourApps => __('console.account_apps_title'),
            self::Preferences => __('console.account_preferences_title'),
            self::ApiKeys => __('console.keys_for_the_apis_of_the_apps_you_use_ec8ba965'),
            self::MemberApiKeys => __('console.keys_your_people_hold_for_your_apps_a5db71a6'),
            self::OrganizationSettings => __('console.organization_settings_4fd62541'),
            self::Projects => __('console.your_products_and_their_environments_c6945f5f'),
            self::Team => __('console.the_people_who_run_this_organization_41e40bf5'),
            self::Keys => __('console.keys_for_your_own_code_4e040a24'),
            self::EnvironmentDomains => __('console.sign_in_on_your_own_domain_5fbbd0c6'),
            self::Billing => __('console.plans_and_what_they_count_4637cc71'),
            self::EnvironmentOverview => __('console.this_environment_at_a_glance_afe27efd'),
            self::EnvironmentGetStarted => __('console.from_nothing_to_a_first_sign_in_d866f09f'),
            self::ReviewAgentRequests => __('console.approvals_across_the_environment_c9852ac4'),
            self::Agents => __('console.software_that_acts_on_this_environment_fe82778a'),
            self::ConnectAgent => __('console.pointing_an_ai_agent_at_this_environment_9c4d17ac'),
            self::Organizations => __('console.the_teams_using_your_product_1743113f'),
            self::Users => __('console.everyone_who_can_sign_in_here_5536d8c6'),
            self::Staff => __('console.roles_for_your_own_people_53386a8d'),
            self::SupportAccess => __('console.signing_in_to_an_app_as_somebody_else_8574cde1'),
            self::SignInRules => __('console.the_rules_every_sign_in_has_to_meet_6932e88e'),
            self::SignInMethods => __('console.every_way_into_this_environment_ebacca65'),
            self::SamlApplications => __('console.applications_that_trust_this_environment_ab46d3c0'),
            self::LegacyLogin => __('console.signing_in_through_your_old_system_23b062d0'),
            self::Connectors => __('console.every_integration_in_one_place_028e0c56'),
            self::LogStreaming => __('console.sending_the_audit_log_to_your_own_tools_061b9609'),
            self::AuditLogs => __('console.what_your_app_records_about_each_organization_3c1ad833'),
            self::FineGrainedAuthorization => __('console.who_may_do_what_to_which_of_your_app_s_resources_a6227e5b'),
            self::DataExports => __('console.exports_retention_6d540b79'),
            self::RiskEvents => __('console.sign_ins_that_looked_suspicious_5d581685'),
            self::Radar => __('console.allowing_challenging_and_blocking_sign_ins_1df777ec'),
            self::SignInActivity => __('console.sign_ins_over_time_7418e68f'),
            self::Branding => __('console.your_brand_on_the_sign_in_and_in_mail_459efaa4'),
            self::AllOrganizations => __('console.every_organization_on_this_install_c06290a4'),
            self::Environments => __('console.every_environment_on_this_install_8174d93f'),
            self::PlatformOrganizations => __('console.organizations_in_the_target_environment_d859f568'),
            self::PlatformUsage => __('console.usage_across_the_install_a13ed937'),
            self::PlatformSearch => __('console.finding_someone_in_any_environment_f966bf7a'),
            self::PlatformQueues => __('console.the_work_that_happens_after_the_click_09b33e7b'),
            self::Operators => __('console.the_people_who_run_this_install_706d5e9a'),
        };
    }

    /** Two or three sentences: what it is, and when you need it. */
    public function summary(): string
    {
        return match ($this) {
            self::AppCatalog => __('console.catalog_description'),
            self::Overview => __('console.everything_your_organization_has_set_up_and_what_is_lef_2eaad20f'),

            self::Usage => __('console.how_many_people_signed_in_and_how_often_your_apps_calle_bc3b092f'),

            self::TrustedDevices => __('console.a_phone_with_the_authenticator_app_installed_becomes_th_caef4b54'),
            self::AgentApprovals => __('console.when_an_app_or_an_ai_agent_needs_your_go_ahead_to_act_a_51a99858'),

            self::Members => __('console.everyone_who_can_sign_in_to_this_organization_and_the_i_e278dd18'),

            self::Roles => __('console.a_role_is_a_job_title_your_apps_understand_editor_suppo_73cd7eac'),

            self::Permissions => __('console.a_permission_is_one_thing_a_role_is_allowed_to_do_creat_522b0034'),

            self::SingleSignOn => __('console.lets_your_people_sign_in_with_the_company_account_they_5186cda3'),

            self::Domains => __('console.the_email_domains_this_organization_owns_each_proved_wi_02a41e13'),

            self::SocialSignIn => __('console.offers_google_github_apple_and_others_as_buttons_on_you_82b5af03'),

            self::SyncUsersIn => __('console.your_identity_provider_creates_updates_and_deactivates_2d9dc172'),

            self::SyncUsersOut => __('console.the_mirror_image_cbox_id_pushes_your_people_into_the_ot_19c0ed40'),

            self::AccessReviews => __('console.a_round_where_you_go_through_who_holds_which_role_and_c_617023b9'),

            self::RoleConflicts => __('console.some_pairs_of_roles_must_never_sit_with_the_same_person_70e4454a'),

            self::Apps => __('console.every_app_that_signs_people_in_through_cbox_id_or_calls_1e151c65'),

            self::Apis => __('console.an_api_is_a_service_of_yours_that_receives_access_token_2747c516'),

            self::Webhooks => __('console.cbox_id_posts_a_signed_message_to_your_endpoint_after_s_8dadac57'),

            self::InlineHooks => __('console.these_run_in_the_middle_of_an_operation_not_after_it_an_c325ec80'),

            self::FeatureFlags => __('console.a_feature_flag_is_a_switch_your_apps_ask_about_for_one_9a43d208'),

            self::Pipes => __('console.each_pipe_lets_people_connect_their_own_account_at_a_se_ec4853ae'),

            self::TokenVault => __('console.api_keys_and_tokens_your_apps_and_agents_need_for_other_f9b71876'),

            self::ActivityLog => __('console.every_change_made_in_this_organization_who_did_what_to_1d80a907'),

            self::Settings => __('console.your_organization_s_name_domains_and_defaults_changes_h_8e3220c0'),

            self::Appearance => __('console.the_sign_in_page_your_people_see_is_yours_not_ours_logo_7573e3bf'),

            self::AccountSecurity => __('console.your_own_sign_in_methods_and_active_sessions_a_passkey_2bf79cbb'),

            self::SessionsAndActivity => __('console.every_browser_and_device_signed_in_as_you_the_apps_you_ee516093'),
            self::YourApps => __('console.account_apps_help'),
            self::Preferences => __('console.account_preferences_help'),

            self::ApiKeys => __('console.a_key_lets_your_own_scripts_and_integrations_call_an_ap_4edbc37d'),

            self::MemberApiKeys => __('console.every_api_key_the_people_in_this_organization_have_crea_51e4cae3'),

            self::OrganizationSettings => __('console.the_name_of_your_organization_which_your_projects_envir_fde906b5'),

            self::Projects => __('console.a_project_is_one_of_your_products_with_its_own_plan_sig_45d566d1'),

            self::Team => __('console.everyone_who_administers_this_organization_the_built_in_69802df4'),

            self::Keys => __('console.the_keys_your_own_code_presents_to_cbox_id_one_tab_per__a0955137'),

            self::EnvironmentDomains => __('console.serves_an_environment_s_identity_endpoints_on_a_domain_f6582d38'),

            self::Billing => __('console.each_project_in_your_organization_has_its_own_plan_and__0a007c9c'),

            self::EnvironmentOverview => __('console.how_many_organizations_users_single_sign_on_connections_8e80258f'),

            self::EnvironmentGetStarted => __('console.pick_the_framework_you_are_building_with_and_an_app_is_a82b6a71'),

            self::ReviewAgentRequests => __('console.what_agents_in_this_environment_are_waiting_for_a_perso_8f2ea5c0'),

            self::Agents => __('console.an_agent_is_any_software_that_acts_on_this_environment_05aebde2'),

            self::ConnectAgent => __('console.this_environment_serves_an_mcp_server_the_standard_way_eb9faa22'),

            self::Organizations => __('console.each_organization_is_a_company_or_team_using_your_produ_08cdcb7d'),

            self::Users => __('console.every_person_with_an_identity_in_this_environment_which_f9f26f27'),
            self::Staff => __('console.an_admin_support_role_is_a_role_you_grant_to_your_own_p_4b6ac4cb'),
            self::SupportAccess => __('console.see_an_app_exactly_as_one_of_its_users_does_you_sign_in_8eafae42'),

            self::SignInRules => __('console.the_password_rules_lockout_two_factor_requirement_and_s_6d5c4102'),

            self::SamlApplications => __('console.registers_applications_that_accept_this_environment_as_d381d96e'),

            self::LegacyLogin => __('console.while_you_move_off_another_system_an_app_can_ask_for_th_c182528e'),

            self::Connectors => __('console.every_link_between_cbox_id_and_another_system_in_one_pl_0376dee4'),

            self::LogStreaming => __('console.mirrors_every_audit_log_entry_into_your_security_team_s_b980f702'),
            self::AuditLogs => __('console.the_audit_events_your_app_sends_about_each_organization_a385fa1d'),
            self::FineGrainedAuthorization => __('console.your_app_s_own_access_model_resource_types_like_documen_24105154'),

            self::DataExports => __('console.how_the_audit_trail_leaves_cbox_id_a_scheduled_export_s_3da9deb4'),

            self::RiskEvents => __('console.sign_ins_and_requests_that_cbox_id_scored_as_risky_enou_b09e917e'),
            self::SignInMethods => __('console.every_way_a_person_can_sign_in_here_password_passkeys_m_1abc176e'),
            self::Radar => __('console.radar_judges_every_sign_in_and_sign_up_credential_stuff_96651d15'),

            self::SignInActivity => __('console.sign_ins_tokens_issued_new_users_and_two_factor_enrolme_e9040ee7'),

            self::Branding => __('console.everything_your_people_see_of_your_brand_on_one_page_th_9d98e332'),

            self::AllOrganizations => __('console.an_organization_is_one_signed_up_company_s_home_on_this_b98406a0'),

            self::Environments => __('console.an_environment_is_a_fully_isolated_identity_service_wit_993d6053'),

            self::PlatformOrganizations => __('console.every_organization_in_the_environment_the_console_is_po_0de4eff9'),

            self::PlatformUsage => __('console.organizations_users_and_active_sessions_for_every_envir_b7f36bfd'),

            self::PlatformSearch => __('console.looks_up_an_organization_by_name_or_handle_or_a_user_by_d7460886'),

            self::PlatformQueues => __('console.webhooks_sign_out_notices_to_connected_apps_role_update_776577f9'),
            self::Operators => __('console.platform_operators_administer_every_organization_and_en_36866fb1'),
        };
    }

    /**
     * Path into the repository's `docs/`, without a file extension — or null where
     * no guide has been written. Never point this at a page that does not exist.
     */
    public function docsPath(): ?string
    {
        return match ($this) {
            self::SingleSignOn => 'guides/single-sign-on',
            self::Domains => 'guides/single-sign-on',
            self::SocialSignIn => 'guides/social-sign-in',
            self::SyncUsersIn => 'guides/sync-users-in',
            self::SyncUsersOut => 'guides/sync-users-out',
            self::Roles => 'guides/roles',
            self::Members => 'guides/members',
            self::Permissions => 'guides/permissions',
            self::Apps => 'guides/apps-and-api-keys',
            self::Apis => 'guides/apis',
            self::Webhooks => 'guides/webhooks',
            self::InlineHooks => 'guides/inline-hooks',
            self::AppCatalog => 'guides/app-catalog',
            self::FeatureFlags => 'guides/feature-flags',
            self::TokenVault => 'guides/token-vault',
            self::Pipes => 'guides/pipes',
            self::AccessReviews => 'guides/access-reviews',
            self::RoleConflicts => 'guides/role-conflicts',
            self::ActivityLog => 'guides/activity-log',
            self::AuditLogs => 'guides/audit-logs',
            self::FineGrainedAuthorization => 'guides/fine-grained-authorization',
            self::LogStreaming => 'guides/log-streams',
            self::EnvironmentDomains => 'guides/custom-domains',
            self::AgentApprovals => 'guides/agent-approvals',
            self::TrustedDevices => 'guides/trusted-devices',
            self::ReviewAgentRequests => 'guides/agent-approvals',
            self::Agents,
            self::ConnectAgent => 'guides/agents-and-mcp',
            self::EnvironmentGetStarted => 'getting-started/integrate-your-app',
            self::Keys => 'guides/keys',
            self::ApiKeys,
            self::MemberApiKeys => 'guides/api-keys',
            self::Staff => 'guides/roles',
            self::SupportAccess => 'guides/support-access',
            self::Projects,
            self::AllOrganizations,
            self::Environments,
            self::Organizations,
            self::PlatformOrganizations => 'core-concepts/organizations-and-customers',
            self::DataExports => 'security/compliance',
            self::RiskEvents => 'security/adaptive-risk',
            self::SignInMethods,
            self::SignInRules => 'guides/authentication-policy',
            self::Radar => 'guides/radar',
            self::SignInActivity => 'operations/analytics',
            self::PlatformQueues => 'operations/queue-workers',
            self::Overview,
            self::Usage,
            self::Settings,
            self::Appearance,
            self::AccountSecurity,
            self::SessionsAndActivity,
            self::YourApps,
            self::Preferences,
            self::OrganizationSettings,
            self::Team,
            self::Billing,
            self::EnvironmentOverview,
            self::Users,
            self::SamlApplications,
            self::LegacyLogin,
            self::Connectors,
            self::Branding,
            self::PlatformUsage,
            self::PlatformSearch,
            self::Operators => null,
        };
    }
}
