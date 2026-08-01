<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

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
            self::InviteTeam => 'Invite your team',
            self::ConnectApp => 'Connect your first app',
            self::DefineRoles => 'Decide who can do what',
            self::BrandSignIn => 'Make sign-in look like you',
            self::SingleSignOn => 'Sign in with your own provider',
            self::SyncUsersIn => 'Sync users in automatically',
        };
    }

    /** One sentence on why this is worth doing — not what the button does. */
    public function description(): string
    {
        return match ($this) {
            self::InviteTeam => 'Add the people who will administer this organization with you. They set their own sign-in, so you never handle a password.',
            self::ConnectApp => 'Register an app so your people can sign in to it with the account they have here. This is the step that turns Cbox ID into something they use.',
            self::DefineRoles => 'Give people roles instead of blanket access, so every connected app knows who is an editor and who is read-only.',
            self::BrandSignIn => 'Your sign-in page carries your logo and colours. People are typing a password into it — it should look like you, not like us.',
            self::SingleSignOn => 'Connect Entra ID, Okta or Google Workspace and your people sign in with the company account they already have.',
            self::SyncUsersIn => 'Let your provider create and deactivate people here on its own, so a leaver loses access everywhere without a ticket.',
        };
    }

    /** The route the step sends you to. */
    public function route(): string
    {
        return match ($this) {
            self::InviteTeam => 'members',
            self::ConnectApp => 'clients',
            self::DefineRoles => 'roles',
            self::BrandSignIn => 'appearance',
            self::SingleSignOn => 'connections',
            self::SyncUsersIn => 'directories',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::InviteTeam => 'Invite someone',
            self::ConnectApp => 'Register an app',
            self::DefineRoles => 'Set up roles',
            self::BrandSignIn => 'Open the theme editor',
            self::SingleSignOn => 'Connect a provider',
            self::SyncUsersIn => 'Connect a directory',
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
