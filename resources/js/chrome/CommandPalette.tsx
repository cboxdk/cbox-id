import { router } from '@inertiajs/react';
import { Command } from 'cmdk';
import { Dialog as Primitive } from 'radix-ui';
import { useEffect, useMemo, useState } from 'react';
import { toggleTheme } from '@/lib/theme';
import type { NavArea } from '@/types';
import { Icon } from '@/ui';

export interface CommandPaletteProps {
    areas: NavArea[];
}

/**
 * ⌘K — go anywhere in the console without leaving the keyboard.
 *
 * DELIBERATELY NAVIGATION ONLY. The Volt console had a ⌘K box and it was removed rather
 * than left in place, because it searched nothing: a dead affordance teaches people the
 * shortcut does not work, and they stop pressing it. Everything offered here is backed by
 * the same nav registry the rail draws from — so it lists exactly the pages this person
 * can actually reach, on this plane, with these features enabled, and never a page that
 * would 404 them.
 *
 * Searching ENTITIES — an organization by name, a user by email — is the obvious next
 * thing and is not here, because it needs an endpoint that scopes and authorizes the
 * search. That is a feature, not a widget, and shipping the widget first is how the last
 * one ended up being deleted.
 */
export function CommandPalette({ areas }: CommandPaletteProps) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent): void => {
            if (event.key.toLowerCase() !== 'k' || !(event.metaKey || event.ctrlKey)) {
                return;
            }

            event.preventDefault();
            setOpen((current) => !current);
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    const entries = useMemo(
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

    return (
        <>
            <button
                type="button"
                className="cbx-search hidden md:inline-flex"
                onClick={() => setOpen(true)}
                aria-label="Search the console"
            >
                <Icon name="search" className="w-3.5 h-3.5 shrink-0" />
                <span className="label">Go to…</span>
                <kbd>⌘K</kbd>
            </button>

            <Primitive.Root open={open} onOpenChange={setOpen}>
                <Primitive.Portal>
                    <Primitive.Overlay className="cbx-overlay" />
                    <Primitive.Content className="cbx-palette" aria-describedby={undefined}>
                        <Primitive.Title className="sr-only">Go to a page</Primitive.Title>

                        <Command loop>
                            <div className="cbx-combobox-search">
                                <Icon name="search" className="w-4 h-4 shrink-0" />
                                <Command.Input placeholder="Go to…" />
                            </div>

                            <Command.List>
                                <Command.Empty className="cbx-combobox-empty">
                                    No page matches that.
                                </Command.Empty>

                                {entries.map((entry) => (
                                    <Command.Item
                                        key={entry.key}
                                        value={`${entry.area} ${entry.label}`}
                                        className="cbx-menuitem"
                                        onSelect={() => {
                                            setOpen(false);
                                            router.visit(entry.href);
                                        }}
                                    >
                                        <Icon name={entry.icon} className="w-4 h-4 shrink-0" />
                                        <span className="min-w-0 flex-1">{entry.label}</span>
                                        <span
                                            className="shrink-0"
                                            style={{
                                                fontSize: '11px',
                                                color: 'var(--muted-foreground)',
                                            }}
                                        >
                                            {entry.area}
                                        </span>
                                    </Command.Item>
                                ))}

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
