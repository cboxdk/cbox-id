import {
    CboxShell,
    type ShellIcon,
    type ShellLinkComponent,
    type ShellNavigate,
    type ShellPreferenceChange,
} from '@cboxdk/cbox-ui';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ComponentProps, type ReactNode, useMemo } from 'react';
import { EmailConfirmationBanner } from '@/chrome/Banners';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { StepUpDialog } from '@/chrome/StepUpDialog';
import { Toaster } from '@/chrome/Toaster';
import { useDocumentLanguage } from '@/i18n';
import { setNavPinned, setThemePreference } from '@/lib/theme';
import { TenantConsoleLayout } from '@/layouts/TenantConsoleLayout';
import { OrganizationFrame } from '@/pages/environment/organizations/frame';
import type { ConsoleShell, SharedProps } from '@/types';
import { Icon, PageApiEquivalents, TooltipProvider } from '@/ui';
import { iconPaths, type IconName } from '@/ui/icons';

export interface ConsoleLayoutProps {
    children: ReactNode;
}

/** An action link that must be POSTed (the impersonation exit), as the server marks it. */
const POST_ACTION = '#post';

/**
 * The console's own glyphs under their own names, so the rail draws the same icons the pages
 * do. cbox-ui's built-in Lucide set covers any other name.
 */
const ID_ICONS: Record<string, ShellIcon> = Object.fromEntries(
    (Object.keys(iconPaths) as IconName[]).map((name) => {
        const Glyph: ShellIcon = ({ size = 18, strokeWidth, className }) => (
            <Icon
                name={name}
                className={className}
                style={{ width: size, height: size }}
                strokeWidth={strokeWidth}
            />
        );

        return [name, Glyph];
    }),
);

/** Inertia links for the shell; a link marked {@see POST_ACTION} is a POST button. */
const ShellLink: ShellLinkComponent = ({ href, children, ...rest }) =>
    href.endsWith(POST_ACTION) ? (
        <Link
            href={href.slice(0, -POST_ACTION.length)}
            method="post"
            as="button"
            className={rest.className}
            aria-label={rest['aria-label']}
        >
            {children}
        </Link>
    ) : (
        <Link href={href} {...(rest as Omit<ComponentProps<typeof Link>, 'href'>)}>
            {children}
        </Link>
    );

/**
 * cbox-ui 0.10 posts a switch as `{organization, project, environment, return_to}`, the levels
 * above the chosen one included; laravel-console-kit 0.2 reads `{select: {…}, from}` and keeps
 * the page only for the outermost level in `select`. So the levels that did not change are left
 * out: choosing Sandbox in the cascade switches the environment, not the organization.
 */
export function switchRequest(
    data: Record<string, unknown> | undefined,
    levels: { key: string; current: { id: string } }[],
): { select: Record<string, string>; from: string | undefined } {
    const select: Record<string, string> = {};
    let changed = false;

    for (const level of levels) {
        const chosen = data?.[level.key];

        if (typeof chosen !== 'string') {
            continue;
        }

        changed = changed || chosen !== level.current.id;

        if (changed) {
            select[level.key] = chosen;
        }
    }

    const from = data?.return_to;

    return { select, from: typeof from === 'string' ? from : undefined };
}

function navigator(shell: ConsoleShell): ShellNavigate {
    return (url, options) => {
        if (options?.newTab === true) {
            window.open(url, '_blank', 'noopener');

            return;
        }

        const method = options?.method ?? 'get';

        if (method === 'post' && url === shell.context.switchUrl) {
            const request = switchRequest(options?.data, shell.context.levels);

            // The item already open was chosen: there is nowhere to go.
            if (Object.keys(request.select).length > 0) {
                router.post(url, request);
            }

            return;
        }

        router.visit(url, { method, data: options?.data as Record<string, string> | undefined });
    };
}

function writeCookie(name: string, value: string): void {
    const secure = location.protocol === 'https:' ? '; secure' : '';

    document.cookie = `${name}=${value}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax${secure}`;
}

/**
 * THE CONSOLE'S ONE SHELL: cbox-ui's `CboxShell`, the same rail, header, ⌘K palette, Help
 * and phone layout every Cbox app has. The server describes it in one prop
 * (`shell`, {@see \App\Platform\Shell\IdShell}); this file only arranges the page inside it.
 *
 * A persistent Inertia layout, so the rail, Help and its chat survive page visits.
 *
 * A customer environment's own console is white-labelled and keeps its own chrome
 * ({@see TenantConsoleLayout}). A page with no chrome (mid-sign-in) renders bare.
 */
export default function ConsoleLayout({ children: page }: ConsoleLayoutProps) {
    const { shell, tenantShell, auth, title, organizationHub, i18n } =
        usePage<SharedProps>().props;

    useDocumentLanguage(i18n?.locale ?? 'en');

    const onPreferenceChange = useMemo<ShellPreferenceChange>(
        () => (key, value) => {
            if (key === 'theme') {
                setThemePreference(value as 'system' | 'light' | 'dark');
            } else if (key === 'railPinned') {
                setNavPinned(value === true);
            } else if (key === 'helpOpen') {
                writeCookie('cbox_shell_help', value === true ? '1' : '0');
            } else if (key === 'locale' && i18n?.preference && value !== i18n.locale) {
                router.post(
                    i18n.preference.updateUrl,
                    {
                        language: String(value),
                        subject_id: i18n.preference.subjectId,
                        version: i18n.preference.version,
                    },
                    { preserveScroll: true, preserveState: true },
                );
            }
        },
        [i18n],
    );

    if (tenantShell !== null && tenantShell !== undefined) {
        return <TenantConsoleLayout>{page}</TenantConsoleLayout>;
    }

    // A page under an environment console's `…/organizations/{organization}/…` is a tab of
    // that organization's page: its header and tabs go around it, whichever page it is.
    const children =
        organizationHub !== undefined && organizationHub !== null ? (
            <OrganizationFrame hub={organizationHub}>{page}</OrganizationFrame>
        ) : (
            page
        );

    const chrome = (
        <>
            <Head title={title} />
            <RouteAnnouncer />
            <Toaster />
            <StepUpDialog />
        </>
    );

    if (shell === null || auth.user === null) {
        return (
            <TooltipProvider>
                {chrome}
                <main id="main-content" className="canvas-gradient">
                    {children}
                </main>
            </TooltipProvider>
        );
    }

    const languages =
        i18n?.preference && i18n.locales.length > 1
            ? i18n.locales.map((option) => ({ code: option.code, label: option.name }))
            : undefined;

    return (
        <TooltipProvider>
            {chrome}
            <CboxShell
                shell={{
                    ...shell,
                    account: { ...shell.account, languages },
                    preferences: { ...shell.preferences, locale: i18n?.locale ?? 'en' },
                }}
                navigate={navigator(shell)}
                LinkComponent={ShellLink}
                icons={ID_ICONS}
                onPreferenceChange={onPreferenceChange}
            >
                <EmailConfirmationBanner />
                <div
                    className="cbx-page-frame p-6 pb-24 lg:p-8 mx-auto w-full"
                    style={{ maxWidth: '72rem' }}
                >
                    {/* "</> API": this page's actions as REST, MCP, CLI and SDK calls. */}
                    <div className="cbx-page-tools">
                        <PageApiEquivalents />
                    </div>
                    {children}
                </div>
            </CboxShell>
        </TooltipProvider>
    );
}
