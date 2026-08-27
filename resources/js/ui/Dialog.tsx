import { Dialog as Primitive } from 'radix-ui';
import { type ReactNode, useRef } from 'react';
import { cn } from '@/lib/cn';
import { Icon } from './Icon';

export interface DialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    /**
     * One sentence on what confirming will do. Radix warns when it is missing, and
     * rightly: a dialog announced as nothing but its title tells a screen-reader user
     * that something opened and not what it wants.
     */
    description?: ReactNode;
    /** The buttons, right-aligned in a footer. Destructive last, cancel first. */
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg';
    children?: ReactNode;
}

/**
 * WHERE FOCUS GOES WHEN THE DIALOG CLOSES.
 *
 * Radix returns focus to its own `<Trigger>`, and this dialog deliberately has none: it
 * is opened by whatever the caller likes — a button, a menu item that has since
 * unmounted, a keyboard shortcut, a redirect. With no trigger, Radix's default is to
 * focus nothing, which drops the keyboard user at the top of the document with the page
 * they were working on scrolled somewhere below.
 *
 * `onOpenAutoFocus` is the event that MOVES focus into the dialog, so it fires while
 * `document.activeElement` is still whatever opened it. Catching it there is the only
 * point at which that element is still knowable.
 */
function useReturnFocus(): {
    onOpenAutoFocus: () => void;
    onCloseAutoFocus: (event: Event) => void;
} {
    const returnTo = useRef<HTMLElement | null>(null);

    return {
        onOpenAutoFocus: () => {
            const active = document.activeElement;
            returnTo.current = active instanceof HTMLElement ? active : null;
        },
        onCloseAutoFocus: (event) => {
            if (returnTo.current === null || !returnTo.current.isConnected) {
                return;
            }

            event.preventDefault();
            returnTo.current.focus();
        },
    };
}

/**
 * A modal.
 *
 * Radix owns the parts that are easy to get wrong and invisible when you do: focus moves
 * in on open and back to the trigger on close, Tab is trapped inside, Escape closes,
 * everything behind it is inert to assistive technology, and the scroll position of the
 * page underneath is held.
 *
 * The Volt console did this with Alpine: `x-show`, a hand-written keydown listener and no
 * focus trap at all. Tab walked straight out of the dialog and into the page behind it.
 */
export function Dialog({
    open,
    onOpenChange,
    title,
    description,
    footer,
    size = 'md',
    children,
}: DialogProps) {
    const returnFocus = useReturnFocus();

    return (
        <Primitive.Root open={open} onOpenChange={onOpenChange}>
            <Primitive.Portal>
                <Primitive.Overlay className="cbx-overlay" />
                <Primitive.Content
                    className={cn('cbx-dialog', `cbx-dialog--${size}`)}
                    {...returnFocus}
                >
                    <div className="cbx-dialog-hd">
                        <div style={{ minWidth: 0 }}>
                            <Primitive.Title className="cbx-dialog-title">{title}</Primitive.Title>
                            {description !== undefined && (
                                <Primitive.Description className="cbx-dialog-desc">
                                    {description}
                                </Primitive.Description>
                            )}
                        </div>
                        <Primitive.Close className="cbx-iconbtn" aria-label="Close">
                            <Icon name="close" className="w-4 h-4" />
                        </Primitive.Close>
                    </div>

                    {children !== undefined && <div className="cbx-dialog-body">{children}</div>}

                    {footer !== undefined && <div className="cbx-dialog-ft">{footer}</div>}
                </Primitive.Content>
            </Primitive.Portal>
        </Primitive.Root>
    );
}

/**
 * Closes the dialog it is inside. Useful for a "Cancel" in the footer that should not
 * also have to be wired to the caller's state.
 *
 * There is no exported Trigger, and that is not an omission: `Primitive.Root` lives
 * inside this component, so a trigger placed outside it would have no context to open.
 * Callers set `open` themselves, and focus still returns to whatever they clicked —
 * see {@see useReturnFocus}.
 */
export const DialogClose = Primitive.Close;
