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

/** `App\Http\Props\Shared\FlashProps` */
export interface Flash {
    status: string | null;
    error: string | null;
}

/** What `App\Platform\Theme` decided this request should be painted in. */
export type ThemePreference = 'light' | 'dark' | null;

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
    /** Where the row leads when it is more than a selection — an environment's own console. */
    openHref: string | null;
}

/** `App\Http\Props\Shell\ShellProps` — null on a page with no console chrome. */
export interface Shell {
    areas: NavArea[];
    activeArea: string | null;
    /** "Platform" for the pages about the whole install, null for a customer's own. */
    section: string | null;
    organizations: SwitchOption[];
    environments: SwitchOption[];
    isOperator: boolean;
    brandHref: string;
    navPinned: boolean;
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
