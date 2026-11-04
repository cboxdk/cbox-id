import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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
    staffRoleScope,
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
    help: HelpContent;
}>;

/**
 * Grants grouped by what they reach: every app first, then each app by name — the
 * question this page answers is "who has what in Parcels?", and the app is its first half.
 */
function byScope(grants: GrantRow[], tc: Translator['t']): [string, GrantRow[]][] {
    const groups = new Map<string, GrantRow[]>();

    for (const grant of grants) {
        const scope = staffRoleScope(grant.role, tc('console.all_apps_01bed311'));
        groups.set(scope, [...(groups.get(scope) ?? []), grant]);
    }

    return [...groups.entries()].sort(([a], [b]) => {
        if (a === tc('console.all_apps_01bed311')) {
            return -1;
        }

        if (b === tc('console.all_apps_01bed311')) {
            return 1;
        }

        return a.localeCompare(b);
    });
}

export default function Staff({ grants, roles, storeHref, rolesHref, reviewHref, help }: Props) {
    const { t: tc } = useTranslator();

    const [revoking, setRevoking] = useState<GrantRow | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.an_admin_support_role_is_a_role_you_grant_to_caea5beb')}
                actions={
                    <Button asChild className="shrink-0">
                        <Link href={reviewHref}>
                            <Icon name="shield" className="w-4 h-4" />
                            {tc('console.review_admin_support_access_81f237a2')}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6 space-y-6">
                <GrantForm roles={roles} storeHref={storeHref} rolesHref={rolesHref} />

                {grants.length === 0 ? (
                    <div className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        <EmptyState
                            icon="members"
                            equivalent="users.environment_roles.grant"
                            title={tc('console.nobody_holds_an_admin_support_role_41962c81')}
                            description={tc(
                                'console.grant_one_to_the_people_who_support_or_run_y_3991d001',
                            )}
                        />
                    </div>
                ) : (
                    byScope(grants, tc).map(([scope, rows]) => (
                        <Panel
                            key={scope}
                            title={scope}
                            description={
                                scope === tc('console.all_apps_01bed311')
                                    ? tc(
                                          'console.in_the_tokens_of_every_app_in_every_organiza_6a811d97',
                                      )
                                    : tc(
                                          'console.only_in_tokens_for_value0_in_every_organizat_7e530a50',
                                          { value0: scope },
                                      )
                            }
                        >
                            <ul className="-my-1">
                                {rows.map((grant, index) => (
                                    <li
                                        key={`${grant.userId}:${grant.role.id}`}
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5"
                                        style={
                                            index < rows.length - 1
                                                ? { borderBottom: '1px solid var(--border)' }
                                                : undefined
                                        }
                                    >
                                        <Link
                                            href={grant.userHref}
                                            className="min-w-0 flex-1 basis-48"
                                        >
                                            <span
                                                className="block truncate text-sm font-medium"
                                                style={{ color: 'var(--accent-strong)' }}
                                            >
                                                {grant.userName ?? grant.userEmail ?? grant.userId}
                                            </span>
                                            {grant.userName !== null &&
                                                grant.userEmail !== null && (
                                                    <span
                                                        className="block truncate text-xs"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        {grant.userEmail}
                                                    </span>
                                                )}
                                        </Link>

                                        <span className="inline-flex items-center gap-1.5">
                                            <Badge>{grant.role.name}</Badge>
                                            {grant.role.staffOnly && (
                                                <Badge tone="info">
                                                    {tc('console.admins_support_only_bc5dfbd2')}
                                                </Badge>
                                            )}
                                        </span>

                                        <Button
                                            size="sm"
                                            variant="danger"
                                            className="shrink-0"
                                            onClick={() => setRevoking(grant)}
                                        >
                                            {tc('console.take_back_7d2a64f5')}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    ))
                )}
            </div>

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                name={revoking?.userName ?? revoking?.userEmail ?? ''}
                title={tc('console.take_back_value0_from_value1_a2cec092', {
                    value0: revoking?.role.name ?? '',
                    value1: revoking?.userName ?? revoking?.userEmail ?? '',
                })}
                actionLabel={tc('console.take_back_7d2a64f5')}
                consequence={tc('console.they_lose_this_role_in_every_organization_at_2750f7e3')}
                onConfirm={() => {
                    const grant = revoking;
                    setRevoking(null);

                    if (grant !== null) {
                        router.delete(grant.revokeHref, { preserveScroll: true });
                    }
                }}
            />
        </>
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
    const { t: tc } = useTranslator();

    const form = useForm({ email: '', role: '' });

    if (roles.length === 0) {
        return (
            <Panel title={tc('console.grant_an_admin_support_role_ebbcd7de')}>
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.there_is_no_role_to_grant_yet_define_one_on__e3341872')}{' '}
                    <Link
                        href={rolesHref}
                        className="underline underline-offset-2"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {tc('console.roles_c2533705')}
                    </Link>{' '}
                    {tc('console.page_or_let_an_app_declare_its_own_in_its_ma_8f3988ab')}
                </p>
            </Panel>
        );
    }

    return (
        <Panel
            title={tc('console.grant_an_admin_support_role_ebbcd7de')}
            description={tc('console.segregation_of_duties_rules_are_checked_in_e_8204cb95')}
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
                <Field label={tc('console.person_6007db63')} error={form.errors.email}>
                    <Input
                        name="email"
                        type="email"
                        placeholder="name@example.com"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>

                <Field label={tc('console.role_14736a2e')} error={form.errors.role}>
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
                    {tc('console.grant_78b7d037')}
                </Button>
            </form>
        </Panel>
    );
}

Staff.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
