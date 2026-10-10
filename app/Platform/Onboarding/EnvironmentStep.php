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

    public function title(): string
    {
        return match ($this) {
            self::CreateApp => 'Create your first app',
            self::AddRedirect => 'Tell it where to send people back',
            self::FirstSignIn => 'Sign somebody in',
            self::BrandSignIn => 'Make the sign-in page yours',
            self::CreateOrganization => 'Add your first organization',
            self::ConnectSso => 'Connect an organization\'s single sign-on',
            self::ConnectAgent => 'Connect an AI agent',
            self::InviteTeammate => 'Invite a teammate',
            self::GoLive => 'Go live',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateApp => 'Pick your framework and get an app with a localhost redirect, the .env block and the install command — about a minute.',
            self::AddRedirect => 'An app signs people in only to the URLs it lists, character for character. Ticks once one of your apps has one.',
            self::FirstSignIn => 'Run your app and sign in. Ticks the moment anybody signs in to one of your apps.',
            self::BrandSignIn => 'Your colours and logo on the hosted sign-in page, checked for contrast in light and dark.',
            self::CreateOrganization => 'An organization is one of the teams that use your product: its members, roles and authentication policy, kept apart from everyone else\'s.',
            self::ConnectSso => 'Let an organization sign in with Okta, Entra ID or Google Workspace — or send its admin an Admin Portal link to do it themselves.',
            self::ConnectAgent => 'Point Claude Code, Cursor or your own bot at this environment\'s MCP server. Ticks once an agent has made its first call.',
            self::InviteTeammate => 'Somebody else who can open this console when you are away.',
            self::GoLive => 'Ticks once an app sends people back to a real https address rather than localhost.',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::CreateApp => 'Start the quickstart',
            self::AddRedirect => 'Open apps',
            self::FirstSignIn => 'Wait for a sign-in',
            self::BrandSignIn => 'Edit appearance',
            self::CreateOrganization => 'New organization',
            self::ConnectSso => 'Enterprise SSO',
            self::ConnectAgent => 'Connect an agent',
            self::InviteTeammate => 'Invite',
            self::GoLive => 'Open apps',
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
