<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

/**
 * One step of an environment's "Get started", in the order a developer meets them: an app,
 * somewhere for it to send people back to, somebody actually signing in, and then the
 * things that make it a product — a brand, organizations, their SSO, an agent, a teammate, and
 * production.
 *
 * {@see EnvironmentChecklist} decides whether each is done, from the environment's own
 * state. Nothing here is ever ticked by hand.
 */
enum EnvironmentStep: string
{
    case CreateApp = 'create_app';
    case AddRedirect = 'add_redirect';
    case FirstSignIn = 'first_sign_in';
    case BrandSignIn = 'brand_sign_in';
    case CreateOrganization = 'create_organization';
    case ConnectSso = 'connect_sso';
    case ConnectAgent = 'connect_agent';
    case InviteTeammate = 'invite_teammate';
    case GoLive = 'go_live';

    public function isOptional(): bool
    {
        return ! in_array($this, [self::CreateApp, self::AddRedirect, self::FirstSignIn], true);
    }

    public function title(): string
    {
        return match ($this) {
            self::CreateApp => __('console.create_your_first_app_1377df53'),
            self::AddRedirect => __('console.tell_it_where_to_send_people_back_7b1f3fef'),
            self::FirstSignIn => __('console.sign_somebody_in_597221a8'),
            self::BrandSignIn => __('console.make_the_sign_in_page_yours_78f62434'),
            self::CreateOrganization => __('console.add_your_first_organization_7985d69a'),
            self::ConnectSso => __('console.connect_an_organization_s_single_sign_on_d28ff82e'),
            self::ConnectAgent => __('console.connect_an_ai_agent_d8f6c537'),
            self::InviteTeammate => __('console.invite_a_teammate_3e14c447'),
            self::GoLive => __('console.go_live_a9f69016'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateApp => __('console.pick_your_framework_and_get_an_app_with_a_lo_70977d3d'),
            self::AddRedirect => __('console.an_app_signs_people_in_only_to_the_urls_it_l_a7f88d2a'),
            self::FirstSignIn => __('console.run_your_app_and_sign_in_ticks_the_moment_an_018461b4'),
            self::BrandSignIn => __('console.your_colours_and_logo_on_the_hosted_sign_in__54995b02'),
            self::CreateOrganization => __('console.an_organization_is_one_of_the_teams_that_use_509fd742'),
            self::ConnectSso => __('console.let_an_organization_sign_in_with_okta_entra__cf114dfb'),
            self::ConnectAgent => __('console.point_claude_code_cursor_or_your_own_bot_at__7beb8319'),
            self::InviteTeammate => __('console.somebody_else_who_can_open_this_console_when_784e0dd9'),
            self::GoLive => __('console.ticks_once_an_app_sends_people_back_to_a_rea_248c1799'),
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::CreateApp => __('console.start_the_quickstart_4e27ce76'),
            self::AddRedirect => __('console.open_apps_1ec82e2c'),
            self::FirstSignIn => __('console.wait_for_a_sign_in_5b9c04cb'),
            self::BrandSignIn => __('console.edit_appearance_a3ac8251'),
            self::CreateOrganization => __('console.new_organization_62e34b70'),
            self::ConnectSso => __('console.enterprise_sso_0f8d7e8e'),
            self::ConnectAgent => __('console.connect_an_agent_ce504be3'),
            self::InviteTeammate => __('console.invite_1fd9ae16'),
            self::GoLive => __('console.open_apps_1ec82e2c'),
        };
    }

    /** The console route the step's button opens; null for one reached on another host. */
    public function route(): ?string
    {
        return match ($this) {
            self::CreateApp, self::FirstSignIn => 'environment.get-started',
            self::AddRedirect, self::GoLive => 'environment.clients',
            self::BrandSignIn => 'environment.branding',
            self::CreateOrganization => 'environment.organizations.create',
            self::ConnectSso => 'environment.connections',
            self::ConnectAgent => 'environment.agent-connect',
            self::InviteTeammate => null,
        };
    }
}
