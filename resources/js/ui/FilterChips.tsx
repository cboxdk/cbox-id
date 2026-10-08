import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import type { OrganizationFilter } from '@/types';
import { Icon } from './Icon';
import { OrganizationLookupList } from './OrganizationField';
import { Popover, PopoverContent, PopoverTrigger } from './Popover';

/**
 * THE NARROWINGS A LIST IS SHOWING, above it — each one in the URL, said out loud, and
 * removable in one click.
 *
 * It replaced a hidden filter. The environment console used to keep an "acting
 * organization" in the session that silently narrowed sixteen pages, so a list could be one
 * customer's while its heading said nothing of the kind. A narrowing that changes what a
 * list means has to be ON the list.
 */
export function FilterChips({
    children,
    label = 'Filters',
}: {
    children: ReactNode;
    label?: string;
}) {
    return (
        <fieldset className="cbx-filter-chips">
            <legend className="sr-only">{label}</legend>
            {children}
        </fieldset>
    );
}

/** One narrowing that is in force: its name, its value, and the way to drop it. */
export function FilterChip({
    label,
    value,
    clearLabel,
    onClear,
    warning = false,
}: {
    label: string;
    value: ReactNode;
    /** What the clear button says to a screen reader — "Show every organization". */
    clearLabel: string;
    onClear: () => void;
    warning?: boolean;
}) {
    return (
        <span className={warning ? 'cbx-filter-chip is-warning' : 'cbx-filter-chip'}>
            <span className="cbx-filter-chip-label">{label}:</span>
            <span className="cbx-filter-chip-value">{value}</span>
            <button
                type="button"
                className="cbx-filter-chip-clear"
                aria-label={clearLabel}
                onClick={onClear}
            >
                <Icon name="close" className="w-3 h-3" aria-hidden="true" />
            </button>
        </span>
    );
}

/** This page's URL with one query parameter set (or dropped), and back to its first page. */
function withParameter(parameter: string, value: string | null): string {
    const url = new URL(window.location.href);

    if (value === null) {
        url.searchParams.delete(parameter);
    } else {
        url.searchParams.set(parameter, value);
    }

    // A narrower list starts again at its first page: page 4 of every organization's rows
    // is not page 4 of one organization's.
    url.searchParams.delete('page');

    return url.pathname + url.search;
}

/**
 * THE ORGANIZATION CHIP — `?organization=` on an environment-wide list.
 *
 * Unset, it is a dashed "Organization" chip that opens the lookup; set, it names the
 * organization and clears in one click. An id that names nothing here (another
 * environment's, a typo in a pasted link) is said as such, and the list under it is empty
 * rather than quietly unfiltered.
 *
 * Where the per-organization half of a list is a page of its own (`hrefTemplate`, the token
 * vault), choosing one GOES there instead of narrowing this list.
 */
export function OrganizationFilterChip({ filter }: { filter: OrganizationFilter }) {
    const [open, setOpen] = useState(false);

    const choose = (id: string): void => {
        setOpen(false);

        if (filter.hrefTemplate !== null) {
            router.visit(filter.hrefTemplate.replace('__organization__', encodeURIComponent(id)));

            return;
        }

        router.visit(withParameter(filter.parameter, id), { preserveScroll: true });
    };

    const clear = (): void =>
        router.visit(withParameter(filter.parameter, null), { preserveScroll: true });

    if (filter.selected !== null) {
        return (
            <FilterChip
                label="Organization"
                value={filter.selected.name}
                clearLabel="Show every organization"
                onClear={clear}
            />
        );
    }

    if (filter.unknown) {
        return (
            <FilterChip
                label="Organization"
                value="not in this environment"
                clearLabel="Show every organization"
                onClear={clear}
                warning
            />
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger className="cbx-filter-chip is-unset">
                <Icon name="plus" className="w-3 h-3" aria-hidden="true" />
                Organization
            </PopoverTrigger>
            <PopoverContent className="cbx-combobox" align="start" style={{ width: '18rem' }}>
                <OrganizationLookupList
                    lookupHref={filter.lookupHref}
                    open={open}
                    selectedId={null}
                    onSelect={(organization) => choose(organization.id)}
                />
            </PopoverContent>
        </Popover>
    );
}
