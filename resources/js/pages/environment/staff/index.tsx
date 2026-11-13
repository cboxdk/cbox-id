import { EnvironmentReadOnly } from '@/ui/EnvironmentReadOnly';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
    type StaffRoleOption,
    StaffRolePicker,
} from '@/ui';

interface GrantRow {
    userId: string;
    userName: string | null;
    userEmail: string | null;
    role: StaffRoleOption;
    /** Who made the grant: a person here, or a sync. */
    source: string;
    userHref: string;
    revokeHref: string;
}

type Props = PageProps<{
    grants: GrantRow[];
    roles: StaffRoleOption[];
    storeHref: string;
    rolesHref: string;
    reviewHref: string;
    /** Where a person's role inside ONE organization is set instead. */
    organizationsHref: string;
    help: HelpContent;
}>;

/**
 * Grants grouped by ROLE — the role is what a reviewer certifies — with the roles that reach
 * every app first, then each app's by name.
 */
function byRole(grants: GrantRow[]): GrantRow[][] {
    const groups = new Map<string, GrantRow[]>();

    for (const grant of grants) {
        groups.set(grant.role.id, [...(groups.get(grant.role.id) ?? []), grant]);
    }

    return [...groups.values()].sort((a, b) => {
        const x = a[0]?.role;
        const y = b[0]?.role;

        if (x === undefined || y === undefined) {
            return 0;
        }

        if ((x.app === null) !== (y.app === null)) {
            return x.app === null ? -1 : 1;
        }

        return `${x.app ?? ''} ${x.name}`.localeCompare(`${y.app ?? ''} ${y.name}`);
    });
}

