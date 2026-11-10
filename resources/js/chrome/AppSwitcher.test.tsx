import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { setPageProps } from '@/test/page';
import { AppSwitcher, ServiceMark } from './AppSwitcher';

describe('AppSwitcher', () => {
    it('renders the shared <cbox-app-switcher> fed by the console catalog endpoint', () => {
        setPageProps({ appCatalogUrl: '/switch-app/catalog' });
        const { container } = render(<AppSwitcher />);
        const element = container.querySelector('cbox-app-switcher')!;
        expect(customElements.get('cbox-app-switcher')).toBeDefined();
        expect(element.getAttribute('endpoint')).toBe('/switch-app/catalog');
        expect(element.getAttribute('current')).toBe('id');
        expect(JSON.parse(element.getAttribute('labels')!)).toEqual({
            title: 'Switch app',
            empty: 'No apps have been published for you yet.',
        });
        expect(container.textContent).not.toMatch(/reconnect/i);
    });
    it('is disabled when no one is signed in to the console', () => {
        setPageProps({ appCatalogUrl: null });
        const { container } = render(<AppSwitcher />);
        expect(container.querySelector('cbox-app-switcher')!.getAttribute('endpoint')).toBe('');
    });
    it('opens itself for an old /switch-app bookmark', () => {
        setPageProps({ appCatalogUrl: '/switch-app/catalog' });
        window.history.replaceState({}, '', '/account?switch-app=1');
        const { container } = render(<AppSwitcher />);
        const button = container
            .querySelector('cbox-app-switcher')!
            .shadowRoot!.querySelector('button')!;
        expect(button.getAttribute('aria-expanded')).toBe('true');
        window.history.replaceState({}, '', '/');
    });
    it('draws the rail mark through the shared <cbox-service-mark>', () => {
        const { container } = render(<ServiceMark service="id" />);
        expect(container.querySelector('cbox-service-mark')!.getAttribute('service')).toBe('id');
    });
});
