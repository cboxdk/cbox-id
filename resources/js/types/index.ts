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

/** `App\Http\Props\Shared\FlashProps` */
export interface Flash {
    status: string | null;
    error: string | null;
}

/** What `App\Platform\Theme` decided this request should be painted in. */
export type ThemePreference = 'light' | 'dark' | null;

export interface SharedProps {
    /**
     * Inertia's own `PageProps` is an open record, and `createInertiaApp` will not accept
     * a closed interface in its place. The index signature is that requirement and
     * nothing more: everything this app actually shares is named below, and reading a key
     * that is not gets you `unknown` rather than a value — so a typo still fails to
     * compile at the point of use.
     */
    [key: string]: unknown;

    app: { name: string };
    theme: ThemePreference;
    auth: Auth;
    brand: Brand | null;
    sandbox: boolean;
    impersonation: ImpersonationSession | null;
    flash: Flash;
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
