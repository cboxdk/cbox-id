import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import type { CatalogApp, SharedProps } from '@/types';
import { Input, Popover, PopoverContent, PopoverTrigger } from '@/ui';

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
export function AppSwitcher({ compact = false }: { compact?: boolean }) {
    const { appCatalog = [] } = usePage<SharedProps>().props;
    const [query, setQuery] = useState('');
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
    const apps = appCatalog.filter((app) =>
        `${app.name} ${app.description ?? ''}`.toLowerCase().includes(query.toLowerCase()),
    );
    return (
        <Popover
            onOpenChange={(open) => {
                if (open) {
                    setQuery('');
                    refresh();
                }
            }}
        >
            <PopoverTrigger
                className={compact ? 'cbx-rail-brand' : 'btn btn-secondary w-full'}
                aria-label="Switch app"
            >
                {compact ? (
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--primary)] text-[var(--primary-foreground)] font-semibold">
                        ID
                    </span>
                ) : (
                    'Switch app'
                )}
            </PopoverTrigger>
            <PopoverContent
                side={compact ? 'right' : 'bottom'}
                className="w-80 max-w-[calc(100vw-2rem)] p-3"
                aria-label="Your apps"
            >
                <h2 className="mb-3 font-semibold">Your apps</h2>
                <Input
                    aria-label="Search apps"
                    placeholder="Search apps…"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
                <nav aria-label="Apps" className="mt-2 max-h-80 overflow-y-auto space-y-1">
                    {apps.map((app) => (
                        <a
                            key={app.key}
                            href={app.launch_url}
                            className="flex items-center gap-3 rounded-lg p-2 hover:bg-[var(--muted)] focus-visible:ring-2 focus-visible:ring-[var(--ring)]"
                        >
                            <CatalogAppIcon app={app} />
                            <span className="min-w-0">
                                <span className="block truncate font-medium">{app.name}</span>
                                <span className="block text-xs text-[var(--muted-foreground)]">
                                    {app.description}
                                </span>
                            </span>
                        </a>
                    ))}
                    {apps.length === 0 && (
                        <p className="p-2 text-sm text-[var(--muted-foreground)]">
                            {query
                                ? 'No apps match your search.'
                                : 'No apps have been published for you yet.'}
                        </p>
                    )}
                </nav>
            </PopoverContent>
        </Popover>
    );
}
