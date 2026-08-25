import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Icon } from './Icon';
import { iconNames } from './icons';

describe('Icon', () => {
    it('is hidden from assistive technology unless it carries the whole meaning', () => {
        const { container } = render(<Icon name="check" />);
        const svg = container.querySelector('svg');

        expect(svg).toHaveAttribute('aria-hidden', 'true');
        expect(svg).not.toHaveAttribute('role');
    });

    it('becomes an image with a name when it is the only thing saying so', () => {
        render(<Icon name="shield" label="Verified" />);

        const svg = screen.getByRole('img', { name: 'Verified' });
        expect(svg).not.toHaveAttribute('aria-hidden');
    });

    it('lets a caller resize it without also having to restate the default', () => {
        const { container } = render(<Icon name="plus" className="w-4 h-4" />);

        expect(container.querySelector('svg')).toHaveClass('w-4', 'h-4');
        expect(container.querySelector('svg')).not.toHaveClass('w-5');
    });

    it.each(iconNames)('draws %s', (name) => {
        const { container } = render(<Icon name={name} />);

        // A name with no path renders an empty <svg>, which is how the blade version
        // failed: a nav area whose icon was misspelled showed a blank square and nothing
        // anywhere said so.
        expect(container.querySelectorAll('path').length).toBeGreaterThan(0);
    });
});
