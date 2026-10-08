import { router, usePage } from '@inertiajs/react';
import { Command } from 'cmdk';
import { Dialog as Primitive } from 'radix-ui';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    MIN_QUERY,
    type PaletteAction,
    readRecent,
    rememberRecent,
    type SearchItem,
    type SearchResponse,
    searchHref,
    searchQuery,
    searchUrl,
} from '@/lib/consoleSearch';
import { toggleTheme } from '@/lib/theme';
import type { NavArea, SharedProps } from '@/types';
import { Icon, type IconName } from '@/ui';

export interface CommandPaletteProps {
    areas: NavArea[];
}

/** How long typing has to pause before the server is asked. */
const DEBOUNCE_MS = 200;

const KIND_ICON: Record<SearchItem['kind'], IconName> = {
    user: 'members',
    organization: 'building',
    app: 'code',
    key: 'key',
    audit: 'audit',
};

function browserStorage(): Storage | undefined {
    try {
        return window.localStorage;
    } catch {
        return undefined;
    }
}

/**
 * ⌘K — go anywhere in the console, and find anything in it, without leaving the keyboard.
 *
 * FOUR KINDS OF ANSWER, in the order a person reaches for them:
 *  - RECENT: the records this browser opened from here last, before anything is typed;
 *  - RESULTS: users, organizations, apps, keys and audit entries, from the server — always
 *    within this environment, and on the organization console within that organization
 *    (`App\Platform\Console\ConsoleSearch`). A pasted id that names one record is offered
 *    first, so Enter opens it;
 *  - GO TO: every page on the rail, from the same nav registry the rail draws — never a page
 *    that would 404;
 *  - ACTIONS: "Create app", "Rotate app secret…" — deep links to the forms, read from the
 *    action registry.
 *
 * The search the Volt console had was removed because it searched nothing: a dead
 * affordance teaches people the shortcut does not work. This one asks an endpoint that
 * scopes and authorizes what it finds, and only on a console that has one.
 *
 * Accessible as cmdk makes it: the input is a combobox owning a listbox, the active option
 * is announced, arrows and Enter drive it. On a phone the button is an icon in the top bar.
 */
