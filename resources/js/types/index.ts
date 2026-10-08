/**
 * THE SERVER'S CONTRACT, as TypeScript.
 *
 * Everything here mirrors a typed prop object under `app/Http/Props/`. The mirroring is
 * the point: a page that reads `auth.user.email` is checked against what the middleware
 * actually shares, so a field renamed on the server is a compile error rather than an
 * `undefined` somebody finds in production.
 *
 * Page-specific props live beside their page, not here. This file is only what every
 * page is given without asking — see `App\Http\Middleware\HandleInertiaRequests`.
 */

import type { ApiAction } from '@/lib/apiSnippets';
import type { LinkTab } from '@/ui/LinkTabs';
import type { IconName } from '@/ui/icons';

/** `Cbox\Id\Organization\Enums\MembershipRole` */
export type MembershipRole = 'owner' | 'admin' | 'developer' | 'member' | 'viewer';

/** `App\Http\Props\Shared\UserProps` */
export interface User {
    id: string;
    name: string;
    email: string | null;
    emailVerified: boolean;
}

/** `App\Http\Props\Shared\OrganizationProps` */
export interface Organization {
    id: string;
    name: string;
    slug: string | null;
    role: MembershipRole | null;
}

/** `App\Http\Props\Shared\AuthProps` */
export interface Auth {
    user: User | null;
    organization: Organization | null;
}

/**
 * `App\Http\Props\Shared\BrandProps`
 *
 * The colours are deliberately absent: they are already in the document as a token
 * override the root view emitted, because a branded page that waits for React to
 * recolour it has already painted the wrong colour once.
 */
export interface Brand {
    name: string;
    logo: string | null;
}

/** `App\Http\Props\Shared\ImpersonationProps` */
export interface ImpersonationSession {
    subject: string;
    email: string | null;
    reason: string | null;
    expiresInSeconds: number;
}

/**
 * `App\Http\Props\Shared\EnvironmentProps`
 *
 * Which realm this request acts in. The badge that says so must survive below the `lg`
 * breakpoint: the breadcrumb that used to carry it did not, so two tabs — one staging,
 * one production — were indistinguishable at the moment of hitting Delete.
 */
export interface CurrentEnvironment {
    name: string | null;
    /** The environment type's backing value — `production`, `sandbox`, … */
    type: string | null;
    sandbox: boolean;
}

/** One language the hosted pages' picker offers, named in itself — "Dansk", not "Danish". */
export interface LocaleOption {
    code: string;
    name: string;
}

/**
 * `App\Http\Props\Shared\I18nProps`
 *
 * Null on every console page: the console is English and ships no catalogue. On a hosted
 * page, its own group's strings only, flattened to dot keys. Read through `useTranslator()`
 * in `@/i18n` rather than directly, so every lookup is checked against the generated key
 * type.
 */
export interface I18n {
    locale: string;
    locales: LocaleOption[];
    messages: Record<string, string>;
}

/** `App\Http\Props\Shared\FlashProps` */
export interface Flash {
    status: string | null;
    error: string | null;
}

/** What `App\Platform\Theme` decided this request should be painted in. */
export type ThemePreference = 'light' | 'dark' | null;

/**
 * `App\Http\Props\Shared\HelpProps`
 *
 * The resolved copy, not a topic key. A React page holding the KEY would need its own
 * copy of the strings to render, which is the second source of truth the enum exists to
 * prevent. `href` is null where no guide has been written — the UI omits the link rather
 * than shipping a 404.
 */
export interface HelpContent {
    topic: string;
    title: string;
    summary: string;
    href: string | null;
}

/**
 * `App\Http\Props\Shared\PaginationProps`
 *
 * State, not rendered links. Laravel's paginator array carries pre-built page links with
 * HTML entities in their labels; how many page numbers to draw and where the ellipsis
 * goes is a layout question, and layout is decided at 375px and at 1440px by the
 * component, not by the server.
 */
