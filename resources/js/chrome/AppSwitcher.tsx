import { ServiceMark, hasServiceMark } from './ServiceMark';
import { useTranslator } from '@/i18n';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CatalogApp, SharedProps } from '@/types';
import { Icon, Popover, PopoverContent, PopoverTrigger } from '@/ui';

export function CatalogAppIcon({ app }: { app: Pick<CatalogApp, 'name' | 'icon_url'> }) {
    const [failed, setFailed] = useState<string | null>(null);
    return (
        <span
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[var(--border)] bg-[var(--muted)] text-sm font-semibold"
            aria-hidden="true"
        >
            {app.icon_url && failed !== app.icon_url ? (
                <img
                    src={app.icon_url}
                    alt=""
                    className="h-full w-full rounded-lg object-contain"
                    onError={() => setFailed(app.icon_url)}
                />
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
    const { appCatalog = [] } = usePage<SharedProps>().props;
    const firstApp = useRef<HTMLAnchorElement>(null);
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
                            >
                                {hasServiceMark(app.key) ? (
                                    <ServiceMark service={app.key} />
                                ) : (
                                    <CatalogAppIcon app={app} />
                                )}
                                <span>{app.name.replace(/^Cbox\s*·\s*/i, '')}</span>
                                {app.key === 'id' && (
                                    <Icon name="check" className="cbx-launch-check" />
                                )}
                            </a>
                        ))}
                    </nav>
                ) : (
                    <p>{t('console.catalog_empty')}</p>
                )}
            </PopoverContent>
        </Popover>
    );
}
