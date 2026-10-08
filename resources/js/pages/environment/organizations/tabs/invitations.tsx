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
    return (
        <div className="space-y-6">
            <Panel
                title="Invite someone"
                description="The invitee accepts by email — nobody is added to an organization without saying yes."
            >
                <InviteForm
                    href={inviteHref}
                    roles={roleOptions}
                    accessRoles={inviteAccessRoles.map((role) => ({
                        id: role.id,
                        name: role.name,
                        group: role.app ?? 'All apps',
                    }))}
                    apps={apps}
                />
            </Panel>

            <PendingInvitations invitations={invitations} />
        </div>
    );
}

OrganizationInvitations.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
