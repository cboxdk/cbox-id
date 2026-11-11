import type { SharedProps } from '@/types';

/** One page of the rail this person sees, whichever console drew it. */
export interface RailPage {
    route: string;
    label: string;
    href: string;
}

/** One area of the rail, with its pages. */
export interface RailArea {
    key: string;
    label: string;
    href: string;
    pages: RailPage[];
}

/**
 * THE RAIL THIS PAGE IS DRAWN WITH, as page content reads it (a breadcrumb's area, a guide's
 * links): the shared shell's nav (`shell.nav`), or a customer environment's own console
 * (`tenantShell`). Both carry the same pages; only the shapes differ.
 */
export function rail(props: Pick<SharedProps, 'shell' | 'tenantShell'>): {
    areas: RailArea[];
    active: RailArea | undefined;
} {
    if (props.shell) {
        const areas = props.shell.nav.areas.map((area) => ({
            key: area.key,
            label: area.label,
            href: area.url,
            pages: (area.pages ?? []).map((page) => ({
                route: page.key,
                label: page.label,
                href: page.url,
            })),
        }));

        return { areas, active: areas.find((area) => area.key === props.shell?.nav.activeArea) };
    }

    const tenant = props.tenantShell;
    const areas = (tenant?.areas ?? []).map((area) => ({
        key: area.key,
        label: area.label,
        href: area.href,
        pages: area.pages.map((page) => ({ route: page.route, label: page.label, href: page.href })),
    }));

    return { areas, active: areas.find((area) => area.key === tenant?.activeArea) };
}
