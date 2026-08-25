import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { Popover, PopoverContent, PopoverTrigger } from './Popover';

export interface HelpProps {
    /** What the popover is about, announced on the trigger — "Help with SCIM tokens". */
    title: string;
    /** Where to read more. Rendered as the last thing in the panel. */
    href?: string;
    linkLabel?: string;
    children: ReactNode;
}

/**
 * The "what is this?" panel beside a setting.
 *
 * A popover rather than a tooltip, because the content here is prose with a link in it:
 * a tooltip closes the moment the pointer leaves, so a link inside one is unreachable,
 * and none of it exists at all on a touch screen.
 */
export function Help({ title, href, linkLabel = 'Read more', children }: HelpProps) {
    return (
        <Popover>
            <PopoverTrigger className="cbx-help-btn" aria-label={title}>
                <Icon name="help" className="w-4 h-4" />
            </PopoverTrigger>

            <PopoverContent className="cbx-help-content">
                <p className="cbx-help-title">{title}</p>
                <div className="cbx-help-body">{children}</div>

                {href !== undefined && (
                    <a className="cbx-help-link" href={href} target="_blank" rel="noreferrer">
                        {linkLabel}
                        <span className="sr-only"> (opens in a new tab)</span>
                    </a>
                )}
            </PopoverContent>
        </Popover>
    );
}
