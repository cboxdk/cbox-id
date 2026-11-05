import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Toaster as Sonner, toast } from 'sonner';

export { toast };

/**
 * THE CONSOLE'S ONE CONFIRMATION SURFACE.
 *
 * Under Volt this was two mechanisms that disagreed. A redirecting action flashed
 * `status` and the layout rendered it; a non-redirecting action dispatched a browser
 * event, because Livewire does not re-render the layout on an action round trip. Sixty-
 * three components flashed and only seven rendered it — so a message written by one of
 * the other fifty-six displayed nothing, and then surfaced later on an unrelated page,
 * because a flash survives to the next request.
 *
 * Every mutation is a real request now and every one of them redirects, so there is one
 * mechanism: the server flashes, this shows it once. A page that wants to say something
 * without a round trip calls `toast()` directly — the same surface either way.
 */
export function Toaster() {
    const { flash } = usePage();

    // Inertia drops native flash from history. Each new response may legitimately
    // repeat the same message (for example, saving two different profile fields).
    const last = useRef<typeof flash | null>(null);

    useEffect(() => {
        if (flash === last.current) return;
        last.current = flash;

        if (typeof flash.error === 'string' && flash.error) {
            // Longer than a confirmation: something went wrong is worth finishing.
            toast.error(flash.error, { duration: 8000 });

            return;
        }

        if (typeof flash.status === 'string' && flash.status) toast.success(flash.status);
    }, [flash]);

    return (
        <Sonner
            position="bottom-right"
            // The document's theme is already on <html data-theme>, painted by the server
            // before anything ran. Telling sonner to read the system preference instead
            // would light the one element on the page that disagrees with the rest of it.
            theme="system"
            // The default for a confirmation; errors ask for 8s at the call site.
            // Auto-dismiss is pausable on hover and on focus, which SC 2.2.1 requires —
            // somebody at 400% magnification must not lose the message mid-read.
            toastOptions={{ duration: 4500, classNames: { error: 'is-error' } }}
            gap={8}
            offset={{ bottom: 'var(--toast-bottom, 16px)' }}
            mobileOffset={{ bottom: 'var(--toast-bottom, 16px)' }}
        />
    );
}
