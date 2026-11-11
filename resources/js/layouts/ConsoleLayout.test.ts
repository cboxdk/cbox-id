import { describe, expect, it } from 'vitest';
import { switchRequest } from './ConsoleLayout';

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
