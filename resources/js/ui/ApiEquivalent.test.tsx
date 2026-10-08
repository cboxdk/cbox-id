import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ApiAction } from '@/lib/apiSnippets';
import { setPageProps } from '@/test/page';
import { ApiEquivalent, PageApiEquivalents } from './ApiEquivalent';
import { EmptyState } from './EmptyState';

const CREATE: ApiAction = {
    name: 'apps.create',
    summary: 'Register an app.',
    method: 'POST',
    path: '/apps',
    url: 'https://acme.cboxid.com/api/v1/apps',
    scope: 'apps:write',
    danger: 'critical',
    plane: 'environment',
    tool: 'apps_create',
    cli: 'cbox id apps:create',
    cliShipped: false,
    sdk: 'env.apps.create',
    sdkPreview: false,
    redact: ['client_secret'],
    fields: [
        {
            name: 'name',
            type: 'string',
            required: false,
            in: 'body',
            secret: false,
            description: null,
            enum: null,
            value: null,
        },
    ],
};

describe('ApiEquivalent', () => {
    it('renders nothing for an action the page does not host', () => {
        const { container } = render(<ApiEquivalent action="apps.create" />);

        expect(container).toBeEmptyDOMElement();
    });

    it("opens the same operation as curl, MCP, CLI and id-js, with the form's values", async () => {
        setPageProps({ apiEquivalents: { 'apps.create': CREATE } });
        render(<ApiEquivalent action="apps.create" values={{ name: 'Shop' }} />);

        const toggle = screen.getByRole('button', { name: 'API' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        await userEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText('needs', { exact: false })).toHaveTextContent('apps:write');
        expect(screen.getByRole('tab', { name: 'curl' })).toHaveAttribute('aria-selected', 'true');
        expect(document.body).toHaveTextContent('"name": "Shop"');

        for (const tab of ['MCP', 'CLI', 'id-js']) {
            expect(screen.getByRole('tab', { name: tab })).toBeInTheDocument();
        }

        await userEvent.click(screen.getByRole('tab', { name: 'CLI' }));
        expect(document.body).toHaveTextContent('cbox id apps:create --name=Shop');
        expect(document.body).toHaveTextContent('Not in the cbox CLI yet');
    });

    it('puts every action the page hosts behind one button in the top bar', async () => {
        setPageProps({ apiEquivalents: { 'apps.create': CREATE } });
        render(<PageApiEquivalents />);

        await userEvent.click(screen.getByRole('button', { name: /^API/ }));

        expect(screen.getByRole('dialog', { name: 'Do this from code' })).toBeInTheDocument();
        expect(document.body).toHaveTextContent('/api/v1/apps');
    });

    it('draws no API button on a page that hosts no action', () => {
        render(<PageApiEquivalents />);

        expect(screen.queryByRole('button', { name: /^API/ })).not.toBeInTheDocument();
    });

    it('offers the first step of an empty list as code too', () => {
        setPageProps({ apiEquivalents: { 'apps.create': CREATE } });
        render(<EmptyState title="No apps yet" equivalent="apps.create" />);

        expect(screen.getByRole('button', { name: 'Or from code' })).toBeInTheDocument();
    });
});
