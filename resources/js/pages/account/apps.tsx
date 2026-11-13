import { CboxAppIcon, hueFor, initialsOf } from '@cboxdk/cbox-ui';
import { type ReactNode, useDeferredValue, useMemo, useState } from 'react';
import { useTranslator } from '@/i18n';
import AccountLayout from '@/layouts/AccountLayout';
import { absoluteTime, relativeTime } from '@/lib/time';
import type { HelpContent, PageProps } from '@/types';
import { Button, EmptyState, Icon, Input, PageHeader, Panel } from '@/ui';

export interface LauncherApp {
    key: string;
    name: string;
    description: string | null;
    /** What the catalog says the app is for ("Finance"): the group it is listed under. */
    purpose: string | null;
    hue: string | null;
    iconUrl: string | null;
    iconDarkUrl: string | null;
    /** Where it opens (launch URL), or for a requestable app where to ask. */
    url: string | null;
    lastUsedIso: string | null;
}

type Props = PageProps<{
    apps: LauncherApp[];
    requestable: LauncherApp[];
    help: HelpContent;
}>;

/** Above this many apps a search box earns its place. */
const SEARCH_FROM = 7;
/** "Recently used" holds this many at most: a row, not a second list. */
const RECENT = 4;

/**
 * YOUR APPS: a launcher, not a list to manage. Recently used first, then every app grouped
 * by what it is for, and a search box once there are enough apps to need one. Each tile is
 * one link, the whole tile. ({@see \App\Http\Controllers\AccountAppsController})
 */
export default function Apps({ apps, requestable, help }: Props) {
    const { t, choice } = useTranslator();
    const [query, setQuery] = useState('');
    const deferred = useDeferredValue(query.trim().toLocaleLowerCase());

    const matches = useMemo(
        () =>
            deferred === ''
                ? apps
                : apps.filter((app) =>
                      [app.name, app.description ?? '', app.purpose ?? ''].some((text) =>
                          text.toLocaleLowerCase().includes(deferred),
                      ),
                  ),
        [apps, deferred],
    );

    const recent = useMemo(
        () =>
            apps
                .filter((app) => app.lastUsedIso !== null)
                .sort((a, b) => Date.parse(b.lastUsedIso ?? '') - Date.parse(a.lastUsedIso ?? ''))
                .slice(0, RECENT),
        [apps],
    );

    const groups = useMemo(() => groupByPurpose(matches), [matches]);
    const grouped = groups.some((group) => group.purpose !== null);
    const searching = deferred !== '';

    return (
        <div>
            <PageHeader
                help={help}
                description={t('console.account_apps_description')}
                badge={
                    apps.length > 0 ? (
                        <span className="acct-count">
                            {choice('console.account_apps_count', apps.length)}
                        </span>
                    ) : undefined
                }
            />

            {apps.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon="apps"
                        title={t('console.account_apps_empty_title')}
                        description={t('console.account_apps_empty_description')}
                    />
                </Panel>
            ) : (
                <>
                    {apps.length >= SEARCH_FROM && (
                        <div className="launcher-search">
                            <Icon name="search" className="launcher-search-icon" />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder={t('console.account_apps_search_placeholder')}
                                aria-label={t('console.account_apps_search_label')}
                                autoComplete="off"
                                data-test="apps-search"
                            />
                            {/* How many match, said to a screen reader as the person types. */}
                            <output className="sr-only" aria-live="polite">
                                {searching
                                    ? choice('console.account_apps_count', matches.length)
                                    : ''}
                            </output>
                        </div>
                    )}

                    {!searching && recent.length > 0 && (
                        <LauncherSection
                            title={t('console.account_apps_recent')}
                            count={recent.length}
                        >
                            {recent.map((app) => (
                                <AppTile key={app.key} app={app} />
                            ))}
                        </LauncherSection>
                    )}

                    {matches.length === 0 ? (
                        <Panel>
                            <EmptyState
                                icon="search"
                                title={t('console.account_apps_no_match_title', {
                                    query: query.trim(),
                                })}
                                description={t('console.account_apps_no_match_description')}
                                actions={
                                    <Button size="sm" onClick={() => setQuery('')}>
                                        {t('console.account_apps_clear_search')}
                                    </Button>
                                }
                            />
                        </Panel>
                    ) : (
                        groups.map((group) => (
                            <LauncherSection
                                key={group.purpose ?? ''}
                                title={
                                    group.purpose ??
                                    (grouped
                                        ? t('console.account_apps_other')
                                        : t('console.account_apps_all'))
                                }
                                count={group.apps.length}
                            >
                                {group.apps.map((app) => (
                                    <AppTile key={app.key} app={app} />
                                ))}
                            </LauncherSection>
                        ))
                    )}
                </>
            )}

            {requestable.length > 0 && !searching && (
                <LauncherSection
                    title={t('console.account_apps_requestable_title')}
                    description={t('console.account_apps_requestable_description')}
                    count={requestable.length}
                >
                    {requestable.map((app) => (
                        <AppTile key={app.key} app={app} requestable />
                    ))}
                </LauncherSection>
            )}
        </div>
    );
}

