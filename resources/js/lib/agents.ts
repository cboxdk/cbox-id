/**
 * The agent scope picker's rules, as plain functions — what a preset grants, how risky a
 * set of scopes is, and how the picker groups them — so they are tested once here rather
 * than inferred from a rendered page.
 *
 * A scope's risk comes from the server (`App\Platform\Agents\AgentScopes`): the highest
 * danger among the actions that require it. Nothing here decides a risk; it only reads one.
 */

export type Risk = 'read' | 'write' | 'destructive' | 'critical';

/** `require_approval.min_danger`, plus `none` for a key that never waits. */
export type ApprovalLevel = 'none' | 'write' | 'destructive' | 'critical';

export interface AgentAction {
    name: string;
    summary: string;
    danger: Risk;
}

/** `App\Platform\Agents\AgentScope` */
export interface AgentScope {
    value: string;
    label: string;
    description: string | null;
    resource: string;
    resourceLabel: string;
    risk: Risk;
    /** False where approvals cannot hold it yet: endpoints that are not actions. */
    held: boolean;
    actions: AgentAction[];
}

export const RISK_RANK: Record<Risk, number> = { read: 0, write: 1, destructive: 2, critical: 3 };

export const RISK_LABEL: Record<Risk, string> = {
    read: 'Read',
    write: 'Write',
    destructive: 'Destructive',
    critical: 'Critical',
};

/** The badge tone for a risk: the word is always there, the colour only underlines it. */
export const RISK_TONE: Record<Risk, 'neutral' | 'info' | 'warn' | 'danger'> = {
    read: 'neutral',
    write: 'info',
    destructive: 'warn',
    critical: 'danger',
};

export type PresetId = 'read-only' | 'support' | 'full-admin';

export interface Preset {
    id: PresetId;
    label: string;
    description: string;
    approval: ApprovalLevel;
}

/**
 * The people resources a support agent works on. Organizations are deliberately absent:
 * their write scope can transfer ownership, which is nobody's idea of a support task.
 */
const SUPPORT_WRITES = ['users', 'members', 'invitations'];

export const PRESETS: readonly [Preset, Preset, Preset] = [
    {
        id: 'read-only',
        label: 'Read-only',
        description: 'Reads everything it is offered and changes nothing.',
        approval: 'none',
    },
    {
        id: 'support',
        label: 'Support agent',
        description:
            'Reads everything, and helps people: users, memberships and invitations. Anything destructive waits for you.',
        approval: 'destructive',
    },
    {
        id: 'full-admin',
        label: 'Full admin',
        description: 'Every scope. Critical actions — keys, secrets, sign-in — wait for you.',
        approval: 'critical',
    },
];

/** The scopes a preset grants, in the catalogue's order. */
export function presetScopes(preset: PresetId, scopes: AgentScope[]): string[] {
    return scopes
        .filter((scope) => {
            switch (preset) {
                case 'read-only':
                    return scope.risk === 'read';
                case 'support':
                    return (
                        scope.risk === 'read' ||
                        (SUPPORT_WRITES.includes(scope.resource) && scope.value.endsWith(':write'))
                    );
                case 'full-admin':
                    return true;
            }
        })
        .map((scope) => scope.value);
}

export function presetById(id: string | null | undefined): Preset | null {
    return PRESETS.find((preset) => preset.id === id) ?? null;
}

/** The preset a selection is exactly, if any — so the picker can show which is in effect. */
export function matchingPreset(selected: string[], scopes: AgentScope[]): PresetId | null {
    const chosen = [...new Set(selected)].sort().join(' ');

    for (const preset of PRESETS) {
        if (presetScopes(preset.id, scopes).sort().join(' ') === chosen) {
            return preset.id;
        }
    }

    return null;
}

/** The highest risk a selection unlocks; `read` for none. */
export function highestRisk(selected: string[], scopes: AgentScope[]): Risk {
    let highest: Risk = 'read';

    for (const scope of scopes) {
        if (selected.includes(scope.value) && RISK_RANK[scope.risk] > RISK_RANK[highest]) {
            highest = scope.risk;
        }
    }

    return highest;
}

export interface ScopeGroup {
    resource: string;
    label: string;
    /** The highest risk anything in the group unlocks — what the group header shows. */
    risk: Risk;
    scopes: AgentScope[];
}

/** By resource, in the catalogue's order, each group ordered read → critical. */
export function groupScopes(scopes: AgentScope[]): ScopeGroup[] {
    const groups = new Map<string, ScopeGroup>();

    for (const scope of scopes) {
        const group = groups.get(scope.resource) ?? {
            resource: scope.resource,
            label: scope.resourceLabel,
            risk: 'read' as Risk,
            scopes: [],
        };

        group.scopes.push(scope);

        if (RISK_RANK[scope.risk] > RISK_RANK[group.risk]) {
            group.risk = scope.risk;
        }

        groups.set(scope.resource, group);
    }

    for (const group of groups.values()) {
        group.scopes.sort((a, b) => RISK_RANK[a.risk] - RISK_RANK[b.risk]);
    }

    return [...groups.values()];
}

/**
 * The selected scopes an approval policy cannot hold — the endpoints that are not
 * actions yet. Said beside the policy, so it never looks wider than it is.
 */
export function unheldScopes(selected: string[], scopes: AgentScope[]): AgentScope[] {
    return scopes.filter(
        (scope) => selected.includes(scope.value) && !scope.held && scope.risk !== 'read',
    );
}