export interface Pagination {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number;
    to: number;
}

/**
 * `App\Http\Props\Shared\SimplePaginationProps`
 *
 * A page of a list whose total is deliberately not counted — see the PHP side for why.
 * Separate from {@see Pagination} rather than a nullable-total version of it, so a
 * component cannot promise a position the server never computed.
 */
export interface SimplePagination {
    currentPage: number;
    perPage: number;
    /** Rows on THIS page. */
    count: number;
    hasMore: boolean;
}

/** `App\Http\Props\Shell\NavPageProps` */
export interface NavPage {
    route: string;
    href: string;
    label: string;
    active: boolean;
    /**
     * The soft entitlement lock. The page is SHOWN and marked, because an organization
     * that cannot discover a capability exists cannot buy it. A hard gate removes the
     * page from `areas` entirely and 404s the route — a different question.
     */
    badge: string | null;
    /**
     * Something on the page waiting for the person reading the rail — the agent actions
     * held for their approval. Null (or absent) when there is nothing to say.
     */
    count?: number | null;
}

/**
 * `App\Http\Props\Shell\NavAreaProps`
 *
 * `current` is not `active`. `active` means this area owns the page being viewed and
 * paints the rail's filled marker; `current` means the rail LINK IS the page, which is
 * true only for a single-page area — when there is a second tier the sub-nav entry
 * carries `aria-current`, and two elements claiming to be the current page is worse
 * than none.
 */
export interface NavArea {
    key: string;
    label: string;
    icon: IconName;
    href: string;
    active: boolean;
    current: boolean;
    pages: NavPage[];
}

/** `App\Http\Props\Shell\SwitchOptionProps` */
export interface SwitchOption {
    id: string;
    label: string;
    caption: string | null;
    current: boolean;
    /** Where the row leads when choosing it is a page load rather than a POST. */
    openHref: string | null;
}

/** `App\Http\Props\Shell\ContextEnvironmentProps` */
export interface ContextEnvironment {
    id: string;
    name: string;
    /** The environment type's backing value — `production`, `sandbox`, … */
    type: string;
    current: boolean;
    /** The workspace host's `/open/{environment}`, carrying the page to land on. Leaves this host. */
    href: string;
}

/** `App\Http\Props\Shell\ContextProjectProps` */
export interface ContextProject {
    id: string;
    name: string;
    /** Whether the environment this console stands on belongs to this project. */
    current: boolean;
    environments: ContextEnvironment[];
}

/**
 * `App\Http\Props\Shell\ShellContextProps` — the topbar's
 * `Workspace ▾ / Project ▾ / Environment ▾`.
 */
export interface ShellContext {
    /** "Workspace" where the organization owns projects, "Organization" everywhere else. */
    noun: string;
    /** Always holds the current one, even when it is the only one. */
    workspaces: SwitchOption[];
    /** Where choosing a workspace POSTs; null where each row is a link instead. */
    switchUrl: string | null;
    /** Only the environments this person may open, grouped by project. */
    projects: ContextProject[];
}

/** One organization as a lookup answers it — `GET /admin/lookup/organizations`. */
export interface OrganizationOption {
    id: string;
    name: string;
}

/**
 * `App\Http\Props\Shared\OrganizationFilterProps` — the Organization chip on an
 * environment-wide list. It adds and removes `?{parameter}=` on the page's own URL, or —
 * where `hrefTemplate` is set — goes to that organization's own page instead.
 */
export interface OrganizationFilter {
    parameter: string;
    selected: OrganizationOption | null;
    /** An id was asked for that names no organization here: the list is empty, not unfiltered. */
    unknown: boolean;
    lookupHref: string;
    /** `__organization__` marks where the chosen id goes. */
    hrefTemplate: string | null;
}

/**
 * `App\Http\Props\Shared\OrganizationPickerProps` — "For which organization?" on an
 * environment-console create form. Locked when the form was opened from that organization's
 * own page.
 */
