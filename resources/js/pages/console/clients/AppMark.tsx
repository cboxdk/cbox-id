import { Bot, Code2, Globe, type LucideIcon, Server, Smartphone, Terminal } from 'lucide-react';
import { CatalogAppIcon } from '@/chrome/CatalogAppIcon';

/** What an app row knows about how the app catalog draws it. */
export interface AppFace {
    iconUrl: string | null;
    iconDarkUrl: string | null;
    hue: string | null;
}

/** The kind filter's values: {@see App\Platform\Console\AppListing::KINDS}. */
export type AppKindKey = 'web' | 'spa' | 'cli' | 'service' | 'agent' | 'other';

export const KIND_ICONS: Record<AppKindKey, LucideIcon> = {
    web: Globe,
    spa: Smartphone,
    cli: Terminal,
    service: Server,
    agent: Bot,
    other: Code2,
};

/** The catalog's named hues (App\Platform\AppCatalog\CatalogHue), as oklch hue angles. */
const HUES: Record<string, number> = {
    red: 25,
    orange: 50,
    amber: 75,
    yellow: 95,
    lime: 125,
    green: 145,
    emerald: 160,
    teal: 185,
    cyan: 205,
    sky: 230,
    blue: 258,
    indigo: 275,
    violet: 295,
    purple: 305,
    fuchsia: 320,
    pink: 345,
    rose: 10,
};

/**
 * THE APP'S FACE ON A ROW: the icon the catalog gives it — the same one the person meets in
 * the app switcher — and otherwise a tile with its kind's icon, tinted by the catalog hue
 * when it has one. Decorative: the row says the name and the kind in words.
 */
export function AppMark({
    name,
    kind,
    face,
    size = 'md',
}: {
    name: string;
    kind: AppKindKey;
    face: AppFace | null;
    size?: 'md' | 'lg';
}) {
    const box = size === 'lg' ? 'h-11 w-11' : 'h-9 w-9';

    if (face?.iconUrl) {
        return (
            <span className={`idc-app-mark ${box}`} aria-hidden="true">
                <CatalogAppIcon
                    app={{ name, icon_url: face.iconUrl, icon_dark_url: face.iconDarkUrl }}
                />
            </span>
        );
    }

    const KindIcon = KIND_ICONS[kind];
    const hue = face?.hue ? HUES[face.hue] : undefined;

    return (
        <span
            className={`idc-app-mark idc-app-mark--tile ${box}`}
            data-hue={hue === undefined ? undefined : 'set'}
            style={hue === undefined ? undefined : ({ '--app-hue': hue } as React.CSSProperties)}
            aria-hidden="true"
        >
            <KindIcon className={size === 'lg' ? 'h-5 w-5' : 'h-4 w-4'} strokeWidth={1.7} />
        </span>
    );
}
