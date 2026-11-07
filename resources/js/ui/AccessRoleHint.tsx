import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Badge } from './Badge';

/** An app or custom role as the environment console offers it inside one organization. */
export interface AccessRoleOption {
    id: string;
    name: string;
    /** The app it is scoped to, or null when it applies across all of them. */
    app: string | null;
    /**
     * A staff role (`tenant_assignable: false`): the vendor's own people only. The
     * environment console may grant it inside one organization; an organization's own
     * admins never see it offered.
     */
    staffOnly: boolean;
}

/**
 * What a role reaches, and — for a staff role — that it is one, in the Staff page's words.
 * Drawn under the role's checkbox on every environment-console picker, so an administrator
 * granting it inside one customer's organization knows it is not that customer's role.
 */
export function AccessRoleHint({ role }: { role: AccessRoleOption }) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    if (!role.staffOnly) {
        return <>{role.app ?? consoleRuntimeText('console.all_apps_01bed311')}</>;
    }

    return (
        <>
            <span className="inline-flex items-center gap-1.5">
                {role.app ?? consoleRuntimeText('console.all_apps_01bed311')}
                <Badge tone="info">{consoleT('console.admins_support_only_bc5dfbd2')}</Badge>
            </span>
            <span className="block">
                {consoleT(
                    'console.for_your_own_people_this_organization_s_admins_can_t_se_401528dd',
                )}
            </span>
        </>
    );
}