export default function Staff({
    grants,
    roles,
    storeHref,
    rolesHref,
    reviewHref,
    organizationsHref,
    help,
}: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [revoking, setRevoking] = useState<GrantRow | null>(null);

    return (
        <EnvironmentReadOnly>
            <>
                <PageHeader
                    help={help}
                    description={consoleT(
                        'console.an_admin_support_role_is_a_role_you_grant_to_your_own_p_caea5beb',
                    )}
                    actions={
                        <Button asChild className="shrink-0">
                            <Link href={reviewHref}>
                                {consoleRich(
                                    'console._part1_review_admin_support_access_fa3ed6c8',
                                    {
                                        part1: <Icon name="shield" className="w-4 h-4" />,
                                    },
                                )}
                            </Link>
                        </Button>
                    }
                />

                <div className="mt-6 space-y-6">
                    <div className="idc-where">
                        <p>{consoleT('console.staff_not_org_roles')}</p>
                        <Link href={organizationsHref}>
                            {consoleT('console.roles_where_org_link')}
                        </Link>
                    </div>

                    <GrantForm roles={roles} storeHref={storeHref} rolesHref={rolesHref} />

                    {grants.length === 0 ? (
                        <div className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                            <EmptyState
                                icon="members"
                                equivalent="users.environment_roles.grant"
                                title={consoleT(
                                    'console.nobody_holds_an_admin_support_role_41962c81',
                                )}
                                description={consoleT(
                                    'console.grant_one_to_the_people_who_support_or_run_your_apps_an_3991d001',
                                )}
                            />
                        </div>
                    ) : (
                        byRole(grants).map((rows) => {
                            const role = rows[0]?.role;

                            if (role === undefined) {
                                return null;
                            }

                            return (
                                <Panel
                                    key={role.id}
                                    title={
                                        <span className="inline-flex flex-wrap items-center gap-2">
                                            {role.name}
                                            <Badge>
                                                {role.app ??
                                                    consoleRuntimeText('console.all_apps_01bed311')}
                                            </Badge>
                                            {role.staffOnly && (
                                                <Badge tone="info">
                                                    {consoleT(
                                                        'console.admins_support_only_bc5dfbd2',
                                                    )}
                                                </Badge>
                                            )}
                                        </span>
                                    }
                                    description={
                                        role.app === null
                                            ? consoleText(
                                                  'console.in_the_tokens_of_every_app_in_every_organization_6a811d97',
                                              )
                                            : consoleFinalText(
                                                  'console.only_in_tokens_for_part1_in_every_organization_cead60fe',
                                                  { part1: role.app },
                                              )
                                    }
                                    action={
                                        <Badge>
                                            {consoleT('console.roles_summary_grants', {
                                                count: rows.length,
                                            })}
                                        </Badge>
                                    }
                                    flush
                                >
                                    <ul>
                                        {rows.map((grant) => (
                                            <li
                                                key={`${grant.userId}:${grant.role.id}`}
                                                className="idc-row"
                                            >
                                                <Link
                                                    href={grant.userHref}
                                                    className="idc-row-main"
                                                >
                                                    <span className="idc-row-title text-sm">
                                                        {grant.userName ??
                                                            grant.userEmail ??
                                                            grant.userId}
                                                    </span>
                                                    {grant.userName !== null &&
                                                        grant.userEmail !== null && (
                                                            <span className="idc-row-sub">
                                                                <span>{grant.userEmail}</span>
                                                            </span>
                                                        )}
                                                </Link>

                                                <Button
                                                    size="sm"
                                                    variant="danger"
                                                    className="shrink-0"
                                                    onClick={() => setRevoking(grant)}
                                                >
                                                    {consoleT('console.take_back_7d2a64f5')}
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                </Panel>
                            );
                        })
                    )}
                </div>

                <ConfirmDelete
                    open={revoking !== null}
                    onOpenChange={(open) => !open && setRevoking(null)}
                    name={revoking?.userName ?? revoking?.userEmail ?? ''}
                    title={consoleFinalText('console.take_back_part1_from_part2_5114808a', {
                        part1: revoking?.role.name ?? '',
                        part2: revoking?.userName ?? revoking?.userEmail ?? '',
                    })}
                    actionLabel={consoleUiText('console.take_back_7d2a64f5')}
                    consequence={consoleUiText(
                        'console.they_lose_this_role_in_every_organization_at_once_apps_2750f7e3',
                    )}
                    onConfirm={() => {
                        const grant = revoking;
                        setRevoking(null);

                        if (grant !== null) {
                            router.delete(grant.revokeHref, { preserveScroll: true });
                        }
                    }}
                />
            </>
        </EnvironmentReadOnly>
    );
}

function GrantForm({
    roles,
    storeHref,
    rolesHref,
}: {
    roles: StaffRoleOption[];
    storeHref: string;
    rolesHref: string;
}) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ email: '', role: '' });

    if (roles.length === 0) {
        return (
            <Panel title={consoleT('console.grant_an_admin_support_role_ebbcd7de')}>
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich(
                        'console.there_is_no_role_to_grant_yet_define_one_on_the_part1_p_ffe056a6',
                        {
                            part1: ' ',
                            part2: (
                                <Link
                                    href={rolesHref}
                                    className="underline underline-offset-2"
                                    style={{ color: 'var(--accent-strong)' }}
                                >
                                    {consoleT('console.roles_c2533705')}
                                </Link>
                            ),
                            part3: ' ',
                        },
                    )}
                </p>
            </Panel>
        );
    }

    return (
        <Panel
            title={consoleT('console.grant_an_admin_support_role_ebbcd7de')}
            description={consoleT(
                'console.segregation_of_duties_rules_are_checked_in_every_organi_8204cb95',
            )}
        >
            <form
                className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] items-start"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Field label={consoleT('console.person_6007db63')} error={form.errors.email}>
                    <Input
                        name="email"
                        type="email"
                        placeholder="name@example.com"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>

                <Field label={consoleT('console.role_14736a2e')} error={form.errors.role}>
                    <StaffRolePicker
                        roles={roles}
                        value={form.data.role}
                        onValueChange={(role) => form.setData('role', role)}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    className="shrink-0 sm:self-end"
                >
                    {consoleT('console.grant_78b7d037')}
                </Button>
            </form>
        </Panel>
    );
}

Staff.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
