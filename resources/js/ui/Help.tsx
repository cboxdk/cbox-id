import { useTranslator as useConsoleTranslator } from '@/i18n';
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
 * NON-MODAL, and the distinction is the whole of what its predecessor got wrong. The
 * blade panel put `role="dialog"` on a div that took no focus, trapped nothing and
 * restored nothing: a screen reader announced a dialog its user could neither enter nor
 * leave. This one is what it says it is — focus moves in so the link is reachable, Escape
 * closes it, focus goes back to the trigger, and the page underneath stays interactive
 * because nothing is trapped. Held in `Help.test.tsx`, which is a rendered DOM rather
 * than a grep for the attribute.
 *
 * The copy comes from the server, resolved from {@see \App\Platform\Help\HelpTopic}. It
 * has to be identical wherever the same concept surfaces — a page header, an empty state
 * and the setup checklist all explain "single sign-on" in the same words — so it lives in
 * one place and travels as text.
 */
export function Help({ help, label }: HelpProps) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <Popover>
            {label !== undefined ? (
                <PopoverTrigger className="cbx-help-link">{label}</PopoverTrigger>
            ) : (
                <PopoverTrigger
                    className="cbx-help-btn"
                    aria-label={consoleFinalText('console.what_is_part1_00d3f3e7', {
                        part1: help.title,
                    })}
                    title={consoleT('console.what_is_this_0b12ba39')}
                >
                    <Icon name="help" className="w-4 h-4" />
                </PopoverTrigger>
            )}

            <PopoverContent className="cbx-help-content">
                <p className="idc-help-title">{help.title}</p>
                <p className="idc-help-body">{help.summary}</p>

                {help.href !== null && (
                    <a className="cbx-help-link" href={help.href} target="_blank" rel="noreferrer">
                        {consoleRich('console.read_the_guide_part1_3ea3eb97', {
                            part1: (
                                <span className="sr-only">
                                    {' '}
                                    {consoleT('console._opens_in_a_new_tab_1d183356')}
                                </span>
                            ),
                        })}
                    </a>
                )}
            </PopoverContent>
        </Popover>
    );
}
