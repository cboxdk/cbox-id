import {
    type AgentScope,
    groupScopes,
    highestRisk,
    matchingPreset,
    presetById,
    presetScopes,
    PRESETS,
    unheldScopes,
} from './agents';

function scope(value: string, risk: AgentScope['risk'], held = true): AgentScope {
    const resource = value.split(':')[0] ?? value;

    return {
        value,
        label: value,
        description: null,
        resource,
        resourceLabel: resource.charAt(0).toUpperCase() + resource.slice(1),
        risk,
        held,
        actions: [],
    };
}

/** A catalogue shaped like the server's: read and write per resource, risks from actions. */
const CATALOGUE: AgentScope[] = [
    scope('organizations:read', 'read', false),
    scope('organizations:write', 'critical', false),
    scope('users:read', 'read', false),
    scope('users:write', 'destructive', false),
    scope('members:read', 'read', false),
    scope('members:write', 'destructive', false),
    scope('invitations:read', 'read', false),
    scope('invitations:write', 'destructive', false),
    scope('apps:read', 'read'),
    scope('apps:write', 'critical'),
    scope('branding:read', 'read'),
    scope('branding:write', 'write'),
];

describe('presets', () => {
    it('grants only reads for Read-only, and asks for no approval', () => {
        expect(presetScopes('read-only', CATALOGUE)).toEqual([
            'organizations:read',
            'users:read',
            'members:read',
            'invitations:read',
            'apps:read',
            'branding:read',
        ]);
        expect(presetById('read-only')?.approval).toBe('none');
    });

    it('lets a support agent help people, never transfer an organization, and holds destruction', () => {
        const granted = presetScopes('support', CATALOGUE);

        expect(granted).toContain('users:write');
        expect(granted).toContain('members:write');
        expect(granted).toContain('invitations:write');
        expect(granted).not.toContain('organizations:write');
        expect(granted).not.toContain('apps:write');
        expect(granted).not.toContain('branding:write');
        expect(granted).toContain('apps:read');
        expect(presetById('support')?.approval).toBe('destructive');
    });

    it('gives a full admin every scope and holds critical actions', () => {
        expect(presetScopes('full-admin', CATALOGUE)).toEqual(CATALOGUE.map((s) => s.value));
        expect(presetById('full-admin')?.approval).toBe('critical');
    });

    it('names the preset a selection is exactly, whatever the order', () => {
        const readOnly = presetScopes('read-only', CATALOGUE).reverse();

        expect(matchingPreset(readOnly, CATALOGUE)).toBe('read-only');
        expect(matchingPreset([...readOnly, 'branding:write'], CATALOGUE)).toBeNull();
        expect(
            matchingPreset(
                CATALOGUE.map((s) => s.value),
                CATALOGUE,
            ),
        ).toBe('full-admin');
    });

    it('offers the three presets in the order they widen', () => {
        expect(PRESETS.map((p) => p.id)).toEqual(['read-only', 'support', 'full-admin']);
        expect(presetById('nonsense')).toBeNull();
    });
});

describe('risk', () => {
    it('is the highest any selected scope unlocks', () => {
        expect(highestRisk([], CATALOGUE)).toBe('read');
        expect(highestRisk(['apps:read', 'branding:write'], CATALOGUE)).toBe('write');
        expect(highestRisk(['users:write', 'branding:write'], CATALOGUE)).toBe('destructive');
        expect(highestRisk(['apps:write'], CATALOGUE)).toBe('critical');
    });

    it('groups by resource in catalogue order, read before write, with the group at its riskiest', () => {
        const groups = groupScopes([
            scope('apps:write', 'critical'),
            scope('apps:read', 'read'),
            scope('branding:read', 'read'),
        ]);

        expect(groups.map((g) => g.resource)).toEqual(['apps', 'branding']);
        expect(groups[0]?.scopes.map((s) => s.value)).toEqual(['apps:read', 'apps:write']);
        expect(groups[0]?.risk).toBe('critical');
        expect(groups[1]?.risk).toBe('read');
    });

    it('says which selected writes an approval policy cannot hold', () => {
        expect(
            unheldScopes(['users:read', 'users:write', 'apps:write'], CATALOGUE).map(
                (s) => s.value,
            ),
        ).toEqual(['users:write']);
    });
});
