import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { HelpContent } from '@/types';
import { Help } from './Help';

const topic: HelpContent = {
    topic: 'members',
    title: 'Members',
    summary: 'Members are the people who can sign in to this organization.',
    href: 'https://docs.example.test/members',
};

/**
 * THIS REPLACES A SOURCE-GREP.
 *
 * The blade help panel put `role="dialog"` on a `<div>` that took no focus, trapped
 * nothing and restored nothing — a label describing behaviour the markup did not have, so
 * a screen reader announced a dialog and then left its user inside the page with no way
 * out and nothing to come back from. The test that caught it read the blade file for the
 * string, which is the only thing a source-grep can do, and it is not what anybody needed
 * to know.
 *
 * The port is a Radix popover, and a popover IS a non-modal dialog: it takes focus, closes
 * on Escape and hands focus back. The label is true now, so asserting its absence would
 * assert the wrong thing. What is worth holding is the BEHAVIOUR the old markup lacked,
 * which a rendered test can actually see.
 */
describe('Help', () => {
    it('announces itself as closed until it is opened', async () => {
        render(<Help help={topic} />);

        const trigger = screen.getByRole('button', { name: 'What is Members?' });

        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await userEvent.click(trigger);

        await waitFor(() => expect(trigger).toHaveAttribute('aria-expanded', 'true'));
    });

    it('puts the explanation and its link where the keyboard can reach them', async () => {
        render(<Help help={topic} />);

        await userEvent.click(screen.getByRole('button', { name: 'What is Members?' }));

        // The prose, and the link that is the reason this is a popover rather than a
        // tooltip: a tooltip closes as the pointer leaves, so a link inside one cannot be
        // clicked, and on a touch screen it never opens at all.
        expect(await screen.findByText(topic.summary)).toBeInTheDocument();

        const guide = screen.getByRole('link', { name: /Read the guide/ });

        expect(guide).toHaveAttribute('href', topic.href);

        // Announced as leaving the page, for a reader who cannot see the new tab appear.
        expect(guide).toHaveTextContent('opens in a new tab');
    });

    it('closes on Escape and gives focus back to the trigger', async () => {
        render(<Help help={topic} />);

        const trigger = screen.getByRole('button', { name: 'What is Members?' });

        await userEvent.click(trigger);
        await screen.findByText(topic.summary);

        await userEvent.keyboard('{Escape}');

        // BOTH HALVES. A panel that closes without returning focus drops a keyboard user
        // at the top of the document, which is the failure the old `role="dialog"` was
        // lying about not having.
        await waitFor(() => expect(screen.queryByText(topic.summary)).not.toBeInTheDocument());
        expect(trigger).toHaveFocus();
    });
});
