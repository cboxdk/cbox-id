import { RadioGroup as Primitive } from 'radix-ui';
import type { ReactNode } from 'react';
import { useId } from 'react';
import { cn } from '@/lib/cn';

export interface RadioOption<T extends string> {
    value: T;
    label: ReactNode;
    hint?: ReactNode;
    disabled?: boolean;
}

export interface RadioGroupProps<T extends string> {
    value: T;
    onValueChange: (value: T) => void;
    options: RadioOption<T>[];
    name?: string;
    /** The question the options answer. Required — a radio group without one is a list. */
    label: ReactNode;
    className?: string;
}

/**
 * A choice of one, where seeing all the options at once matters.
 *
 * Arrow keys move between options and Tab leaves the group — the roving-tabindex
 * behaviour a radio group is supposed to have and a row of styled buttons never does.
 */
export function RadioGroup<T extends string>({
    value,
    onValueChange,
    options,
    name,
    label,
    className,
}: RadioGroupProps<T>) {
    const id = useId();

    return (
        <div className={cn(className)}>
            <span className="label" id={`${id}-label`}>
                {label}
            </span>
            <Primitive.Root
                className="cbx-radios"
                value={value}
                onValueChange={(next) => onValueChange(next as T)}
                name={name}
                aria-labelledby={`${id}-label`}
            >
                {options.map((option) => (
                    <label key={option.value} className="cbx-radio">
                        <Primitive.Item
                            value={option.value}
                            disabled={option.disabled}
                            className="cbx-radio-box"
                        >
                            <Primitive.Indicator className="cbx-radio-dot" />
                        </Primitive.Item>
                        <span style={{ minWidth: 0 }}>
                            <span className="cbx-radio-label">{option.label}</span>
                            {option.hint !== undefined && (
                                <span className="cbx-radio-hint">{option.hint}</span>
                            )}
                        </span>
                    </label>
                ))}
            </Primitive.Root>
        </div>
    );
}
