import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ProviderMark } from './ProviderMark';

describe('ProviderMark', () => {
    it.each(['linkedin', 'bitbucket', 'xero', 'intuit'])(
        'draws %s as a monochrome glyph that follows the button colour',
        (provider) => {
            const { container } = render(<ProviderMark provider={provider} />);
            const svg = container.querySelector('svg');

            expect(svg).not.toBeNull();
            expect(svg).toHaveAttribute('fill', 'currentColor');
            expect(svg).toHaveAttribute('aria-hidden', 'true');

            // Neutral: no colour of its own anywhere in the glyph.
            for (const path of container.querySelectorAll('path')) {
                expect(path).not.toHaveAttribute('fill');
            }
        },
    );

    it('cuts the window out of a glyph drawn with evenodd', () => {
        const { container } = render(<ProviderMark provider="bitbucket" />);

        expect(container.querySelector('path')).toHaveAttribute('fill-rule', 'evenodd');
    });

    it('still falls back to a monogram for a provider it has no glyph for', () => {
        const { container } = render(<ProviderMark provider="okta" />);

        expect(container.querySelector('svg')).toBeNull();
        expect(container.textContent).toBe('O');
    });
});
