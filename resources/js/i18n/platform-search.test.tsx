import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { consoleI18n } from '@/test/catalogue';
import { DEFAULT_PAGE_PROPS, setPageProps } from '@/test/page';
import PlatformSearch from '@/pages/console/platform/search';

vi.mock('@/layouts/ConsoleLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

describe('platform search result summaries', () => {
    it.each([0, 1, 2, 5])('renders whole translated count messages for %i results', (count) => {
        const i18n = consoleI18n('pl');
        // A translator may reorder the count and add punctuation; English noun assembly cannot.
        i18n.messages['console.search_results_found'] = 'Wyniki (:count)';
        setPageProps({ i18n });
        render(
            <PlatformSearch
                {...DEFAULT_PAGE_PROPS}
                term="Acme"
                ready
                organizations={Array.from({ length: count }, (_, index) => ({
                    id: String(index),
                    name: `Acme ${index}`,
                    slug: `acme-${index}`,
                    suspended: false,
                    plane: 'Production',
                    href: `/organizations/${index}`,
                }))}
                users={[]}
                help={{ topic: 'search', title: 'Search', summary: 'Find people', href: '/help' }}
            />,
        );
        expect(screen.getAllByText(`Wyniki (${count})`)).toHaveLength(count === 0 ? 2 : 1);
        expect(screen.queryByText(/\bmatches?\b/)).not.toBeInTheDocument();
    });
});
