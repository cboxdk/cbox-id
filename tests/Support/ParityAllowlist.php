<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Feature\Actions\ActionParityTest;

/**
 * Console writes that are not (yet) an action: the debt the parity test holds to a number
 * that only goes down ({@see ActionParityTest}).
 *
 * Kept in kinds, because only one of them is debt:
 *
 *  - CEREMONIES a person performs in a browser: signing in, a second factor, consenting,
 *    accepting an invitation, confirming a password, choosing which console to look at.
 *    They are UI by nature; an API for them would be an API for impersonating the person.
 *  - The ADMIN PORTAL's own steps, taken by a customer's IT admin holding a one-time link.
 *    What a machine needs there is the LINK, which is an action of its own.
 *  - OPERATOR TOOLING from a vendor package (the queue monitor's dashboard).
 *  - PENDING: real management writes with no action yet. Every one is something an agent
 *    cannot do. Moving one into an action removes it from this list.
 */
final class ParityAllowlist
{
    /** The most PENDING entries there may be. Lower it whenever an area becomes actions. */
    public const int BASELINE = 104;

    /** @return list<string> */
    public static function ceremonies(): array
    {
        return [
            'account.mfa.confirm',
            'account.mfa.enrol',
            'account.mfa.recovery-codes',
            'account.password.update',
            'accounts.switch',
            'admin.logout',
            'dashboard.checklist.dismiss',
            'device.approve',
            'device.deny',
            'device.lookup',
            'environment.acting-organization.choose',
            'environment.acting-organization.clear',
            'environment.sudo.confirm',
            // An environment administrator stepping into a person's own BROWSER session — the
            // session is swapped under this browser, and a REST call has no browser to swap.
            // An agent that must act as a customer's user starts a support session
            // (`support_sessions.start`) for a named staff member instead.
            'environment.impersonate',
            // The console's "sign in to <app> as <user>" hands THIS browser to the app with a
            // one-time handoff only the same administrator's browser can redeem. The machine
            // twin is `support_sessions.start`, which names a staff member as the actor.
            'environment.users.support-sessions.store',
            'first-run.claim',
            'frontend.sign-in',
            'frontend.sign-in.factor',
            'frontend.sign-in.passkey',
            'frontend.sign-in.passkey.options',
            'get-started.dismiss',
            'impersonation.exit',
            'invitation.accept.store',
            'link.connect',
            'link.decline',
            // The hosted pages' language picker: a cookie for the next render, nothing more.
            'locale.update',
            'login.attempt',
            'login.identify',
            'login.magic-link',
            'login.step-up.resend',
            'login.step-up.verify',
            'logout',
            'magic.redeem.store',
            'mfa.recover',
            'mfa.verify',
            'oauth.authorize.approve',
            'oauth.authorize.deny',
            'oauth.authorize.organization.choose',
            'oauth.authorize.organization.store',
            'organization.invite.accept.store',
            'organization.switch',
            'passkeys.login',
            'passkeys.login.options',
            'passkeys.register',
            'passkeys.register.options',
            'password.change.update',
            'password.email',
            'password.update',
            'platform.customers.open',
            'platform.customers.target',
            'platform.environment.switch',
            'portal.enter.store',
            'portal.finish',
            // An OWNER closing their own organization from inside it, behind a fresh password
            // and the organization's name typed out, then landed somewhere they still belong.
            // The environment's authority archives one with `organizations.delete`.
            'settings.organization.destroy',
            'signup.register',
            'sso.saml.acs',
            'sudo.confirm',
            'verification.verify.store',
        ];
    }

    /** @return list<string> */
    public static function adminPortal(): array
    {
        return [
            'portal.connections.activate',
            'portal.connections.store',
            'portal.directories.store',
            'portal.domains.destroy',
            'portal.domains.store',
            'portal.domains.verify',
        ];
    }

    /** @return list<string> */
    public static function operatorTooling(): array
    {
        return [
            'queue-monitor.dashboard.batch.delete',
            'queue-monitor.dashboard.batch.replay',
            'queue-monitor.dashboard.job.destroy',
            'queue-monitor.dashboard.job.replay',
            'queue-monitor.dashboard.stuck-jobs.resolve',
            'queue-monitor.dashboard.stuck-jobs.resolve-all',
        ];
    }

    /** @return list<string> */
    public static function pending(): array
    {
        return [
            'account.api-keys.revoke',
            'account.api-keys.store',
            'account.applications.destroy',
            'account.passkeys.destroy',
            'account.profile.update',
            'account.sessions.revoke',
            'account.sessions.revoke-others',
            'account.social.destroy',
            'approvals.approve',
            'approvals.deny',
            'compliance.data-exports.download',
            'connections.activate',
            'connections.destroy',
            'connections.disable',
            'connections.domains.capture',
            'connections.domains.destroy',
            'connections.domains.store',
            'connections.domains.verify',
            'connections.import',
            'connections.invite',
            'connections.require-sso',
            'connections.store',
            'connections.update',
            'devices.mine.destroy',
            'directories.connect',
            'directories.destroy',
            'directories.invite',
            'directories.map',
            'directories.rotate',
            'directories.store',
            'directories.toggle',
            'directories.update',
            'directory.api-keys.revoke',
            'directory.members.access',
            'directory.members.invitations.resend',
            'directory.members.invitations.revoke',
            'directory.members.invite',
            'directory.members.leave',
            'directory.members.remove',
            'directory.members.role',
            'directory.members.transfer-ownership',
            'environment-domains.destroy',
            'environment-domains.store',
            'environment-domains.verify',
            'environment.approvals.deny',
            'environment.compliance.data-exports.download',
            'environment.connections.activate',
            'environment.connections.destroy',
            'environment.connections.disable',
            'environment.connections.domains.capture',
            'environment.connections.domains.destroy',
            'environment.connections.domains.store',
            'environment.connections.domains.verify',
            'environment.connections.import',
            'environment.connections.invite',
            'environment.connections.require-sso',
            'environment.connections.store',
            'environment.connections.update',
            'environment.directories.connect',
            'environment.directories.destroy',
            'environment.directories.invite',
            'environment.directories.map',
            'environment.directories.rotate',
            'environment.directories.store',
            'environment.directories.toggle',
            'environment.directories.update',
            'environment.governance.close',
            'environment.governance.item',
            'environment.governance.store',
            'environment.provisioning.destroy',
            'environment.provisioning.store',
            'environment.provisioning.toggle',
            'environment.sod-policies.destroy',
            'environment.sod-policies.store',
            'environment.sod-policies.toggle',
            'environment.vault.grants.destroy',
            'environment.vault.grants.store',
            'environment.vault.revoke',
            'environment.vault.rotate',
            'environment.vault.store',
            'governance.close',
            'governance.item',
            'governance.store',
            'platform.customers.store',
            'platform.customers.toggle',
            'platform.environments.provision',
            'platform.environments.store',
            'platform.impersonate',
            'platform.operators.store',
            'platform.operators.toggle',
            'platform.organizations.reparent',
            'platform.organizations.store',
            'platform.organizations.toggle',
            'provisioning.destroy',
            'provisioning.store',
            'provisioning.toggle',
            'sod-policies.destroy',
            'sod-policies.store',
            'sod-policies.toggle',
            'vault.grants.destroy',
            'vault.grants.store',
            'vault.revoke',
            'vault.rotate',
            'vault.store',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::ceremonies(), ...self::adminPortal(), ...self::operatorTooling(), ...self::pending()];
    }
}
