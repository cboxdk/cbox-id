import { DropdownMenu as Primitive } from 'radix-ui';
import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export const DropdownMenu = Primitive.Root;
export const DropdownMenuTrigger = Primitive.Trigger;

export function DropdownMenuContent({
    className,
    align = 'end',
    sideOffset = 6,
    children,
    ...props
}: ComponentPropsWithoutRef<typeof Primitive.Content>) {
    return (
        <Primitive.Portal>
            <Primitive.Content
                align={align}
                sideOffset={sideOffset}
                className={cn('cbx-menu', className)}
                {...props}
            >
                {children}
            </Primitive.Content>
        </Primitive.Portal>
    );
}

export interface DropdownMenuItemProps
    extends ComponentPropsWithoutRef<typeof Primitive.Item> {
    /** Styles the item as destructive. The label must still say what it destroys. */
    destructive?: boolean;
}

export function DropdownMenuItem({
    destructive = false,
    className,
    ...props
}: DropdownMenuItemProps) {
    return (
        <Primitive.Item
            className={cn('cbx-menuitem', destructive && 'is-destructive', className)}
            {...props}
        />
    );
}

export function DropdownMenuLabel({ children }: { children: ReactNode }) {
    return <Primitive.Label className="cbx-menulabel">{children}</Primitive.Label>;
}

export function DropdownMenuSeparator() {
    return <Primitive.Separator className="cbx-menusep" />;
}
