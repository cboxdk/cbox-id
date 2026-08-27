import { Checkbox as Primitive } from 'radix-ui';
import type { ReactNode } from 'react';
import { useId } from 'react';
import { cn } from '@/lib/cn';
import { Icon } from './Icon';

export interface CheckboxProps {
    checked: boolean | 'indeterminate';
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    name?: string;
    value?: string;
    /** The label sits beside the box and is clickable — never a bare box in a row. */
    label: ReactNode;
    hint?: ReactNode;
    className?: string;
}

export function Checkbox({
    label,
    hint,
    className,
    checked,
    onCheckedChange,
    ...props
}: CheckboxProps) {
    const id = useId();
    const hintId = `${id}-hint`;

    return (
        <div className={cn('cbx-check', className)}>
            <Primitive.Root
                id={id}
                className="cbx-check-box"
                checked={checked}
                onCheckedChange={(next) => onCheckedChange(next === true)}
                aria-describedby={hint !== undefined ? hintId : undefined}
                {...props}
            >
                <Primitive.Indicator className="cbx-check-mark">
                    <Icon
                        name={checked === 'indeterminate' ? 'chevron' : 'check'}
                        className="w-3 h-3"
                    />
                </Primitive.Indicator>
            </Primitive.Root>

            <div style={{ minWidth: 0 }}>
                <label htmlFor={id} className="cbx-check-label">
                    {label}
                </label>
                {hint !== undefined && (
                    <p id={hintId} className="cbx-check-hint">
                        {hint}
                    </p>
                )}
            </div>
        </div>
    );
}
