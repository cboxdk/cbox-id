import { Badge } from './Badge';
import { Combobox } from './Combobox';

/** A role that may be granted across the whole environment, as the server describes it. */
export interface StaffRoleOption {
    id: string;
    name: string;
    /** The app whose tokens it reaches, or null for every app. */
    app: string | null;
    /** No organization's administrators may grant it. */
    staffOnly: boolean;
}

/** "All apps" for an app-agnostic role; the app's name otherwise. */
export function staffRoleScope(role: Pick<StaffRoleOption, 'app'>): string {
    return role.app ?? 'All apps';
}

/**
 * The one picker for a staff role, on the Staff page and on a user's page — searchable by
 * role and by app, because "Support" is a role in several apps and the app is the half of
 * the answer that decides whose tokens it reaches.
 */
export function StaffRolePicker({
    roles,
    value,
    onValueChange,
}: {
    roles: StaffRoleOption[];
    value: string;
    onValueChange: (value: string) => void;
}) {
    return (
        <Combobox
            aria-label="Staff role"
            value={value === '' ? undefined : value}
            onValueChange={onValueChange}
            placeholder="Choose a role…"
            searchPlaceholder="Search roles or apps…"
            emptyMessage="No role matches that."
            options={roles.map((role) => ({
                value: role.id,
                label: role.name,
                keywords: [staffRoleScope(role)],
                hint: (
                    <span className="inline-flex items-center gap-1.5">
                        {staffRoleScope(role)}
                        {role.staffOnly && <Badge>Staff-only</Badge>}
                    </span>
                ),
            }))}
        />
    );
}
