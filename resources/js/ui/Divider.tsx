import type { ReactNode } from 'react';

/**
 * A labelled rule — "or" between a password form and the SSO buttons.
 *
 * A `<div>` rather than an `<hr>` on purpose: `<hr>` is a thematic break with no room for
 * a label, and an `<hr>` with text jammed over it announces a separator and then a stray
 * word. This announces neither; the word is decoration over a line the eye needs and the
 * ear does not.
 */
export function Divider({ children }: { children?: ReactNode }) {
    return (
        <div className="divider" aria-hidden="true">
            {children}
        </div>
    );
}
