import { Select as Primitive } from 'radix-ui';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useFieldControl } from './Field';
import { Icon } from './Icon';

export interface SelectOption<T extends string> {
    value: T;
    label: ReactNode;
    hint?: ReactNode;
    disabled?: boolean;
}

export interface SelectProps<T extends string> {
    value: T | undefined;
    onValueChange: (value: T) => void;
    options: SelectOption<T>[];
    placeholder?: string;
    disabled?: boolean;
    name?: string;
    className?: string;
    'aria-label'?: string;
}

/**
 * A choice of one from a short, known list.
 *
 * NOT a native `<select>` — the design system forbids it, and the reason is not taste:
 * the OS control cannot be themed (so it is the one element on the page that ignores a
 * customer's brand), cannot show a hint under an option, and renders as a full-screen
 * wheel on iOS that hides the form the person was filling in.
 *
 * Radix keeps what the native control was right about: type-ahead, Home/End, arrow keys,
 * the value announced as a listbox selection, and the popup positioned so the current
 * value sits under the cursor.
 *
 * For a list long enough to need searching, use `<Combobox>` instead.
 */
export function Select<T extends string>({
    value,
    onValueChange,
    options,
    placeholder = 'Select…',
    disabled,
    name,
    className,
    ...props
}: SelectProps<T>) {
    const field = useFieldControl();

    return (
        <Primitive.Root
            value={value}
            onValueChange={(next) => onValueChange(next as T)}
            disabled={disabled}
            name={name}
        >
            <Primitive.Trigger className={cn('select cbx-select', className)} {...field} {...props}>
                <Primitive.Value placeholder={placeholder} />
            </Primitive.Trigger>

            <Primitive.Portal>
                <Primitive.Content position="popper" sideOffset={6} className="cbx-menu">
                    <Primitive.Viewport>
                        {options.map((option) => (
                            <Primitive.Item
                                key={option.value}
                                value={option.value}
                                disabled={option.disabled}
                                className="cbx-menuitem"
                            >
                                <span style={{ minWidth: 0, flex: 1 }}>
                                    <Primitive.ItemText>{option.label}</Primitive.ItemText>
                                    {option.hint !== undefined && (
                                        <span className="cbx-menuitem-hint">{option.hint}</span>
                                    )}
                                </span>
                                <Primitive.ItemIndicator>
                                    <Icon name="check" className="w-4 h-4" />
                                </Primitive.ItemIndicator>
                            </Primitive.Item>
                        ))}
                    </Primitive.Viewport>
                </Primitive.Content>
            </Primitive.Portal>
        </Primitive.Root>
    );
}
