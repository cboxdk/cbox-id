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
    case SessionsAndActivity = 'sessions-and-activity';
    case WorkspaceSettings = 'workspace-settings';
    case Projects = 'projects';
    case Team = 'team';
    case Keys = 'keys';
    case EnvironmentDomains = 'environment-domains';
    case Billing = 'billing';
    case EnvironmentOverview = 'environment-overview';
    case ReviewAgentRequests = 'review-agent-requests';
    case Organizations = 'organizations';
    case Users = 'users';
    case SignInRules = 'sign-in-rules';
    case SamlApplications = 'saml-applications';
    case LegacyLogin = 'legacy-login';
    case Connectors = 'connectors';
    case LogStreaming = 'log-streaming';
    case DataExports = 'data-exports';
    case RiskEvents = 'risk-events';
    case SignInActivity = 'sign-in-activity';
    case Branding = 'branding';
    case Workspaces = 'workspaces';
    case Environments = 'environments';
    case PlatformOrganizations = 'platform-organizations';
    case PlatformUsage = 'platform-usage';
    case PlatformSearch = 'platform-search';
    case Operators = 'operators';

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
            self::SessionsAndActivity => 'Where you are signed in',
            self::WorkspaceSettings => 'Workspace settings',
            self::Projects => 'Your products and their environments',
            self::Team => 'The people who run this workspace',
            self::Keys => 'Keys for your own code',
            self::EnvironmentDomains => 'Sign-in on your own domain',
            self::Billing => 'Plans and what they count',
            self::EnvironmentOverview => 'This environment at a glance',
            self::ReviewAgentRequests => 'Agent requests across the environment',
            self::Organizations => 'The teams using your product',
            self::Users => 'Everyone who can sign in here',
            self::SignInRules => 'The rules every sign-in has to meet',
            self::SamlApplications => 'Applications that trust this environment',
            self::LegacyLogin => 'Signing in through your old system',
            self::Connectors => 'Every connection, in one list',
            self::LogStreaming => 'Sending the activity log to your own tools',
            self::DataExports => 'Exports & retention',
            self::RiskEvents => 'Sign-ins that looked suspicious',
            self::SignInActivity => 'Sign-ins over time',
            self::Branding => 'Your look on the console and sign-in',
            self::Workspaces => 'Every workspace on this install',
            self::Environments => 'Every environment on this install',
            self::PlatformOrganizations => 'Organizations in the target environment',
            self::PlatformUsage => 'Usage across the install',
            self::PlatformSearch => 'Finding someone in any environment',
            self::Operators => 'The people who run this install',
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

            self::SessionsAndActivity => 'Every browser and device signed in as you, the apps you have allowed to act for you, and your recent sign-ins and security changes. If something here is not yours, sign it out or withdraw the app, then change your password.',

            self::WorkspaceSettings => 'The name of your workspace, which your projects, environments and team sit under and which the console shows everywhere. Deleting a workspace takes down every environment people sign in through, so it is a request to support rather than a button.',

            self::Projects => 'A project is one of your products, with its own plan, sign-in and environments such as production and staging. Open an environment from here to administer it, and create a new project when you launch a product that should be billed and run on its own.',

            self::Team => 'Everyone who administers this workspace, the built-in role each one holds (Owner, Admin, Developer, Member or Viewer) and which environments they can reach. Invite people by email, and give each person only the environments their job needs.',

            self::Keys => 'The keys your own code presents to Cbox ID, one tab per kind. A management key lets your backend run one environment\'s organizations, members, invitations, roles and apps, a workspace key calls the workspace API with a built-in role, and a frontend key goes into a browser app and works only from the origins you allow. Management and workspace keys are shown once, so copy them when you create them, and revoke one the moment it leaks.',

            self::EnvironmentDomains => 'Serves an environment\'s identity endpoints on a domain you own, such as login.example.com, instead of ours. You prove the domain is yours with a DNS TXT record; set one up when your people should only ever see your own address while they sign in.',

            self::Billing => 'Each project in your workspace has its own plan and environment allowance, shown here beside this month\'s usage: organizations, billed single sign-on connections and sign-ins. Pricing counts monthly active users and enterprise connections such as single sign-on and SCIM, and sandbox environments carry no connection charge.',

            self::EnvironmentOverview => 'How many organizations, users, single sign-on connections, apps and user syncs this environment holds, each linking to its own page. The shortcuts underneath start the things people most often come here to create: an organization, a user, a single sign-on connection or an app.',

            self::ReviewAgentRequests => 'Every pending request in this environment from an app or AI agent asking to act on a user\'s behalf. Each user approves their own, so there is no approve button here; deny a request when it looks like abuse, and the denial is recorded in the activity log.',

            self::Organizations => 'Each organization is a company or team using your product, with its own members, roles, domains and single sign-on. Create one for each company that signs up, and open it to manage its members, invitations and verified domains, or to suspend it.',

            self::Users => 'Every person with an identity in this environment, whichever organizations they belong to. Open one to reset their password or two-factor, sign out their sessions, deactivate them, or change which organizations and roles they hold.',

            self::SignInRules => 'The password rules, lockout, two-factor requirement and single sign-on requirement that apply whenever someone signs in. The environment sets a baseline every organization inherits, and an organization can make its own rules stricter but never looser.',

            self::SamlApplications => 'Registers applications that accept this environment as their SAML identity provider, so their users sign in with the accounts they already have here. This is the outbound direction; to let people arrive with a company account they hold elsewhere, use Single sign-on under Sign-in.',

            self::LegacyLogin => 'While you move off another system, an app can ask for the email and password of anyone not yet in Cbox ID to be checked against its old login endpoint. Test the endpoint before you approve it: a person it accepts is created here and never sent there again, but while it is down, nobody who has not moved yet can sign in.',

            self::Connectors => 'An overview of the links between Cbox ID and other systems: syncing users out over SCIM, webhooks, and single sign-on federation. The catalog lists the kinds this install supports and Connections lists the ones that are live; each is set up on its own page.',

            self::LogStreaming => 'Mirrors every activity log entry into your security team\'s tools, such as a SIEM, as it is written. Set one up so an investigation starts in the tools your team already uses rather than with a request for an export; delivery is at least once, so expect the occasional duplicate.',

            self::DataExports => 'How the audit trail leaves Cbox ID: a scheduled export ships new entries to your SIEM or archive every five minutes, and a daily retention job checkpoints the trail without deleting anything. Come here to pull one person\'s audit history for a GDPR access request, or, in an environment console, to check the exports are running.',

            self::RiskEvents => 'Sign-ins and requests that Cbox ID scored as risky enough to flag, newest first, with the score and the reasons behind it. Look here after a spike in failed sign-ins, or when someone reports a sign-in they did not make.',

            self::SignInActivity => 'Sign-ins, tokens issued, new users and two-factor enrolments, day by day over the last 30 days unless the install sets another window. The page stays empty until whoever runs this install configures where analytics are stored.',

            self::Branding => 'The palette, logo, app name and email sender your people see on the console and the hosted sign-in page. Set it once for the whole environment and every organization inherits it, or choose an organization to give that one its own look.',

            self::Workspaces => 'A workspace is one signed-up company\'s home on this install, holding its projects, environments and team. Open one to walk its products and environments, or suspend it, which signs its members out and stops every environment it owns from serving sign-ins.',

            self::Environments => 'An environment is a fully isolated identity service with its own users, keys, sign-in and issuer, and every one on this install is listed here with the workspace that owns it. Create one, point the console at it, and give it its first organization and administrator.',

            self::PlatformOrganizations => 'Every organization in the environment the console is pointed at, laid out as a tree of resellers, the organizations they manage, and their sub-units. Create an organization here, suspend one, or move it under a different parent.',

            self::PlatformUsage => 'Organizations, users and active sessions for every environment on this install, and the organizations with the most members. It only reads, and it never changes which environment the console is pointed at.',

            self::PlatformSearch => 'Looks up an organization by name or handle, or a user by name or email, across every environment on this install rather than only the one the console is pointed at. Use it when someone asks for help and you do not know which environment they are in.',

            self::Operators => 'Platform operators administer every workspace and environment on this install, from above any single one of them. Add an operator only for someone who runs the deployment itself, and suspend them the day that stops being their job.',
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
            self::Members => 'guides/members',
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
            self::ReviewAgentRequests => 'guides/agent-approvals',
            self::Keys => 'guides/keys',
            self::Projects,
            self::Workspaces,
            self::Environments,
            self::Organizations,
            self::PlatformOrganizations => 'core-concepts/workspaces-and-organizations',
            self::DataExports => 'security/compliance',
            self::RiskEvents => 'security/adaptive-risk',
            self::SignInActivity => 'operations/analytics',
            self::Overview,
            self::Usage,
            self::Settings,
            self::Appearance,
            self::AccountSecurity,
            self::SessionsAndActivity,
            self::WorkspaceSettings,
            self::Team,
            self::EnvironmentDomains,
            self::Billing,
            self::EnvironmentOverview,
            self::Users,
            self::SignInRules,
            self::SamlApplications,
            self::LegacyLogin,
            self::Connectors,
            self::LogStreaming,
            self::Branding,
            self::PlatformUsage,
            self::PlatformSearch,
            self::Operators => null,
        };
    }
}
