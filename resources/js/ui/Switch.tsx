import { Switch as Primitive } from 'radix-ui';
import { cn } from '@/lib/cn';
import { useFieldControl } from './Field';

export interface SwitchProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    name?: string;
    /** Required when the switch has no `<Field>` label beside it. */
    'aria-label'?: string;
    className?: string;
}

/**
 * An immediate on/off.
 *
 * A switch means the change takes effect NOW. If the setting only applies when a form is
 * saved, it is a checkbox — a switch that needs a Save button beside it has told the
 * person a lie about what their click did.
 */
export function Switch({ className, ...props }: SwitchProps) {
    const field = useFieldControl();

    return (
        <Primitive.Root className={cn('cbx-switch', className)} {...field} {...props}>
            <Primitive.Thumb className="cbx-switch-thumb" />
        </Primitive.Root>
    );
}
