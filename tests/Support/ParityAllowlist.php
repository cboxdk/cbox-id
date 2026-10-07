<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Feature\Actions\ActionParityTest;

/**
 * Console writes that are not an action: the deliberate UI-only ones, each with the reason
 * it stays one, and the debt the parity test holds to a number that only goes down
 * ({@see ActionParityTest}).
 *
 * Kept in kinds, because only the last is debt:
 *
 *  - CEREMONIES a person performs in a browser: signing in, a second factor, consenting,
 *    accepting an invitation, confirming a password, registering a passkey. They are UI by
 *    nature; an API for them would be an API for impersonating the person.
 *  - The ADMIN PORTAL's own steps, taken by a customer's IT admin holding a one-time link.
 *    What a machine needs there is the LINK, which is an action of its own.
 *  - FILE DOWNLOADS: a POST that streams a file to the browser. The data behind it is a
 *    read; the request shape is the browser's.
 *  - UI PREFERENCES: what the console remembers about how a person looks at it — a
 *    dismissed checklist, the console or organization they are viewing. Nothing changes
 *    for anyone else.
 *  - VENDOR UI from a package (the queue monitor's dashboard), with its own routes.
 *  - PENDING: real management writes with no action yet. Every one is something an agent
 *    cannot do. Moving one into an action removes it from this list.
 */
final class ParityAllowlist
{
    /** The most PENDING entries there may be. Lower it whenever an area becomes actions. */
    public const int BASELINE = 132;

    /** @return list<string> */
    public static function ceremonies(): array
    {
        return [
            // Turning on a second factor: the authenticator itself has to take part.
            'account.mfa.confirm',
            'account.mfa.enrol',
            // New recovery codes are shown once, to the person, behind a fresh password.
            'account.mfa.recovery-codes',
            // A password is only ever typed by its owner.
            'account.password.update',
            // The person's own answer to an agent asking to act as them (CIBA): approving IS
            // their consent, and the redeemed token is minted for them — so only they give it,
            // on their device or this page. An administrator's denial is `approvals.deny`.
            'approvals.approve',
            'approvals.deny',
            // Signing out of the environment console: ends the browser's session.
            'admin.logout',
            // The device-authorization flow (RFC 8628): the person types the code they were shown.
            'device.approve',
            'device.deny',
            'device.lookup',
            // A fresh password before a sensitive change, on either console.
            'environment.sudo.confirm',
            'sudo.confirm',
            // Stepping into somebody's session for support, and back out: a browser session by
            // definition, never a credential an API could hand out.
            'environment.impersonate',
            'impersonation.exit',
            'platform.impersonate',
            // The first sign-in of a fresh install claims it as its operator.
            'first-run.claim',
            // The hosted sign-in for frontend apps: a person proving who they are.
            'frontend.sign-in',
            'frontend.sign-in.factor',
            'frontend.sign-in.passkey',
            'frontend.sign-in.passkey.options',
            // Accepting an invitation is the invitee's own act, proved by the link they hold.
            'invitation.accept.store',
            'organization.invite.accept.store',
            // Linking a social identity that matched an existing account: the person confirms.
            'link.connect',
            'link.decline',
            // Signing in, and every step of it.
            'login.attempt',
            'login.identify',
            'login.magic-link',
            'login.step-up.resend',
            'login.step-up.verify',
            'logout',
            'magic.redeem.store',
            'mfa.recover',
            'mfa.verify',
            'passkeys.login',
            'passkeys.login.options',
            'sso.saml.acs',
            // OAuth consent: the person granting an app access to themselves.
            'oauth.authorize.approve',
            'oauth.authorize.deny',
            'oauth.authorize.organization.choose',
            'oauth.authorize.organization.store',
            // Registering a passkey: the authenticator signs a challenge in the browser.
            'passkeys.register',
            'passkeys.register.options',
            // A forced password change, forgot-password and reset: typed by the owner.
            'password.change.update',
            'password.email',
            'password.update',
            // The customer's IT admin entering and finishing the hosted admin portal.
            'portal.enter.store',
            'portal.finish',
            // Creating an account for yourself.
            'signup.register',
            // Proving an email address is yours, with the link sent to it.
            'verification.verify.store',
        ];
    }

    /** @return list<string> */
    public static function adminPortal(): array
    {
        return [
            // The portal's hosted steps, under a one-time link; the link is an action.
            'portal.connections.activate',
            'portal.connections.store',
            'portal.directories.store',
            'portal.domains.destroy',
            'portal.domains.store',
            'portal.domains.verify',
        ];
    }

    /** @return list<string> */
    public static function fileDownloads(): array
    {
        return [
            // A subject's data export (GDPR art. 15/20), streamed to the browser as a JSON
            // file and recorded as `compliance.subject_export`.
            'compliance.data-exports.download',
            'environment.compliance.data-exports.download',
        ];
    }

    /** @return list<string> */
    public static function uiPreferences(): array
    {
        return [
            // Which signed-in account this browser shows.
            'accounts.switch',
            // Hiding the dashboard's setup checklist, and the guided first run.
            'dashboard.checklist.dismiss',
            'get-started.dismiss',
            // Which organization the environment console is looking at.
            'environment.acting-organization.choose',
            'environment.acting-organization.clear',
            // The hosted pages' language picker: a cookie for the next render, nothing more.
            'locale.update',
            // Which organization the console is looking at.
            'organization.switch',
            // Which environment the operator's console is pointed at — from the switcher, or
            // from a workspace's own page. The operator API names its environment instead.
            'platform.environment.switch',
            'platform.workspaces.open',
            'platform.workspaces.target',
        ];
    }

    /** @return list<string> */
    public static function vendorUi(): array
    {
        return [
            // cboxdk/laravel-queue-monitor's own dashboard, behind the operator gate.
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
            'environment.provisioning.destroy',
            'environment.provisioning.store',
            'environment.provisioning.toggle',
            'environment.roles.destroy',
            'environment.roles.permissions',
            'environment.roles.store',
            'environment.roles.update',
            'environment.settings.rename',
            'environment.sod-policies.destroy',
            'environment.sod-policies.store',
            'environment.sod-policies.toggle',
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
            'environment.vault.grants.destroy',
            'environment.vault.grants.store',
            'environment.vault.revoke',
            'environment.vault.rotate',
            'environment.vault.store',
            'governance.close',
            'governance.item',
            'governance.store',
            'permissions.destroy',
            'permissions.store',
            'permissions.update',
            'provisioning.destroy',
            'provisioning.store',
            'provisioning.toggle',
            'roles.destroy',
            'roles.permissions',
            'roles.store',
            'roles.update',
            'settings.organization.destroy',
            'settings.rename',
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
        return [...self::ceremonies(), ...self::adminPortal(), ...self::fileDownloads(), ...self::uiPreferences(), ...self::vendorUi(), ...self::pending()];
    }
}
