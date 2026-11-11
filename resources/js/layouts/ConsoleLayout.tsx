import {
    applyTheme,
    CboxShell,
    type PaletteAction,
    parseShellCatalog,
    readKnownAppCatalog,
    rememberAppCatalog,
    type ShellIcon,
    type ShellLinkComponent,
    type ShellNavigate,
    type ShellPreferenceChange,
} from '@cboxdk/cbox-ui';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ComponentProps, type ReactNode, useEffect, useMemo } from 'react';
import { EmailConfirmationBanner } from '@/chrome/Banners';
import { RouteAnnouncer } from '@/chrome/RouteAnnouncer';
import { StepUpDialog } from '@/chrome/StepUpDialog';
import { Toaster } from '@/chrome/Toaster';
import { useDocumentLanguage, useTranslator } from '@/i18n';
import {
    FORMAT_CODES,
    FORMAT_COOKIE,
    formatLabel,
    setFormatPreferences,
    TIME_ZONE_COOKIE,
    writeFormatCookie,
} from '@/lib/formats';
import { setNavPinned, setThemePreference } from '@/lib/theme';
import { TenantConsoleLayout } from '@/layouts/TenantConsoleLayout';
import { OrganizationFrame } from '@/pages/environment/organizations/frame';
import type { ConsoleShell, I18n, SharedProps } from '@/types';
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

/**
 * A ⌘K action that changes a preference rather than going somewhere: the same choices the
 * account menu at the rail's foot offers, findable by typing "dark", "dansk" or "format".
 */
const PREFERENCE_ACTION = '#preference:';

export function preferenceAction(url: string): { key: string; value: string } | null {
    if (!url.startsWith(PREFERENCE_ACTION)) {
        return null;
    }

    const [key = '', ...rest] = url.slice(PREFERENCE_ACTION.length).split(':');
    const value = rest.join(':');

    return key === '' || value === '' ? null : { key, value };
}

