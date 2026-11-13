/**
 * WHICH PAGES ARE LIVE, and what each one shows — by Inertia component name.
 *
 * A page listed here reloads its props when a record of one of its topics changes on a
 * channel the page was offered (its environment, its organization, or the signed-in
 * person's own). Topics are `App\Platform\Realtime\RecordTopics::TOPICS`.
 *
 * Forms that CREATE something are deliberately absent: there is nothing on them to bring
 * up to date, and a reload is the last thing a half-filled form wants. Pages with an edit
 * form are listed — the reload never runs under a draft (see `useLiveReload`).
 *
 * One table rather than a hook call in each page, so a page's live topics are reviewed in
 * one place and a page file does not change to become live.
 */
export const LIVE_PAGES: Readonly<Record<string, readonly string[]>> = {
    // A person's own pages.
    'account/security': ['credentials', 'devices', 'users', 'sessions'],
    'account/activity': ['sessions', 'tokens', 'audit', 'credentials'],
    'account/api-keys': ['api_keys'],
    'devices::mine': ['devices'],
    'console/approvals': ['approvals'],

    // The environment console.
    'environment/home': ['users', 'organizations', 'apps', 'audit'],
    'environment/approvals': ['approvals'],
    'environment/users/index': ['users', 'members'],
    'environment/users/show': [
        'users',
        'sessions',
        'devices',
        'credentials',
        'tokens',
        'members',
        'roles',
        'approvals',
        'audit',
    ],
    'environment/users/profile': ['users'],
    'environment/staff/index': ['members', 'roles'],
    'environment/organizations/index': ['organizations', 'members'],
    'environment/organizations/tabs/overview': ['organizations', 'members', 'audit'],
    'environment/organizations/tabs/members': ['members', 'roles'],
    'environment/organizations/tabs/invitations': ['members'],
    'environment/organizations/tabs/api-keys': ['api_keys', 'keys'],
    'environment/organizations/tabs/settings': ['organizations'],
    'environment/agents/index': ['apps', 'keys', 'approvals'],
    'devices::index': ['devices'],
    'analytics::sign-in-activity': ['sessions', 'audit'],
    'risk-plus::events': ['audit'],
    'compliance::audit': ['audit'],

    // Both consoles: an organization's pages, and the environment console's same pages.
    'console/dashboard': ['members', 'apps', 'projects', 'audit'],
    'console/members': ['members', 'roles'],
    'console/directory-members': ['members', 'users', 'roles'],
    'console/member-api-keys': ['api_keys'],
    'console/keys/management': ['keys'],
    'console/keys/organization': ['keys'],
    'console/keys/frontend': ['keys'],
    'console/clients/index': ['apps'],
    'console/clients/show': ['apps', 'tokens'],
    'console/clients/settings': ['apps'],
    'console/clients/sign-in': ['apps'],
    'console/clients/scopes': ['apps'],
    'console/clients/secrets': ['apps'],
    'console/roles/index': ['roles'],
    'console/roles/show': ['roles', 'members'],
    'console/audit': ['audit'],
    'console/audit-logs/index': ['audit'],
    'console/projects/index': ['projects'],
    'console/projects/show': ['projects'],
    'console/orgs/index': ['organizations', 'members'],
};

export function liveTopicsFor(component: string): readonly string[] {
    return LIVE_PAGES[component] ?? [];
}
