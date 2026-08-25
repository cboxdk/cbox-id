import { Command } from 'cmdk';
import { type ReactNode, useMemo, useState } from 'react';
import { cn } from '@/lib/cn';
import { useFieldControl } from './Field';
import { Icon } from './Icon';
import { Popover, PopoverContent, PopoverTrigger } from './Popover';

export interface ComboboxOption<T extends string> {
    value: T;
    label: string;
    /** Extra words the search should match — an id, an email, a domain. */
    keywords?: string[];
    hint?: ReactNode;
    disabled?: boolean;
}

export interface ComboboxProps<T extends string> {
    value: T | undefined;
    onValueChange: (value: T) => void;
    options: ComboboxOption<T>[];
    placeholder?: string;
    /** The prompt in the search box. */
    searchPlaceholder?: string;
    emptyMessage?: string;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
}

/**
 * A choice of one from a list too long to scan.
 *
 * The trigger is a button showing the current value, and opening it puts focus in a
 * search box — so the whole control is operable from the keyboard without ever reaching
 * for the mouse, which is how anybody picking an organization out of four hundred is
 * actually going to use it.
 */
export function Combobox<T extends string>({
    value,
    onValueChange,
    options,
    placeholder = 'Select…',
    searchPlaceholder = 'Search…',
    emptyMessage = 'Nothing matches that.',
    disabled,
    className,
    ...props
}: ComboboxProps<T>) {
    const [open, setOpen] = useState(false);
    const field = useFieldControl();
    const selected = options.find((option) => option.value === value);

    // The value is always searchable alongside whatever the caller added, so typing an id
    // finds the row named after it. Built once rather than per render, because a list long
    // enough to need a combobox is long enough for it to matter.
    const searchable = useMemo(
        () =>
            options.map((option) => ({
                option,
                keywords: [option.value, ...(option.keywords ?? [])],
            })),
        [options],
    );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            {/*
                A disclosure button, NOT the combobox. The real combobox is cmdk's search
                input inside the popover: it is the thing with a text value, and it
                carries `role="combobox"` with the `aria-controls` that role requires.
                Declaring the role out here too produced a second combobox that controlled
                nothing. Radix's trigger already sets `aria-expanded` and `aria-haspopup`.
            */}
            <PopoverTrigger
                className={cn('select cbx-select', className)}
                disabled={disabled}
                {...field}
                {...props}
            >
                <span className={cn('cbx-select-value', selected === undefined && 'is-placeholder')}>
                    {selected?.label ?? placeholder}
                </span>
            </PopoverTrigger>

            <PopoverContent className="cbx-combobox">
                <Command
                    filter={(itemValue, search, keywords) => {
                        const haystack = [itemValue, ...(keywords ?? [])].join(' ').toLowerCase();

                        return haystack.includes(search.toLowerCase()) ? 1 : 0;
                    }}
                >
                    <div className="cbx-combobox-search">
                        <Icon name="search" className="w-4 h-4 shrink-0" />
                        <Command.Input placeholder={searchPlaceholder} />
                    </div>

                    <Command.List>
                        <Command.Empty className="cbx-combobox-empty">{emptyMessage}</Command.Empty>

                        {searchable.map(({ option, keywords }) => (
                            <Command.Item
                                key={option.value}
                                value={option.label}
                                keywords={keywords}
                                disabled={option.disabled}
                                className="cbx-menuitem"
                                onSelect={() => {
                                    onValueChange(option.value);
                                    setOpen(false);
                                }}
                            >
                                <span style={{ minWidth: 0, flex: 1 }}>
                                    {option.label}
                                    {option.hint !== undefined && (
                                        <span className="cbx-menuitem-hint">{option.hint}</span>
                                    )}
                                </span>
                                {option.value === value && (
                                    <Icon name="check" className="w-4 h-4 shrink-0" />
                                )}
                            </Command.Item>
                        ))}
                    </Command.List>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
