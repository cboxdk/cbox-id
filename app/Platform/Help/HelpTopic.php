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
    case SocialSignIn = 'social-sign-in';
    case SyncUsersIn = 'sync-users-in';
    case SyncUsersOut = 'sync-users-out';
    case AccessReviews = 'access-reviews';
    case RoleConflicts = 'role-conflicts';
    case Apps = 'apps';
    case Webhooks = 'webhooks';
    case InlineHooks = 'inline-hooks';
    case TokenVault = 'token-vault';
    case ActivityLog = 'activity-log';
    case Settings = 'settings';
    case Appearance = 'appearance';
    case AccountSecurity = 'account-security';

    /** The popover heading — the concept's name, not the page's. */
    public function title(): string
    {
        return match ($this) {
            self::Overview => 'Your organization at a glance',
            self::Usage => 'What counts as usage',
            self::AgentApprovals => 'Approving on someone else\'s screen',
            self::TrustedDevices => 'Your phone as the key',
            self::Members => 'Members and invitations',
            self::Roles => 'What roles do',
            self::Permissions => 'What a role is made of',
            self::SingleSignOn => 'Signing in with your own identity provider',
            self::SocialSignIn => 'Signing in with an account people already have',
            self::SyncUsersIn => 'Keeping people up to date automatically',
            self::SyncUsersOut => 'Pushing people out to your other apps',
            self::AccessReviews => 'Certifying who still needs access',
            self::RoleConflicts => 'Roles that must not be combined',
            self::Apps => 'Connecting an app to Cbox ID',
            self::Webhooks => 'Getting told when something happens',
            self::InlineHooks => 'Having a say while it happens',
            self::TokenVault => 'Credentials your apps use elsewhere',
            self::ActivityLog => 'The record of what changed',
            self::Settings => 'Organization settings',
            self::Appearance => 'Your branded sign-in page',
            self::AccountSecurity => 'Protecting your own sign-in',
        };
    }

    /** Two or three sentences: what it is, and when you need it. */
    public function summary(): string
    {
        return match ($this) {
            self::Overview => 'Everything your organization has set up, and what is left to do. The setup checklist tracks real state — a step ticks itself off the moment it is genuinely done.',

            self::Usage => 'How many people signed in, and how often your apps called the API, over the last 30 days. This is here so you can see load and spot anomalies; it is not an invoice.',

            self::TrustedDevices => 'A phone with the authenticator app installed becomes the thing that answers approval requests and tells you when someone signs in as you. Enrol it once by scanning the code; remove it here the moment you lose the handset, which stops it approving anything.',
            self::AgentApprovals => 'When an app or an AI agent needs your go-ahead to act as you, it cannot always ask on the screen in front of you — so it asks here instead. Approve only requests you started yourself, and check that the code shown matches the one on the device that asked.',

            self::Members => 'Everyone who can sign in to this organization, and the invitations you have sent that nobody has accepted yet. Invite people by email; they set up their own sign-in, so you never handle anyone\'s password.',

            self::Roles => 'A role is a job title your apps understand — "Editor", "Support agent". You decide who holds which role here, and each app decides for itself what its roles are allowed to do. Roles travel with the person into every connected app, so you grant and revoke access in one place.',

            self::Permissions => 'A permission is one thing a role is allowed to do — "create invoices", "read reports". You can write your own here, without any code, and then compose them into roles. Apps can also register theirs automatically, so the list stays in step with what the app actually enforces. Permissions you write belong to you; the ones your environment shares are yours to use but not to change.',

            self::SingleSignOn => 'Lets your people sign in with the company account they already have — Microsoft Entra ID, Okta, Google Workspace — instead of a separate password here. You connect your identity provider once and claim your email domains; everyone on those domains is then sent to your provider to sign in.',

            self::SocialSignIn => 'Offers Google, GitHub, Apple and others as buttons on your sign-in page, for people who would rather use an account they already have than create another password. You supply the credentials from your own account with each provider; everything else — endpoints, scopes, what to read from the response — is filled in for you. An address a provider sends is never enough on its own to reach an existing account here.',

            self::SyncUsersIn => 'Your identity provider creates, updates and deactivates people here on its own, over a standard called SCIM. Someone joining or leaving in your HR system reaches your apps within seconds, with no ticket and no leftover accounts — which is the part that matters when someone leaves.',

            self::SyncUsersOut => 'The mirror image: Cbox ID pushes your people into the other SaaS products your company uses, over their SCIM endpoints. One place to onboard and offboard, instead of one admin panel per vendor.',

            self::AccessReviews => 'A round where you go through who holds which role and confirm they still need it. Access piles up quietly — people change teams and keep old permissions — and a review is how you clear it out. What you revoke is applied when you close the review, and the whole round is recorded for your auditor.',

            self::RoleConflicts => 'Some pairs of roles must never sit with the same person — whoever raises a payment should not also approve it. Declare those pairs here and Cbox ID both blocks new grants that would break the rule and shows you who already holds a conflicting pair.',

            self::Apps => 'Every app that signs people in through Cbox ID, or calls its API, is registered here and gets its own credentials. Register one per app and per environment — never share credentials between them, so you can revoke one without taking the others down.',

            self::Webhooks => 'Cbox ID posts a signed message to your endpoint after something happens — a member joined, a role changed — so your systems can react without polling. Delivery is retried, and it is after the fact: your endpoint is told, it does not get a vote.',

            self::InlineHooks => 'These run in the middle of an operation, not after it, and their answer changes the outcome: your endpoint can add information to a token, or refuse a sign-in outright. Powerful, and directly in the critical path — a slow or broken endpoint is felt by the person trying to sign in.',

            self::TokenVault => 'API keys and tokens your apps and agents need for other services, kept encrypted here rather than in each app\'s config. You hand a secret in once, grant specific apps the right to use it, and it is never displayed again — rotate it if you lose it.',

            self::ActivityLog => 'Every change made in this organization: who did what, to what, and when. Entries are hash-chained, so a removed or edited entry breaks the chain and shows up. Read-only, on purpose — this is the record you hand an auditor.',

            self::Settings => 'Your organization\'s name, domains and defaults. Changes here apply to everyone who signs in through this organization.',

            self::Appearance => 'The sign-in page your people see is yours, not ours: logo, colours and wording. Changes preview live and apply to this organization\'s hosted sign-in.',

            self::AccountSecurity => 'Your own sign-in methods and active sessions. A passkey is the strongest option and the quickest to use — your device unlocks it with a fingerprint or face, and there is no password left to phish.',
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
            self::SocialSignIn => 'guides/social-sign-in',
            self::SyncUsersIn => 'guides/sync-users-in',
            self::SyncUsersOut => 'guides/sync-users-out',
            self::Roles => 'guides/roles',
            self::Permissions => 'guides/permissions',
            self::Apps => 'guides/apps-and-api-keys',
            self::Webhooks => 'guides/webhooks',
            self::InlineHooks => 'guides/inline-hooks',
            self::TokenVault => 'guides/token-vault',
            self::AccessReviews => 'guides/access-reviews',
            self::RoleConflicts => 'guides/role-conflicts',
            self::ActivityLog => 'guides/activity-log',
            self::AgentApprovals => 'guides/agent-approvals',
            self::TrustedDevices => 'guides/trusted-devices',
            self::Overview,
            self::Usage,
            self::Members,
            self::Settings,
            self::Appearance,
            self::AccountSecurity => null,
        };
    }
}
