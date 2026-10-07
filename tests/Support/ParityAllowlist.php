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
    public const int BASELINE = 92;

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
            'devices.mine.destroy',
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
            'environment.impersonate',
            'environment.organizations.api-keys.revoke',
            'environment.organizations.destroy',
            'environment.organizations.domains.capture',
            'environment.organizations.domains.remove',
            'environment.organizations.domains.store',
            'environment.organizations.domains.verify',
            'environment.organizations.invitations.resend',
            'environment.organizations.invitations.revoke',
            'environment.organizations.invitations.store',
            'environment.organizations.members.access',
            'environment.organizations.members.remove',
            'environment.organizations.members.role',
            'environment.organizations.members.store',
            'environment.organizations.members.transfer-ownership',
            'environment.organizations.reactivate',
            'environment.organizations.store',
            'environment.organizations.suspend',
            'environment.organizations.update',
            'environment.permissions.destroy',
            'environment.permissions.store',
            'environment.permissions.update',
            'environment.roles.destroy',
            'environment.roles.permissions',
            'environment.roles.store',
            'environment.roles.update',
            'environment.settings.rename',
            'environment.staff.destroy',
            'environment.staff.store',
            'environment.support-sessions.end',
            'environment.users.deactivate',
            'environment.users.mfa',
            'environment.users.organizations.access',
            'environment.users.organizations.remove',
            'environment.users.organizations.role',
            'environment.users.organizations.store',
            'environment.users.password',
            'environment.users.password-reset',
            'environment.users.reactivate',
            'environment.users.roles',
            'environment.users.sessions.revoke',
            'environment.users.sessions.revoke-all',
            'environment.users.store',
            'environment.users.support-sessions.store',
            'environment.users.update',
            'environment.users.verification',
            'environment.users.verify',
            'permissions.destroy',
            'permissions.store',
            'permissions.update',
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
            'roles.destroy',
            'roles.permissions',
            'roles.store',
            'roles.update',
            'settings.organization.destroy',
            'settings.rename',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::ceremonies(), ...self::adminPortal(), ...self::operatorTooling(), ...self::pending()];
    }
}
