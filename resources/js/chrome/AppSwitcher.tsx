import { ServiceMark, hasServiceMark } from './ServiceMark';
import { useTranslator } from '@/i18n';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { flushSync, preconnect } from 'react-dom';
import type { CatalogApp, SharedProps } from '@/types';
import { Icon, Popover, PopoverContent, PopoverTrigger } from '@/ui';

/**
 * The catalog in ID is the one source of app icons. With a dark icon both images are in the
 * document and CSS shows the one matching the painted theme, so a theme toggle never refetches.
 */
export function CatalogAppIcon({
    app,
}: {
    app: Pick<CatalogApp, 'name' | 'icon_url'> & { icon_dark_url?: string | null };
}) {
    const [failed, setFailed] = useState<string | null>(null);
    const dark = app.icon_dark_url ?? null;
    const sources = `${app.icon_url ?? ''} ${dark ?? ''}`;
    const image = (src: string, className: string) => (
        <img
            src={src}
            alt=""
            className={`h-full w-full rounded-lg object-contain ${className}`}
            onError={() => setFailed(sources)}
        />
    );
    return (
        <span
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[var(--border)] bg-[var(--muted)] text-sm font-semibold"
            aria-hidden="true"
        >
            {app.icon_url && failed !== sources ? (
                dark ? (
                    <>
                        {image(app.icon_url, 'cbx-app-icon-light')}
                        {image(dark, 'cbx-app-icon-dark')}
                    </>
                ) : (
                    image(app.icon_url, '')
                )
            ) : (
                app.name.slice(0, 2).toUpperCase()
            )}
        </span>
    );
}

let lastCatalogRefresh = Date.now();

/** The payload is already filtered by ID. This component never invents grants or apps. */
export function AppSwitcher() {
    const { t } = useTranslator();
    const page = usePage<SharedProps>();
    const { appCatalog = [] } = page.props;
    const fromBookmark = new URLSearchParams(page.url?.split('?')[1]).get('switch-app') === '1';
    const firstApp = useRef<HTMLAnchorElement>(null);
    const [launching, setLaunching] = useState<string | null>(null);
    // Warm only the selected destination's connection, never its authenticated URL.
    const prepare = (url: string) => {
        const destination = new URL(url, window.location.href);
        if (destination.protocol === 'https:' && destination.origin !== window.location.origin)
            preconnect(destination.origin);
    };
    useEffect(() => {
        if (!launching) return;
        const reset = () => setLaunching(null);
        // Restore the picker after Back (including bfcache) or an aborted navigation.
        window.addEventListener('pageshow', reset);
        const timer = window.setTimeout(reset, 15000);
        return () => {
            window.removeEventListener('pageshow', reset);
            window.clearTimeout(timer);
        };
    }, [launching]);
    const refresh = useCallback(() => {
        if (document.visibilityState !== 'visible' || Date.now() - lastCatalogRefresh < 60000)
            return;
        lastCatalogRefresh = Date.now();
        router.reload({ only: ['appCatalog'] });
    }, []);
    useEffect(() => {
        const timer = window.setInterval(refresh, 60000);
        window.addEventListener('focus', refresh);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', refresh);
        };
    }, [refresh]);
    const label = t('console.catalog_switch_app');
    return (
        <Popover
            defaultOpen={fromBookmark}
            onOpenChange={(open) => {
                if (open) refresh();
            }}
        >
            <PopoverTrigger className="cbx-launch-trigger" aria-label={label} title={label}>
                <svg
                    aria-hidden="true"
                    width="18"
                    height="18"
                    viewBox="0 0 18 18"
                    fill="currentColor"
                >
                    {[3, 9, 15].flatMap((y) =>
                        [3, 9, 15].map((x) => <circle key={x + ':' + y} cx={x} cy={y} r="1.65" />),
                    )}
                </svg>
            </PopoverTrigger>
            <PopoverContent
                side="bottom"
                align="end"
                onOpenAutoFocus={(event) => {
                    if (firstApp.current) {
                        event.preventDefault();
                        firstApp.current.focus();
                    }
                }}
                collisionPadding={8}
                className="cbx-launch-panel"
                aria-label={label}
            >
                <h2>{label}</h2>
                {appCatalog.length ? (
                    <nav className="cbx-launch-grid" aria-label={t('console.apps_89dd7484')}>
                        {appCatalog.map((app, index) => (
                            <a
                                key={app.key}
                                ref={index === 0 ? firstApp : undefined}
                                href={app.launch_url}
                                aria-current={app.key === 'id' ? 'true' : undefined}
                                aria-busy={launching === app.key || undefined}
                                onPointerEnter={() => prepare(app.launch_url)}
                                onFocus={() => prepare(app.launch_url)}
                                onClick={(event) => {
                                    if (
                                        event.defaultPrevented ||
                                        event.button !== 0 ||
                                        event.metaKey ||
                                        event.ctrlKey ||
                                        event.shiftKey ||
                                        event.altKey
                                    )
                                        return;
                                    prepare(app.launch_url);
                                    // Paint feedback before the browser starts the cross-product SSO navigation.
                                    flushSync(() => setLaunching(app.key));
                                }}
                            >
                                {hasServiceMark(app.key) ? (
                                    <ServiceMark service={app.key} />
                                ) : (
                                    <CatalogAppIcon app={app} />
                                )}
                                <span>{app.name.replace(/^Cbox\s*·\s*/i, '')}</span>
                                {launching === app.key ? (
                                    <span
                                        className="cbx-launch-check motion-safe:animate-spin rounded-full border-2 border-current border-t-transparent"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    app.key === 'id' && (
                                        <Icon name="check" className="cbx-launch-check" />
                                    )
                                )}
                            </a>
                        ))}
                    </nav>
                ) : (
                    <p>{t('console.catalog_empty')}</p>
                )}
                <output
                    aria-live="polite"
                    className="mt-2 block text-center text-sm text-[var(--muted-foreground)] empty:hidden"
                >
                    {launching &&
                        `${t('console.loading_dc380888')} · ${appCatalog.find((app) => app.key === launching)?.name.replace(/^Cbox\s*·\s*/i, '') ?? ''}`}
                </output>
            </PopoverContent>
        </Popover>
    );
}
