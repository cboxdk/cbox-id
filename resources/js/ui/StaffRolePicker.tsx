import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
export function staffRoleScope(role: Pick<StaffRoleOption, 'app'>, allApps = 'All apps'): string {
    return role.app ?? allApps;
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <Combobox
            aria-label={consoleT('console.admin_support_role_cd7f9f2d')}
            value={value === '' ? undefined : value}
            onValueChange={onValueChange}
            placeholder={consoleUiText('console.choose_a_role_04f24839')}
            searchPlaceholder={consoleT('console.search_roles_or_apps_4350bf35')}
            emptyMessage={consoleT('console.no_role_matches_that_c2c896ae')}
            options={roles.map((role) => ({
                value: role.id,
                label: role.name,
                keywords: [staffRoleScope(role, tc('console.all_apps_01bed311'))],
                hint: (
                    <span className="inline-flex items-center gap-1.5">
                        {staffRoleScope(role, tc('console.all_apps_01bed311'))}
                        {role.staffOnly && (
                            <Badge>{consoleT('console.admins_support_only_bc5dfbd2')}</Badge>
                        )}
                    </span>
                ),
            }))}
        />
    );
}
