import { Tooltip as Primitive } from 'radix-ui';
import type { ReactNode } from 'react';

export const TooltipProvider = Primitive.Provider;

export interface TooltipProps {
    content: ReactNode;
    side?: 'top' | 'right' | 'bottom' | 'left';
    children: ReactNode;
}

/**
 * A hint on hover and on focus.
 *
 * NEVER the only place something is said. A tooltip is unreachable by touch and gone the
 * moment the pointer moves, so anything a person NEEDS belongs in the page — this is for
 * naming an icon-only control or expanding an abbreviation, not for instructions.
 */
export function Tooltip({ content, side = 'top', children }: TooltipProps) {
    return (
        <Primitive.Root>
            <Primitive.Trigger asChild>{children}</Primitive.Trigger>
            <Primitive.Portal>
                <Primitive.Content side={side} sideOffset={6} className="cbx-tooltip">
                    {content}
                    <Primitive.Arrow className="cbx-tooltip-arrow" width={10} height={5} />
                </Primitive.Content>
            </Primitive.Portal>
        </Primitive.Root>
    );
}
