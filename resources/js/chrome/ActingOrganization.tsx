import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ActingOrganization as ActingOrganizationState } from '@/types';
import { Icon, Input, Popover, PopoverContent, PopoverTrigger, Spinner } from '@/ui';

interface Option {
    id: string;
    name: string;
}

/**
 * WHICH TENANT THE ENVIRONMENT CONSOLE IS ACTING ON.
 *
 * Every page here is scoped to it, so it belongs in the chrome — repeated as a field on
 * each page is how the two consoles once came to disagree about which organization a form
 * was writing to.
 *
 * A SEARCH, NOT A LIST, and that is the whole design. The control this replaces rendered
 * every organization in the environment into the chrome of every page: fine for the seven
 * an engineer has locally, and an outage for the customer with four thousand. The set is
 * unbounded; the control is not.
 *
 * "All organizations" is a real row at the top rather than a link tucked under the list,
 * because it is where an administrator starts and it was once the one destination this
 * control could not reach.
 */
export function ActingOrganization({ acting }: { acting: ActingOrganizationState }) {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [options, setOptions] = useState<Option[]>([]);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        const timer = setTimeout(() => {
            setLoading(true);

            fetch(`${acting.searchUrl}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: { results: Option[]; total: number } | null) => {
                    if (body !== null) {
                        setOptions(body.results);
                        setTotal(body.total);
                    }
                })
                .catch(() => {
                    // An aborted request is the ordinary case here — the next keystroke
                    // cancels the one in flight — and a failed one leaves the last answer
                    // on screen rather than blanking a list somebody is reading.
                })
                .finally(() => setLoading(false));
            // Debounced, because this fires on every keystroke and the query behind it is a
            // leading-wildcard search over every tenant in the environment.
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [open, term, acting.searchUrl]);

    const choose = (id: string): void => {
        setOpen(false);
        router.post(acting.chooseUrl, { organization: id }, { preserveScroll: true });
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setTerm('');
                    // Focus lands on the search rather than the first row: this control is
                    // open in order to be typed into.
                    window.setTimeout(() => searchRef.current?.focus(), 0);
                }
            }}
        >
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="cbx-switcher-item flex items-center gap-1.5 rounded-lg px-1.5 py-1 min-w-0"
                    aria-haspopup="dialog"
                >
                    {/*
                        "Choose organization" read as an instruction — as though the console
                        could not work until you picked one — when unselected is the ordinary
                        state and means the whole environment. It says what you are looking
                        at now.
                    */}
                    <span
                        className="truncate"
                        style={
                            acting.id === null
                                ? { fontStyle: 'italic', color: 'var(--muted-foreground)' }
                                : { fontWeight: 600 }
                        }
                    >
                        {acting.id === null
                            ? 'All organizations'
                            : (acting.name ?? 'Unknown organization')}
                    </span>
                    <Icon
                        name="chevron"
                        className="w-4 h-4 shrink-0"
                        style={{ color: 'var(--muted-foreground)' }}
                        aria-hidden="true"
                    />
                </button>
            </PopoverTrigger>

            <PopoverContent align="start" style={{ width: '288px', padding: '6px' }}>
                <p className="cbx-nav-group" style={{ padding: '6px 10px 4px' }}>
                    Act on behalf of
                </p>

                <button
                    type="button"
                    className="cbx-row w-full text-start"
                    style={{
                        padding: '8px 10px',
                        borderRadius: '6px',
                        gap: '10px',
                        background: acting.id === null ? 'var(--secondary)' : undefined,
                    }}
                    onClick={() => {
                        setOpen(false);
                        router.delete(acting.clearUrl, { preserveScroll: true });
                    }}
                >
                    <span className="min-w-0 flex-1 truncate">
                        All organizations
                        <span
                            className="block"
                            style={{ fontSize: '11px', color: 'var(--muted-foreground)' }}
                        >
                            The whole environment, unfiltered
                        </span>
                    </span>
                    {acting.id === null && (
                        <Icon
                            name="check"
                            className="w-4 h-4 shrink-0"
                            style={{ color: 'var(--primary)' }}
                            aria-hidden="true"
                        />
                    )}
                </button>

                <div style={{ padding: '0 6px 6px' }}>
                    <Input
                        ref={searchRef}
                        type="search"
                        placeholder="Search organizations…"
                        aria-label="Search organizations"
                        autoComplete="off"
                        spellCheck={false}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                </div>

                <div className="max-h-64 overflow-y-auto space-y-0.5">
                    {options.map((option) => (
                        <button
                            key={option.id}
                            type="button"
                            className="cbx-row w-full text-start"
                            style={{
                                padding: '8px 10px',
                                borderRadius: '6px',
                                gap: '10px',
                                background:
                                    option.id === acting.id ? 'var(--secondary)' : undefined,
                            }}
                            onClick={() => choose(option.id)}
                        >
                            <span className="min-w-0 flex-1 truncate">{option.name}</span>
                            {option.id === acting.id && (
                                <Icon
                                    name="check"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--primary)' }}
                                    aria-hidden="true"
                                />
                            )}
                        </button>
                    ))}
                </div>

                {/*
                    WCAG 4.1.3. The list is replaced on a debounced keystroke with no focus
                    change, so this line is the only thing that can tell a screen-reader user
                    their search narrowed to nothing — or that there are 499 more behind the
                    eight shown.
                */}
                <output
                    className="flex items-center gap-1.5"
                    style={{
                        display: 'flex',
                        padding: '6px 10px 4px',
                        fontSize: '11px',
                        color: 'var(--muted-foreground)',
                    }}
                >
                    {loading && <Spinner className="w-3 h-3" />}
                    {loading
                        ? 'Searching…'
                        : options.length === 0
                          ? term.trim() === ''
                              ? 'This environment has no organizations yet.'
                              : `No organization matches “${term.trim()}”.`
                          : total > options.length
                            ? `Showing ${options.length} of ${total.toLocaleString()} — type to narrow.`
                            : `${options.length} ${options.length === 1 ? 'organization' : 'organizations'}.`}
                </output>
            </PopoverContent>
        </Popover>
    );
}
