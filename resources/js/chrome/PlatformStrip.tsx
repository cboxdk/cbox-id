import { Link } from '@inertiajs/react';
import { Icon } from '@/ui';

export interface PlatformStripProps {
    /** Back to the operator's own console. */
    exitHref: string;
}

/**
 * YOU ARE IN PLATFORM ADMIN — said above every page of it, with the way out beside it.
 *
 * The platform pages act on the whole install: a click here suspends a customer or reads
 * every workspace's usage. They used to be three more areas at the bottom of an operator's
 * rail, framed exactly like the operator's own Settings, so nothing on screen said which
 * side of that line a page was on. A strip the full width of the page, in the warning
 * tone and with the words in it, is hard to be on without noticing.
 *
 * A landmark of its own, named, so a screen reader can jump to it and hear the mode.
 */
export function PlatformStrip({ exitHref }: PlatformStripProps) {
    return (
        <section className="cbx-platform-strip" aria-label="Platform admin">
            <span className="cbx-platform-badge">
                <Icon name="lock" className="w-3.5 h-3.5" />
                Platform admin
            </span>
            <span className="cbx-platform-copy">
                You are administering the whole install — every workspace on it.
            </span>
            <Link href={exitHref} className="cbx-platform-exit">
                Exit platform admin
            </Link>
        </section>
    );
}