/** One choice, wherever it was made: the cookie the server reads before the first paint, and the request that saves it. */
function savePreference(key: string, value: unknown, i18n: I18n | null | undefined): void {
    if (key === 'theme') {
        setThemePreference(value as 'system' | 'light' | 'dark');
    } else if (key === 'railPinned') {
        setNavPinned(value === true);
    } else if (key === 'helpOpen') {
        writeCookie('cbox_shell_help', value === true ? '1' : '0');
    } else if (key === 'regionalFormat') {
        writeFormatCookie(FORMAT_COOKIE, typeof value === 'string' ? value : undefined);
    } else if (key === 'timeZone') {
        writeFormatCookie(TIME_ZONE_COOKIE, typeof value === 'string' ? value : undefined);
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
}

/** The palette's preference entries; the account menu holds the same choices. */
export function preferenceActions(
    labels: {
        theme: string;
        themes: Record<'system' | 'light' | 'dark', string>;
        language: string;
        format: string;
    },
    languages: { code: string; label: string }[],
    formats: { code: string; label: string }[],
): PaletteAction[] {
    const actions: PaletteAction[] = (['system', 'light', 'dark'] as const).map((theme) => ({
        id: `preference.theme.${theme}`,
        label: `${labels.theme}: ${labels.themes[theme]}`,
        url: `${PREFERENCE_ACTION}theme:${theme}`,
        method: 'post',
        icon: theme === 'dark' ? 'moon' : theme === 'light' ? 'sun' : 'sliders',
        keywords: ['theme', 'appearance', 'dark mode', 'light mode', theme],
    }));

    for (const language of languages) {
        actions.push({
            id: `preference.locale.${language.code}`,
            label: `${labels.language}: ${language.label}`,
            url: `${PREFERENCE_ACTION}locale:${language.code}`,
            method: 'post',
            icon: 'globe',
            keywords: ['language', 'sprog', language.code],
        });
    }

    for (const format of formats) {
        actions.push({
            id: `preference.format.${format.code}`,
            label: `${labels.format}: ${format.label}`,
            url: `${PREFERENCE_ACTION}regionalFormat:${format.code}`,
            method: 'post',
            icon: 'calendar',
            keywords: ['format', 'regional format', 'date', 'number', format.code],
        });
    }

    return actions;
}

function navigator(shell: ConsoleShell, i18n: I18n | null | undefined): ShellNavigate {
    return (url, options) => {
        const preference = preferenceAction(url);

        if (preference !== null) {
            if (preference.key === 'theme') {
                applyTheme(preference.value as 'system' | 'light' | 'dark');
            }

            savePreference(preference.key, preference.value, i18n);

            // The language reloads the page itself; anything else re-reads the shell, whose
            // preferences come from the cookies just written, so the account menu agrees.
            if (preference.key !== 'locale') {
                router.reload({ only: ['shell'] });
            }

            return;
        }

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

/**
 * The rail draws this app's mark from the catalog the browser already knows, which until
 * now was only filled once the app switcher had been opened: a first visit showed the
 * initials. Read once per browser, the way the switcher reads it, and only while the mark
 * is missing.
 */
function useAppMark(appKey: string | undefined, catalogUrl: string | undefined): void {
    useEffect(() => {
        if (appKey === undefined || catalogUrl === undefined || catalogUrl === '') {
            return;
        }

        if (readKnownAppCatalog().some((app) => app.key === appKey && app.icon_url !== null)) {
            return;
        }

        const controller = new AbortController();

        fetch(catalogUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                const state = parseShellCatalog(
                    response.ok,
                    await response.json().catch(() => null),
                    window.location,
                );

                if (state.status === 'ready') {
                    rememberAppCatalog(
                        state.apps.map((app) => ({
                            key: app.key,
                            name: app.name,
                            launch_url: app.url,
                            icon_url: app.iconUrl,
                            icon_dark_url: app.iconDarkUrl,
                        })),
                    );
                }
            })
            // The initials stay: the switcher reads the catalog again when it opens.
            .catch(() => undefined);

        return () => controller.abort();
    }, [appKey, catalogUrl]);
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
    const { shell, tenantShell, auth, title, organizationHub, i18n } = usePage<SharedProps>().props;

    useDocumentLanguage(i18n?.locale ?? 'en');

    const { t } = useTranslator();

    useAppMark(shell?.app.key, shell?.apps.catalogUrl);

    const onPreferenceChange = useMemo<ShellPreferenceChange>(
        () => (key, value) => {
            if (key === 'regionalFormat' || key === 'timeZone') {
                setFormatPreferences({
                    regionalFormat:
                        key === 'regionalFormat'
                            ? (value as string)
                            : shell?.preferences.regionalFormat,
                    timeZone: key === 'timeZone' ? (value as string) : shell?.preferences.timeZone,
                });
            }

            savePreference(key, value, i18n);
        },
        [i18n, shell],
    );

    // Dates on every page use the format and zone the shell says this person chose.
    setFormatPreferences({
        regionalFormat: shell?.preferences.regionalFormat,
        timeZone: shell?.preferences.timeZone,
    });

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

    // The languages this console is translated into, each named in itself. Offered only
    // where the choice can be saved; elsewhere the menu shows the one language in use rather
    // than a default list whose choices would do nothing.
    const languages =
        i18n?.preference && i18n.locales.length > 1
            ? i18n.locales.map((option) => ({ code: option.code, label: option.name }))
            : i18n?.locales
                  .filter((option) => option.code === i18n.locale)
                  .map((option) => ({ code: option.code, label: option.name }));
    const formats = FORMAT_CODES.map((code) => ({ code, label: formatLabel(code) }));
    const palette = {
        ...shell.palette,
        actions: [
            ...shell.palette.actions,
            ...preferenceActions(
                {
                    theme: t('console.theme_efb52e71'),
                    themes: {
                        system: t('console.system_6725e7bb'),
                        light: t('console.light_dbcd5e7b'),
                        dark: t('console.dark_60acc53f'),
                    },
                    language: t('console.language'),
                    format: t('console.regional_format'),
                },
                i18n?.preference
                    ? (languages ?? []).filter((language) => language.code !== i18n.locale)
                    : [],
                formats,
            ),
        ],
    };

    return (
        <TooltipProvider>
            {chrome}
            <CboxShell
                shell={{
                    ...shell,
                    palette,
                    account: { ...shell.account, languages, formats },
                    preferences: { ...shell.preferences, locale: i18n?.locale ?? 'en' },
                }}
                navigate={navigator(shell, i18n)}
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
