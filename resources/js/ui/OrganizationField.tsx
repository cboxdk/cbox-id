import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText, choice } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    return (
        // WCAG 4.1.3. The list is replaced on a debounced keystroke with no focus change, so
        // this line is the only thing that can tell a screen-reader user their search
        // narrowed to nothing — or that there are 499 more behind the eight shown.
        <output className="cbx-lookup-status">
            {loading && <Spinner className="w-3 h-3" />}
            {loading
                ? consoleText('console.searching_c31723ab')
                : count === 0
                  ? term.trim() === ''
                      ? consoleText('console.this_environment_has_no_organizations_yet_e3dd0861')
                      : consoleFinalText('console.no_organization_matches_part1_022c2190', {
                            part1: term.trim(),
                        })
                  : total > count
                    ? consoleFinalText('console.showing_part1_of_part2_type_to_narrow_54c3f471', {
                          part1: count,
                          part2: total.toLocaleString(consoleLocale),
                      })
                    : choice('console.count_organizations', count)}
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
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [term, setTerm] = useState('');
    const { results, total, loading } = useOrganizationLookup(lookupHref, open, term);

    return (
        // The server already filtered: cmdk only keeps the keyboard navigation.
        <Command shouldFilter={false}>
            <div className="cbx-combobox-search">
                <Icon name="search" className="w-4 h-4 shrink-0" />
                <Command.Input
                    placeholder={consoleUiText('console.search_organizations_0156eb39')}
                    aria-label={consoleT('console.search_organizations_c1b2693a')}
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
    placeholder: suppliedPlaceholder,
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
    const { t: consoleFinalText } = useConsoleTranslator();
    const placeholder =
        suppliedPlaceholder ?? consoleFinalText('console.find_an_organization_eb2523db');
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                    {value?.name ??
                        (allowsEnvironment
                            ? consoleText('console.the_whole_environment_9ad4afa7')
                            : placeholder)}
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
                                    {consoleRich('console.the_whole_environment_part1_6c96bfa7', {
                                        part1: (
                                            <span className="cbx-menuitem-hint">
                                                {consoleT(
                                                    'console.every_organization_here_and_none_in_particular_ad3f49b0',
                                                )}
                                            </span>
                                        ),
                                    })}
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
    hint: suppliedHint,
    label: suppliedLabel,
}: {
    picker: OrganizationPicker;
    onChange: (organizationId: string) => void;
    error?: string;
    hint?: ReactNode;
    /** The question, where "for which organization" would undersell "the whole environment". */
    label?: string;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();
    const hint =
        suppliedHint ??
        consoleFinalText(
            'console.what_you_create_here_belongs_to_this_organization_and_t_fc8e15c4',
        );
    const label = suppliedLabel ?? consoleFinalText('console.for_which_organization_aaca2bbd');
    const { t: consoleText } = useConsoleTranslator();
    const [value, setValue] = useState<OrganizationOption | null>(picker.selected);

    return (
        <Field
            label={label}
            hint={
                picker.locked
                    ? consoleText('console.opened_from_this_organization_s_page_08622d45')
                    : hint
            }
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