/** The apps by purpose, in the catalog's order of first appearance; apps without one last. */
export function groupByPurpose(
    apps: LauncherApp[],
): { purpose: string | null; apps: LauncherApp[] }[] {
    const groups = new Map<string | null, LauncherApp[]>();

    for (const app of apps) {
        const purpose = app.purpose?.trim() || null;
        const list = groups.get(purpose) ?? [];
        list.push(app);
        groups.set(purpose, list);
    }

    const named = [...groups.entries()].filter(([purpose]) => purpose !== null);
    const rest = groups.get(null);

    return [
        ...named.map(([purpose, list]) => ({ purpose, apps: list })),
        ...(rest !== undefined ? [{ purpose: null, apps: rest }] : []),
    ];
}

function LauncherSection({
    title,
    description,
    count,
    children,
}: {
    title: string;
    description?: string;
    count: number;
    children: ReactNode;
}) {
    return (
        <section className="launcher-section" aria-label={title}>
            <header className="launcher-section-head">
                <h2>{title}</h2>
                <span className="launcher-section-count" aria-hidden="true">
                    {count}
                </span>
            </header>
            {description !== undefined && <p className="launcher-section-lead">{description}</p>}
            <ul className="launcher-grid">{children}</ul>
        </section>
    );
}

function AppTile({ app, requestable = false }: { app: LauncherApp; requestable?: boolean }) {
    const { t, locale } = useTranslator();
    const hue = hueFor(app.key, app.hue);

    const body = (
        <>
            <CboxAppIcon
                name={app.name}
                iconUrl={app.iconUrl}
                iconDarkUrl={app.iconDarkUrl}
                className="launcher-icon"
                fallback={
                    <span
                        className="launcher-monogram"
                        style={{ '--app-hue': String(hue) } as React.CSSProperties}
                        aria-hidden="true"
                    >
                        {initialsOf(app.name)}
                    </span>
                }
            />
            <span className="launcher-text">
                <span className="launcher-name">{app.name}</span>
                {app.description !== null && (
                    <span className="launcher-description">{app.description}</span>
                )}
                {requestable ? (
                    <span className="launcher-meta launcher-meta--action">
                        {t('console.account_apps_request')}
                        <Icon name="external" className="w-3 h-3" />
                    </span>
                ) : (
                    app.lastUsedIso !== null && (
                        <time
                            className="launcher-meta"
                            dateTime={app.lastUsedIso}
                            title={absoluteTime(app.lastUsedIso, locale)}
                        >
                            {t('console.account_apps_used_when', {
                                when: relativeTime(app.lastUsedIso, locale),
                            })}
                        </time>
                    )
                )}
            </span>
            {!requestable && <Icon name="arrow-right" className="launcher-arrow" />}
        </>
    );

    return (
        <li>
            {app.url === null ? (
                <div className="launcher-tile launcher-tile--static">{body}</div>
            ) : (
                <a
                    href={app.url}
                    className={
                        requestable ? 'launcher-tile launcher-tile--requestable' : 'launcher-tile'
                    }
                    aria-label={
                        requestable
                            ? t('console.account_apps_request_for', { app: app.name })
                            : t('console.account_apps_open', { app: app.name })
                    }
                    data-test="launcher-tile"
                >
                    {body}
                </a>
            )}
        </li>
    );
}

Apps.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;
