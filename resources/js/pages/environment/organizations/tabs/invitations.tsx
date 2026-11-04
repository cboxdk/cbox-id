import { useTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    type AccessRoleOption,
    InviteForm,
    Panel,
    type PendingInvitation,
    PendingInvitations,
    type ReturnApp,
    type RoleOption,
} from '@/ui';

type Props = PageProps<{
    invitations: PendingInvitation[];
    /**
     * What an invitation may carry: the organization's own roles and those of the apps it
     * can use — never a staff-only role, which is granted on the member once they have joined.
     */
    inviteAccessRoles: AccessRoleOption[];
    /** What an invitation may give — never Owner. */
    roleOptions: RoleOption[];
    apps: ReturnApp[];
    inviteHref: string;
}>;

/**
 * AN ORGANIZATION › INVITATIONS — invite somebody by email, and the invitations nobody has
 * accepted yet. The header and tabs are the layout's (`../frame.tsx`).
 */
export default function OrganizationInvitations({
    invitations,
    inviteAccessRoles,
    roleOptions,
    apps,
    inviteHref,
}: Props) {
    const { t: tc } = useTranslator();

    return (
        <div className="space-y-6">
            <Panel
                title={tc('console.invite_someone_9b7639e8')}
                description={tc('console.the_invitee_accepts_by_email_nobody_is_added_04408aaf')}
            >
                <InviteForm
                    href={inviteHref}
                    roles={roleOptions}
                    accessRoles={inviteAccessRoles.map((role) => ({
                        id: role.id,
                        name: role.name,
                        group: role.app ?? tc('console.all_apps_01bed311'),
                    }))}
                    apps={apps}
                />
            </Panel>

            <PendingInvitations invitations={invitations} />
        </div>
    );
}

OrganizationInvitations.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
