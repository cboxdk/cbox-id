import type { ReactNode } from 'react';
import type { HelpContent } from '@/types';
import { Help } from './Help';
import { Icon } from './Icon';
import type { IconName } from './icons';

export interface EmptyStateProps {
    icon?: IconName;
    title: ReactNode;
    /** The concept, explained — and the link to the guide where one exists. */
    help?: HelpContent;
    description?: ReactNode;
    /**
     * What to do about it, numbered. An empty list is usually not a bug but a
     * before-state, and the difference between a dead end and a starting point is whether
     * the screen says what the first step is.
     */
    steps?: ReactNode[];
    actions?: ReactNode;
}

/**
 * What a list says when it has nothing in it.
 *
 * The heading is an h3 because an empty state always sits inside a `<Panel>`, whose title
 * is the h2 — so the document outline stays intact rather than skipping a level.
 */
export function EmptyState({ icon, title, help, description, steps, actions }: EmptyStateProps) {
    return (
        <div className="cbx-empty">
            {icon !== undefined && (
                <span className="cbx-empty-icon">
                    <Icon name={icon} />
                </span>
            )}

            <h3 className="flex items-center gap-1">
                {title}
                {help !== undefined && <Help help={help} />}
            </h3>
            {description !== undefined && <p>{description}</p>}

            {steps !== undefined && steps.length > 0 && (
                <ol className="cbx-empty-steps">
                    {steps.map((step, index) => (
                        // The steps are static copy in a fixed order, so the index IS the
                        // identity here — there is nothing to reorder and nothing to key by.
                        // eslint-disable-next-line react/no-array-index-key
                        <li key={index}>{step}</li>
                    ))}
                </ol>
            )}

            {actions !== undefined && <div className="cbx-empty-actions">{actions}</div>}
        </div>
    );
}
