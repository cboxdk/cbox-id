import { Command } from 'cmdk';
import { type ReactNode, useEffect, useState } from 'react';
import { cn } from '@/lib/cn';
import type { OrganizationOption, OrganizationPicker } from '@/types';
import { Field, useFieldControl } from './Field';
import { Icon } from './Icon';
import { Popover, PopoverContent, PopoverTrigger } from './Popover';
import { Spinner } from './Spinner';

/** What `GET /admin/lookup/organizations?q=` answers. */
interface LookupAnswer {
    results: OrganizationOption[];
    total: number;
}

/**
 * The organizations of this environment matching what was typed — a page at a time.
 *
 * A SEARCH, NOT A LIST: the set is unbounded (the shape this product is sold into is a
 * customer with thousands of organizations), so nothing ever renders all of them. Debounced,
 * because it fires on every keystroke and the query behind it is a leading-wildcard search.
 */
export function useOrganizationLookup(lookupHref: string, open: boolean, term: string) {
    const [answer, setAnswer] = useState<LookupAnswer>({ results: [], total: 0 });
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        const timer = setTimeout(() => {
            setLoading(true);

            fetch(`${lookupHref}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: LookupAnswer | null) => {
                    if (body !== null) {
                        setAnswer(body);
                    }
                })
                .catch(() => {
                    // An aborted request is the ordinary case — the next keystroke cancels
                    // the one in flight — and a failed one leaves the last answer on screen
                    // rather than blanking a list somebody is reading.
                })
                .finally(() => setLoading(false));
        }, 250);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [open, term, lookupHref]);

    return { ...answer, loading };
}

/** The line under the results: what the search found, said for a screen reader too. */
function LookupStatus({
    loading,
    count,
    total,
    term,
}: {
    loading: boolean;
    count: number;
    total: number;
    term: string;
}) {
    return (
        // WCAG 4.1.3. The list is replaced on a debounced keystroke with no focus change, so
        // this line is the only thing that can tell a screen-reader user their search
        // narrowed to nothing — or that there are 499 more behind the eight shown.
        <output className="cbx-lookup-status">
            {loading && <Spinner className="w-3 h-3" />}
            {loading
                ? 'Searching…'
                : count === 0
                  ? term.trim() === ''
                      ? 'This environment has no organizations yet.'
                      : `No organization matches “${term.trim()}”.`
                  : total > count
                    ? `Showing ${count} of ${total.toLocaleString()} — type to narrow.`
                    : `${count} ${count === 1 ? 'organization' : 'organizations'}.`}
        </output>
    );
}

/**
 * The searchable list of organizations, inside a popover someone already opened — shared by
 * the form field below and the Organization filter chip.
 */
export function OrganizationLookupList({
    lookupHref,
    open,
    selectedId,
    onSelect,
    leading,
}: {
    lookupHref: string;
    open: boolean;
    selectedId: string | null;
    onSelect: (organization: OrganizationOption) => void;
    /** Rows drawn above the results — "The whole environment" where that is an answer. */
    leading?: ReactNode;
}) {
    const [term, setTerm] = useState('');
    const { results, total, loading } = useOrganizationLookup(lookupHref, open, term);

    return (
        // The server already filtered: cmdk only keeps the keyboard navigation.
        <Command shouldFilter={false}>
            <div className="cbx-combobox-search">
                <Icon name="search" className="w-4 h-4 shrink-0" />
                <Command.Input
                    placeholder="Search organizations…"
                    aria-label="Search organizations"
                    value={term}
                    onValueChange={setTerm}
                />
            </div>

            <Command.List>
                {leading}
                {results.map((organization) => (
                    <Command.Item
                        key={organization.id}
                        value={organization.id}
                        className="cbx-menuitem"
                        onSelect={() => onSelect(organization)}
                    >
                        <span style={{ minWidth: 0, flex: 1 }}>{organization.name}</span>
                        {organization.id === selectedId && (
                            <Icon name="check" className="w-4 h-4 shrink-0" />
                        )}
                    </Command.Item>
                ))}
            </Command.List>

            <LookupStatus loading={loading} count={results.length} total={total} term={term} />
        </Command>
    );
}

/**
 * "FOR WHICH ORGANIZATION?" — the field a create form on the environment console carries when
 * what it creates belongs to one organization.
 *
 * It replaced a sentence that sent somebody to the console header to pick an organization
 * first, away from a form they had half filled in. LOCKED when the form was opened from the
 * organization's own page: the person already said which one. The server checks the id
 * against this environment either way, so the lock is a courtesy, not a guard.
 */
export function OrganizationField({
    lookupHref,
    value,
    onChange,
    locked = false,
    allowsEnvironment = false,
    placeholder = 'Find an organization…',
    disabled,
}: {
    lookupHref: string;
    value: OrganizationOption | null;
    onChange: (organization: OrganizationOption | null) => void;
    locked?: boolean;
    /** Offer "The whole environment" as an answer, for things the environment may own. */
    allowsEnvironment?: boolean;
    placeholder?: string;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const field = useFieldControl();

    if (locked && value !== null) {
        return (
            <div className="select cbx-select" aria-readonly="true" {...field}>
                <span className="cbx-select-value">{value.name}</span>
                <Icon name="lock" className="w-4 h-4 shrink-0" aria-hidden="true" />
            </div>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger className={cn('select cbx-select')} disabled={disabled} {...field}>
                <span className={cn('cbx-select-value', value === null && 'is-placeholder')}>
                    {value?.name ?? (allowsEnvironment ? 'The whole environment' : placeholder)}
                </span>
            </PopoverTrigger>

            <PopoverContent className="cbx-combobox">
                <OrganizationLookupList
                    lookupHref={lookupHref}
                    open={open}
                    selectedId={value?.id ?? null}
                    onSelect={(organization) => {
                        onChange(organization);
                        setOpen(false);
                    }}
                    leading={
                        allowsEnvironment ? (
                            <Command.Item
                                value="__environment__"
                                className="cbx-menuitem"
                                onSelect={() => {
                                    onChange(null);
                                    setOpen(false);
                                }}
                            >
                                <span style={{ minWidth: 0, flex: 1 }}>
                                    The whole environment
                                    <span className="cbx-menuitem-hint">
                                        Every organization here, and none in particular
                                    </span>
                                </span>
                                {value === null && (
                                    <Icon name="check" className="w-4 h-4 shrink-0" />
                                )}
                            </Command.Item>
                        ) : undefined
                    }
                />
            </PopoverContent>
        </Popover>
    );
}

/**
 * The whole "For which organization?" row of a create form: the label, the field and its
 * error, from what the server said about it (`App\Http\Props\Shared\OrganizationPickerProps`).
 * The form keeps only the id (`organization`), which is what is posted and checked.
 */
export function OrganizationPickerField({
    picker,
    onChange,
    error,
    hint = 'What you create here belongs to this organization, and to no other.',
}: {
    picker: OrganizationPicker;
    onChange: (organizationId: string) => void;
    error?: string;
    hint?: ReactNode;
}) {
    const [value, setValue] = useState<OrganizationOption | null>(picker.selected);

    return (
        <Field
            label="For which organization?"
            hint={picker.locked ? 'Opened from this organization’s page.' : hint}
            error={error}
        >
            <OrganizationField
                lookupHref={picker.lookupHref}
                value={value}
                locked={picker.locked}
                allowsEnvironment={picker.allowsEnvironment}
                onChange={(organization) => {
                    setValue(organization);
                    onChange(organization?.id ?? '');
                }}
            />
        </Field>
    );
}