export interface OrganizationPicker {
    lookupHref: string;
    selected: OrganizationOption | null;
    locked: boolean;
    allowsEnvironment: boolean;
}

/** `App\Http\Props\Console\OrganizationHeaderProps` — the header every tab of an organization's page shares. */
export interface OrganizationHub {
    id: string;
    name: string;
    slug: string;
    status: string;
    tabs: LinkTab[];
    indexHref: string;
    /** Null when the organization's plan includes neither single sign-on nor directory sync. */
    portalLink: { href: string; covers: { value: string; label: string }[] } | null;
}

/** `App\Http\Props\Shell\ShellProps` — null on a page with no console chrome. */
export interface Shell {
    areas: NavArea[];
    activeArea: string | null;
    /** "Platform" for the pages about the whole install, null for a customer's own. */
    section: string | null;
    context: ShellContext;
    isOperator: boolean;
    /** Inside platform admin — the install as a whole. Its own rail, strip and way out. */
    platformMode: boolean;
    brandHref: string;
    navPinned: boolean;
    /** Absolute on the environment console: the person's own pages live on the workspace host. */
    accountHref: string;
    switchUserHref: string;
    /** `App\Platform\Console\ConsoleAltitude` — which console this page is drawn in. */
    altitude: 'workspace' | 'organization' | 'environment';
    /** The account menu's Workspace settings, where this person may change them. */
    workspaceSettingsHref: string | null;
    /** The account menu's way into platform admin. Operators only. */
    platformHref: string | null;
    /** The platform strip's way out. Platform mode only. */
    exitPlatformHref: string | null;
    /** A sentence above a page the rail does not offer, and where to go instead. */
    notice: ShellNotice | null;
}

/** `App\Http\Props\Shell\ShellNoticeProps` */
export interface ShellNotice {
    message: string;
    href: string;
    label: string;
}

export interface SharedProps {
    /**
     * Inertia's own `PageProps` is an open record, and `createInertiaApp` will not accept
     * a closed interface in its place. The index signature is that requirement and
     * nothing more: everything this app actually shares is named below, and reading a key
     * that is not gets you `unknown` rather than a value — so a typo still fails to
     * compile at the point of use.
     */
    [key: string]: unknown;

    app: {
        name: string;
        tagline: string;
        /**
         * Free text under the sign-in hero, EMPTY by default and deliberately so: a
         * self-hosted deployment must only claim what it can back.
         */
        trustLine: string;
        year: string;
    };
    theme: ThemePreference;
    auth: Auth;
    brand: Brand | null;
    environment: CurrentEnvironment;
    impersonation: ImpersonationSession | null;
    flash: Flash;
    shell: Shell | null;
    i18n: I18n | null;
    /**
     * Shared on every page under `/admin/organizations/{organization}/…`: the organization the
     * page is about, which the layout draws as the hub's header and tabs around the page.
     */
    organizationHub?: OrganizationHub | null;
    /**
     * The actions this console page hosts, keyed by name, for "</> API"
     * (`App\Platform\Connect\ActionSnippets`). Empty off the console. Optional because a
     * page rendered outside the middleware — a test, an error page — has none.
     */
    apiEquivalents?: Record<string, ApiAction>;
    /**
     * The page's name, stated by the controller.
     *
     * A PROP rather than something each page spells in its own `<Head>`: the root view
     * renders it into `<title>` on the first byte, and the layouts render the same string
     * through `<Head>` once React mounts. One statement, two consumers, and no page can
     * ship with a tab that says nothing but the product's name.
     */
    title?: string;
    /** Laravel's validation errors for the last submission, keyed by field. */
    errors: Record<string, string>;
}

/**
 * The props one page receives: its own, plus everything shared.
 *
 * ```ts
 * export default function Show({ endpoint }: PageProps<{ endpoint: Endpoint }>) { … }
 * ```
 */
export type PageProps<T = Record<string, never>> = T & SharedProps;
