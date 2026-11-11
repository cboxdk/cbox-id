import { describe, expect, it } from 'vitest';
import { preferenceAction, preferenceActions, switchRequest } from './ConsoleLayout';

const levels = [
    { key: 'organization', current: { id: 'org_1' } },
    { key: 'project', current: { id: 'prj_1' } },
    { key: 'environment', current: { id: 'env_1' } },
];

describe('switchRequest', () => {
    it('sends only the levels that changed, so the switch keeps the page', () => {
        expect(
            switchRequest(
                {
                    organization: 'org_1',
                    project: 'prj_1',
                    environment: 'env_2',
                    return_to: '/users',
                },
                levels,
            ),
        ).toEqual({ select: { environment: 'env_2' }, from: '/users' });
    });

    it('sends the levels below a changed one too', () => {
        expect(
            switchRequest(
                { organization: 'org_1', project: 'prj_2', environment: 'env_9', return_to: '/x' },
                levels,
            ),
        ).toEqual({
            select: { project: 'prj_2', environment: 'env_9' },
            from: '/x',
        });
    });

    it('switches the organization when that is what was chosen', () => {
        expect(
            switchRequest({ organization: 'org_2', return_to: '/orgs/a/settings' }, levels),
        ).toEqual({
            select: { organization: 'org_2' },
            from: '/orgs/a/settings',
        });
    });
});

describe('preference actions', () => {
    const labels = {
        theme: 'Theme',
        themes: { system: 'System', light: 'Light', dark: 'Dark' },
        language: 'Language',
        format: 'Regional format',
    };

    it('offers theme, language and format in the palette as well as the account menu', () => {
        const actions = preferenceActions(
            labels,
            [{ code: 'da', label: 'Dansk' }],
            [{ code: 'en-US', label: 'English (United States)' }],
        );

        expect(actions.map((action) => action.label)).toEqual([
            'Theme: System',
            'Theme: Light',
            'Theme: Dark',
            'Language: Dansk',
            'Regional format: English (United States)',
        ]);
        expect(actions.every((action) => action.method === 'post')).toBe(true);
        expect(actions.map((action) => preferenceAction(action.url))).toEqual([
            { key: 'theme', value: 'system' },
            { key: 'theme', value: 'light' },
            { key: 'theme', value: 'dark' },
            { key: 'locale', value: 'da' },
            { key: 'regionalFormat', value: 'en-US' },
        ]);
    });

    it('leaves every other address to the router', () => {
        expect(preferenceAction('/orgs/acme/settings')).toBeNull();
        expect(preferenceAction('#preference:')).toBeNull();
        expect(preferenceAction('#preference:theme:')).toBeNull();
    });
});