export function CommandPalette({ areas }: CommandPaletteProps) {
    const { shell } = usePage<SharedProps>().props;
    const endpoint = searchHref(shell?.altitude, shell?.platformMode ?? false);
    const scope = typeof window === 'undefined' ? '' : window.location.host;

    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [response, setResponse] = useState<SearchResponse | null>(null);
    const [actions, setActions] = useState<PaletteAction[]>([]);
    const [loading, setLoading] = useState(false);
    const [recent, setRecent] = useState<SearchItem[]>([]);
    // The highlighted option, held here so an answer that arrives after the keystroke can
    // put the highlight on it — otherwise Enter on a pasted id selects nothing.
    const [selected, setSelected] = useState('');
    const inflight = useRef<AbortController | null>(null);
    // The shortcut is bound once; it reads the current state through these.
    const openRef = useRef(open);
    const showRef = useRef<() => void>(() => undefined);

    useEffect(() => {
        openRef.current = open;
    }, [open]);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent): void => {
            if (event.key.toLowerCase() !== 'k' || !(event.metaKey || event.ctrlKey)) {
                return;
            }

            event.preventDefault();

            if (openRef.current) {
                setOpen(false);
            } else {
                showRef.current();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    const ask = useCallback(
        async (term: string): Promise<void> => {
            if (endpoint === null) {
                return;
            }

            inflight.current?.abort();
            const controller = new AbortController();
            inflight.current = controller;
            setLoading(true);

            try {
                const answer = await fetch(searchUrl(endpoint, term), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!answer.ok) {
                    return;
                }

                const body = (await answer.json()) as SearchResponse;

                setActions(body.actions);
                setResponse(term === '' ? null : body);

                const first = body.jump ?? body.groups[0]?.items[0];

                if (term !== '' && first !== undefined) {
                    setSelected(itemValue(first, body.jump !== null ? 'Open' : undefined));
                }
            } catch {
                // Aborted by the next keystroke, or offline: the last answer stands.
            } finally {
                if (inflight.current === controller) {
                    setLoading(false);
                }
            }
        },
        [endpoint],
    );

    // Opening reads what this browser remembers and asks once for the actions — from the
    // event that opened it, not an effect watching `open`.
    const show = useCallback((): void => {
        setRecent(readRecent(browserStorage(), scope));
        setOpen(true);
        void ask('');
    }, [ask, scope]);

    useEffect(() => {
        showRef.current = show;
    }, [show]);

    // Typing asks the server once it pauses — a query per keystroke would race itself.
    useEffect(() => {
        const term = searchQuery(query);

        if (!open || term.length < MIN_QUERY) {
            return;
        }

        const timer = setTimeout(() => void ask(term), DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [query, open, ask]);

    const go = (href: string, remember?: SearchItem): void => {
        if (remember !== undefined) {
            setRecent(rememberRecent(browserStorage(), scope, remember));
        }

        setOpen(false);
        setQuery('');
        router.visit(href);
    };

    const pages = useMemo(
        () =>
            areas.flatMap((area) =>
                area.pages.map((page) => ({
                    key: page.route,
                    href: page.href,
                    label: page.label,
                    area: area.label,
                    icon: area.icon,
                })),
            ),
        [areas],
    );

    const typed = searchQuery(query);
    const searching = endpoint !== null && typed.length >= MIN_QUERY;
    // Only the answer to what is typed NOW — a slower, older answer is not shown under it.
    const shown = searching && response?.query === typed ? response : null;

    return (
        <>
            <button
                type="button"
                className="cbx-search"
                onClick={show}
                aria-label="Search the console"
                aria-keyshortcuts="Meta+K Control+K"
            >
                <Icon name="search" className="w-3.5 h-3.5 shrink-0" />
                <span className="label hidden md:inline">
                    {endpoint === null ? 'Go to…' : 'Search…'}
                </span>
                <kbd className="hidden md:inline">⌘K</kbd>
            </button>

            <Primitive.Root
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);

                    if (!next) {
                        setQuery('');
                    }
                }}
            >
                <Primitive.Portal>
                    <Primitive.Overlay className="cbx-overlay" />
                    <Primitive.Content className="cbx-palette" aria-describedby={undefined}>
                        <Primitive.Title className="sr-only">
                            {endpoint === null ? 'Go to a page' : 'Search the console'}
                        </Primitive.Title>

                        <Command
                            loop
                            label="Search the console"
                            value={selected}
                            onValueChange={setSelected}
                        >
                            <div className="cbx-combobox-search">
                                <Icon name="search" className="w-4 h-4 shrink-0" />
                                <Command.Input
                                    value={query}
                                    onValueChange={setQuery}
                                    placeholder={
                                        endpoint === null
                                            ? 'Go to…'
                                            : 'Search users, organizations, apps, keys or paste an id…'
                                    }
                                />
                            </div>

                            <Command.List>
                                {loading && searching && (
                                    <Command.Loading>
                                        <span className="cbx-combobox-empty">Searching…</span>
                                    </Command.Loading>
                                )}

                                <Command.Empty className="cbx-combobox-empty">
                                    {searching ? 'Nothing matches that.' : 'No page matches that.'}
                                </Command.Empty>

                                {shown?.jump != null && (
                                    <Command.Group forceMount heading="Jump to">
                                        <ResultItem
                                            item={shown.jump}
                                            prefix="Open"
                                            onSelect={() =>
                                                shown.jump !== null &&
                                                go(shown.jump.href, shown.jump)
                                            }
                                        />
                                    </Command.Group>
                                )}

                                {query === '' && recent.length > 0 && (
                                    <Command.Group forceMount heading="Recent">
                                        {recent.map((item) => (
                                            <ResultItem
                                                key={`recent-${item.href}`}
                                                item={item}
                                                onSelect={() => go(item.href, item)}
                                            />
                                        ))}
                                    </Command.Group>
                                )}

                                {shown?.groups.map((group) => (
                                    <Command.Group forceMount key={group.key} heading={group.label}>
                                        {group.items.map((item) => (
                                            <ResultItem
                                                key={`${group.key}-${item.id}`}
                                                item={item}
                                                onSelect={() => go(item.href, item)}
                                            />
                                        ))}
                                    </Command.Group>
                                ))}

                                <Command.Group heading="Go to">
                                    {pages.map((entry) => (
                                        <Command.Item
                                            key={entry.key}
                                            value={`go ${entry.area} ${entry.label}`}
                                            className="cbx-menuitem"
                                            onSelect={() => go(entry.href)}
                                        >
                                            <Icon name={entry.icon} className="w-4 h-4 shrink-0" />
                                            <span className="min-w-0 flex-1">{entry.label}</span>
                                            <Hint>{entry.area}</Hint>
                                        </Command.Item>
                                    ))}
                                </Command.Group>

                                {actions.length > 0 && (
                                    <Command.Group heading="Actions">
                                        {actions.map((action) => (
                                            <Command.Item
                                                key={action.name}
                                                value={`action ${action.label} ${action.name}`}
                                                className="cbx-menuitem"
                                                onSelect={() => go(action.href)}
                                            >
                                                <Icon name="plus" className="w-4 h-4 shrink-0" />
                                                <span className="min-w-0 flex-1">
                                                    {action.label}
                                                </span>
                                                <Hint>{action.name}</Hint>
                                            </Command.Item>
                                        ))}
                                    </Command.Group>
                                )}

                                <Command.Item
                                    value="Toggle theme appearance dark light"
                                    className="cbx-menuitem"
                                    onSelect={() => {
                                        setOpen(false);
                                        toggleTheme();
                                    }}
                                >
                                    <Icon name="moon" className="w-4 h-4 shrink-0" />
                                    <span className="min-w-0 flex-1">Toggle theme</span>
                                </Command.Item>
                            </Command.List>
                        </Command>
                    </Primitive.Content>
                </Primitive.Portal>
            </Primitive.Root>
        </>
    );
}

/** The option's value — unique per record and per group, so the highlight can name it. */
function itemValue(item: SearchItem, prefix?: string): string {
    return `${prefix ?? item.kind} ${item.kind} ${item.id} ${item.title}`;
}

/**
 * A record from the server. FORCE-MOUNTED: the server already matched it — by email, id or
 * client id, none of which need appear in its title — so cmdk's own fuzzy filter must not
 * hide it again.
 */
function ResultItem({
    item,
    prefix,
    onSelect,
}: {
    item: SearchItem;
    prefix?: string;
    onSelect: () => void;
}) {
    return (
        <Command.Item
            forceMount
            value={itemValue(item, prefix)}
            className="cbx-menuitem"
            onSelect={onSelect}
        >
            <Icon name={KIND_ICON[item.kind] ?? 'search'} className="w-4 h-4 shrink-0" />
            <span className="min-w-0 flex-1 truncate">
                {prefix !== undefined && `${prefix} `}
                {item.title}
            </span>
            {item.subtitle !== null && <Hint mono>{item.subtitle}</Hint>}
        </Command.Item>
    );
}

function Hint({ children, mono = false }: { children: React.ReactNode; mono?: boolean }) {
    return (
        <span
            className={mono ? 'shrink-0 mono truncate' : 'shrink-0'}
            style={{ fontSize: '11px', color: 'var(--muted-foreground)', maxWidth: '45%' }}
        >
            {children}
        </span>
    );
}
