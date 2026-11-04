<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Platform\Console\ConsoleScope;
use App\Platform\Help\HelpTopic;

/**
 * The things an organization has to do before Cbox ID is actually doing its job,
 * in the order they make sense.
 *
 * Each case owns its own copy and its own destination; whether it is DONE is
 * measured against live state by {@see SetupChecklist}, never stored. That is the
 * whole point: the console previously shipped a checklist with a hardcoded tick on
 * "Organization created" and three items that could not tick at all, so it said the
 * same thing on day one and day four hundred. A checklist that cannot complete is
 * worse than none — it teaches people to ignore it.
 */
enum SetupStepKey: string
{
    case InviteTeam = 'invite-team';
    case ConnectApp = 'connect-app';
    case DefineRoles = 'define-roles';
    case BrandSignIn = 'brand-sign-in';
    case SingleSignOn = 'single-sign-on';
    case SyncUsersIn = 'sync-users-in';

    public function title(): string
    {
        return match ($this) {
            self::InviteTeam => __('console.invite_your_team_8caa939a'),
            self::ConnectApp => __('console.connect_your_first_app_c9a9c5a8'),
            self::DefineRoles => __('console.decide_who_can_do_what_c03831fa'),
            self::BrandSignIn => __('console.make_sign_in_look_like_you_ffb6283c'),
            self::SingleSignOn => __('console.sign_in_with_your_own_provider_34fe54a3'),
            self::SyncUsersIn => __('console.keep_your_people_in_sync_automatically_dc19fdd0'),
        };
    }

    /** One sentence on why this is worth doing — not what the button does. */
    public function description(): string
    {
        return match ($this) {
            self::InviteTeam => __('console.add_the_people_who_will_administer_this_orga_75edc5a6'),
            self::ConnectApp => __('console.register_an_app_so_your_people_can_sign_in_t_9fcc62a6'),
            self::DefineRoles => __('console.give_people_roles_instead_of_blanket_access__8788f6f3'),
            self::BrandSignIn => __('console.your_sign_in_page_carries_your_logo_and_colo_76d3ce22'),
            self::SingleSignOn => __('console.connect_entra_id_okta_or_google_workspace_an_688a5abf'),
            self::SyncUsersIn => __('console.let_your_provider_create_and_deactivate_peop_7db65233'),
        };
    }

    /**
     * The route the step sends you to.
     *
     * `$peopleRoute` is where THIS organization's people are listed — a customer's
     * administrators and everybody else's members are different pages (see
     * {@see ConsoleScope::peopleRoute()}). It was hard-coded to the
     * customer's, so the first step of every tenant organization's setup guide bounced its
     * admin through two redirects to the dashboard.
     */
    public function route(string $peopleRoute = 'directory.members'): string
    {
        return match ($this) {
            self::InviteTeam => $peopleRoute,
            self::ConnectApp => 'clients',
            self::DefineRoles => 'roles',
            self::BrandSignIn => 'branding',
            self::SingleSignOn => 'connections',
            self::SyncUsersIn => 'directories',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::InviteTeam => __('console.invite_someone_9b7639e8'),
            self::ConnectApp => __('console.register_an_app_26c880f0'),
            self::DefineRoles => __('console.set_up_roles_2a23b2ed'),
            self::BrandSignIn => __('console.open_the_theme_editor_46105544'),
            self::SingleSignOn => __('console.connect_a_provider_a002b3f3'),
            self::SyncUsersIn => __('console.connect_a_directory_a1e72908'),
        };
    }

    public function helpTopic(): HelpTopic
    {
        return match ($this) {
            self::InviteTeam => HelpTopic::Members,
            self::ConnectApp => HelpTopic::Apps,
            self::DefineRoles => HelpTopic::Roles,
            self::BrandSignIn => HelpTopic::Appearance,
            self::SingleSignOn => HelpTopic::SingleSignOn,
            self::SyncUsersIn => HelpTopic::SyncUsersIn,
        };
    }

    /**
     * The entitlement this step needs, if any. A step the organization cannot
     * perform is left out of its checklist entirely rather than shown as a
     * permanently unticked box — the list has to be completable to mean anything.
     */
    public function requiresFeature(): ?string
    {
        return match ($this) {
            self::SingleSignOn => 'sso',
            self::SyncUsersIn => 'scim',
            default => null,
        };
    }
}
