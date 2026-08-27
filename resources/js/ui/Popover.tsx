import { Popover as Primitive } from 'radix-ui';
import type { ComponentPropsWithoutRef } from 'react';
import { cn } from '@/lib/cn';

export const Popover = Primitive.Root;
export const PopoverTrigger = Primitive.Trigger;
export const PopoverAnchor = Primitive.Anchor;

export function PopoverContent({
    className,
    align = 'start',
    sideOffset = 6,
    children,
    ...props
}: ComponentPropsWithoutRef<typeof Primitive.Content>) {
    return (
        <Primitive.Portal>
            <Primitive.Content
                align={align}
                sideOffset={sideOffset}
                className={cn('cbx-popover', className)}
                {...props}
            >
                {children}
            </Primitive.Content>
        </Primitive.Portal>
    );
}
