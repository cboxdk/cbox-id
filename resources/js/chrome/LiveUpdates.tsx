import { usePage } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import { liveTopicsFor } from '@/lib/livePages';
import { useLiveReload } from '@/lib/useLiveReload';
import type { SharedProps } from '@/types';
import { Button } from '@/ui';

/**
 * LIVE UPDATES FOR WHATEVER PAGE IS ON SCREEN — mounted once in each layout.
 *
 * The page's topics come from `LIVE_PAGES`; a page that is not listed opens no channel. A
 * page with nothing to listen to, live updates off, or a socket that never connects looks
 * and works exactly as it always did: nothing here draws anything unless the person was
 * editing when something changed, and then only a quiet offer to refresh.
 */
export function LiveUpdates() {
    const { component } = usePage<SharedProps>();

    return <LivePage key={component} topics={liveTopicsFor(component)} />;
}

function LivePage({ topics }: { topics: readonly string[] }) {
    const { t } = useTranslator();
    const { stale, refresh } = useLiveReload(topics);

    if (!stale) {
        return null;
    }

    return (
        <output className="cbx-live-chip" aria-live="polite">
            <span>{t('console.live_new_data')}</span>
            <Button size="sm" variant="secondary" onClick={refresh}>
                {t('console.live_refresh')}
            </Button>
        </output>
    );
}
