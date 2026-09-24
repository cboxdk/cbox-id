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
function byScope(grants: GrantRow[]): [string, GrantRow[]][] {
    const groups = new Map<string, GrantRow[]>();

    for (const grant of grants) {
        const scope = staffRoleScope(grant.role);
        groups.set(scope, [...(groups.get(scope) ?? []), grant]);
    }

    return [...groups.entries()].sort(([a], [b]) => {
        if (a === 'All apps') {
            return -1;
        }

        if (b === 'All apps') {
            return 1;
        }

        return a.localeCompare(b);
    });
}

export default function Staff({ grants, roles, storeHref, rolesHref, reviewHref, help }: Props) {
    const [revoking, setRevoking] = useState<GrantRow | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description="A staff role is a role you grant to your own people across the whole environment: it applies in every organization, and no organization's admins can see or change it."
                actions={
                    <Button asChild className="shrink-0">
                        <Link href={reviewHref}>
                            <Icon name="shield" className="w-4 h-4" />
                            Review staff access
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
                            title="Nobody holds a staff role"
                            description="Grant one to the people who support or run your apps. An app's own role reaches only that app's tokens; a role for all apps reaches every one of them."
                        />
                    </div>
                ) : (
                    byScope(grants).map(([scope, rows]) => (
                        <Panel
                            key={scope}
                            title={scope}
                            description={
                                scope === 'All apps'
                                    ? 'In the tokens of every app, in every organization.'
                                    : `Only in ${scope}'s tokens, in every organization.`
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
                                                <Badge tone="info">Staff-only</Badge>
                                            )}
                                        </span>

                                        <Button
                                            size="sm"
                                            variant="danger"
                                            className="shrink-0"
                                            onClick={() => setRevoking(grant)}
                                        >
                                            Take back
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
                verb={`Take back “${revoking?.role.name ?? ''}” from`}
                consequence="They lose this role in every organization at once. Apps receive the change the next time they refresh the person's tokens; nobody is signed out."
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
    const form = useForm({ email: '', role: '' });

    if (roles.length === 0) {
        return (
            <Panel title="Grant a staff role">
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    There is no role to grant yet. Define one on the{' '}
                    <Link href={rolesHref} style={{ color: 'var(--accent-strong)' }}>
                        Roles
                    </Link>{' '}
                    page, or let an app declare its own in its manifest.
                </p>
            </Panel>
        );
    }

    return (
        <Panel
            title="Grant a staff role"
            description="Segregation-of-duties rules are checked in every organization the person belongs to, and a refusal says where."
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
                <Field label="Person" error={form.errors.email}>
                    <Input
                        name="email"
                        type="email"
                        placeholder="name@example.com"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>

                <Field label="Role" error={form.errors.role}>
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
                    Grant
                </Button>
            </form>
        </Panel>
    );
}

Staff.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
