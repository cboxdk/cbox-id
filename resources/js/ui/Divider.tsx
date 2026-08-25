import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * A labelled rule — "or" between a password form and the SSO buttons.
 *
 * A `<div>` rather than an `<hr>` on purpose: `<hr>` is a thematic break with no room for
 * a label, and an `<hr>` with text jammed over it announces a separator and then a stray
 * word. This announces neither; the word is decoration over a line the eye needs and the
 * ear does not.
 */
export function Divider({ children, className }: { children?: ReactNode; className?: string }) {
    return (
        // The vertical rhythm belongs to the component, not to each call site: a divider
        // exists to separate two things, and one drawn tight against the control above it
        // groups them instead.
        <div className={cn('divider my-6', className)} aria-hidden="true">
            {children}
        </div>
    );
}
