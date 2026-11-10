import { useState } from 'react';
import type { CatalogApp } from '@/types';

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
