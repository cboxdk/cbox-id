import type { HelpContent } from '@/types';
import { Icon } from './Icon';
import { Popover, PopoverContent, PopoverTrigger } from './Popover';

export interface HelpProps {
    help: HelpContent;
    /**
     * Render a text trigger ("What's this?") instead of the round ? button. For a place
     * where a floating icon has nothing to sit against — beside a form field, say.
     */
    label?: string;
}

/**
 * THE CONSOLE'S EXPLANATION AFFORDANCE: a quiet trigger that opens two or three plain
 * sentences about the concept in front of you, and a link to the guide where one exists.
 *
 * A POPOVER rather than a tooltip, because the content is prose with a link in it: a
 * tooltip closes the moment the pointer leaves, so a link inside one is unreachable, and
 * none of it exists at all on a touch screen.
 *
 * A DISCLOSURE rather than a dialog. It takes no focus on open, traps nothing and
 * restores nothing — the person reading it has not left the page, and moving their focus
 * to explain a word would be the interruption the explanation exists to avoid.
 *
 * The copy comes from the server, resolved from {@see \App\Platform\Help\HelpTopic}. It
 * has to be identical wherever the same concept surfaces — a page header, an empty state
 * and the setup checklist all explain "single sign-on" in the same words — so it lives in
 * one place and travels as text.
 */
export function Help({ help, label }: HelpProps) {
    return (
        <Popover>
            {label !== undefined ? (
                <PopoverTrigger className="cbx-help-link">{label}</PopoverTrigger>
            ) : (
                <PopoverTrigger
                    className="cbx-help-btn"
                    aria-label={`What is ${help.title}?`}
                    title="What is this?"
                >
                    <Icon name="help" className="w-4 h-4" />
                </PopoverTrigger>
            )}

            <PopoverContent className="cbx-help-content">
                <p className="cbx-help-title">{help.title}</p>
                <p className="cbx-help-body">{help.summary}</p>

                {help.href !== null && (
                    <a className="cbx-help-link" href={help.href} target="_blank" rel="noreferrer">
                        Read the guide
                        <span className="sr-only"> (opens in a new tab)</span>
                    </a>
                )}
            </PopoverContent>
        </Popover>
    );
}
