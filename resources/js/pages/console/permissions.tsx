import { useTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    EmptyState,
    Field,
    Input,
    PageHeader,
    Panel,
    AccessModelGuide,
} from '@/ui';

interface PermissionRow {
    id: string;
    name: string;
    description: string | null;
    /** The app that declares it — null for one authored here. */
    app: string | null;
    tenantAssignable: boolean;
    /** The app stopped declaring it. Kept rather than deleted, so roles keep resolving. */
    orphaned: boolean;
    /** How many of the roles in view grant it. */
    roleCount: number;
    urls: { update: string; destroy: string };
}

interface DeclaringApp {
    app: string;
    permissions: PermissionRow[];
}

type Props = PageProps<{
    help: HelpContent;
    mine: PermissionRow[];
    inherited: PermissionRow[];
    declared: DeclaringApp[];
    declaredTotal: number;
    declaredShown: number;
    /** True on the environment plane: what this page writes is shared with every tenant. */
    sharesEnvironment: boolean;
    search: string;
    clientsHref: string;
    urls: { store: string };
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function Permissions({
    help,
    mine,
    inherited,
    declared,
    declaredTotal,
    declaredShown,
    sharesEnvironment,
    search,
    clientsHref,
    urls,
}: Props) {
    const { t: tc } = useTranslator();

    const [term, setTerm] = useState(search);

    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    const matching = mine.length + inherited.length + declaredTotal;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={tc('console.everything_a_role_can_be_allowed_to_do_write_233ea6c4')}
            />

            {/*
                The other half of the sentence, said where the confusion happens. A
                permission and a scope are different things sharing one word, and the page
                each lives on is the only place a reader can be told which they are looking
                at.
            */}
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.a_permission_is_what_a_be68069f')}
                <b>{tc('console.person_38a81e87')}</b>
                {tc('console.may_do_once_signed_in_what_an_cb52a7c0')}
                <b>{tc('console.app_a172cedc')}</b>
                {tc('console.may_ask_for_is_a_scope_set_when_you_register_ac316cd6')}{' '}
                <Link href={clientsHref} className="underline">
                    {tc('console.apps_89dd7484')}
                </Link>
                .
            </p>

            <NewPermission sharesEnvironment={sharesEnvironment} storeHref={urls.store} />

            <div>
                <Input
                    type="search"
                    className="w-full"
                    aria-label={tc('console.search_permissions_by_key_or_description_b2c0aa0c')}
                    placeholder={tc('console.search_permissions_by_key_or_description_b2c0aa0c')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the lists below are replaced on a debounced keystroke with no
                    focus change, so this is the only thing that can tell somebody using a
                    screen reader that the search found nothing.
                */}
                <output className="mt-1 block text-xs" style={{ color: 'var(--faint)' }}>
                    {search !== '' &&
                        `${matching} matching ${matching === 1 ? 'permission' : 'permissions'}`}
                </output>
            </div>

            <Panel
                title={
                    sharesEnvironment ? tc('console.manual_b0b9fe24') : tc('console.yours_54b26f7d')
                }
                description={tc('console.authored_here_editable_and_removable_1870b92e')}
                action={<Badge>{mine.length}</Badge>}
            >
                {mine.length === 0 ? (
                    <EmptyState
                        icon="key"
                        title={tc('console.you_haven_t_written_any_yet_7136efde')}
                        description={tc(
                            'console.add_one_above_to_build_your_own_roles_no_int_7a7954a8',
                        )}
                    />
                ) : (
                    <div className="space-y-2">
                        {mine.map((permission) => (
                            <ManualRow
                                key={permission.id}
                                permission={permission}
                                sharesEnvironment={sharesEnvironment}
                            />
                        ))}
                    </div>
                )}
            </Panel>

            {/*
                Inherited from the environment — organization plane only. On the environment
                plane these ARE the list above, and drawing them twice would suggest two
                catalogues where there is one.

                Shown rather than hidden, and read-only rather than editable: a tenant
                composes roles out of these, so a page that omitted them would explain only
                half of what the roles editor offers.
            */}
            {!sharesEnvironment && (
                <Panel
                    title={tc('console.from_your_environment_cb0abff0')}
                    description={tc(
                        'console.provided_for_every_organization_here_yours_t_7d86c809',
                    )}
                    action={<Badge>{inherited.length}</Badge>}
                >
                    {inherited.length === 0 ? (
                        <EmptyState
                            icon="key"
                            title={tc('console.nothing_shared_with_you_yet_df9f8d1a')}
                            description={tc(
                                'console.permissions_your_environment_publishes_for_e_eabd41a4',
                            )}
                        />
                    ) : (
                        <div className="space-y-2">
                            {inherited.map((permission) => (
                                <ReadOnlyRow
                                    key={permission.id}
                                    permission={permission}
                                    tone="Shared"
                                    usageSuffix={tc('console.of_your_9cec8b5e')}
                                />
                            ))}
                        </div>
                    )}
                </Panel>
            )}

            <Panel
                title={tc('console.app_declared_573cf28e')}
                description={tc('console.synced_from_each_app_s_manifest_over_the_sdk_4af9333b')}
                action={<Badge>{declaredTotal}</Badge>}
            >
                {declared.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="key"
                            title={tc('console.no_app_permission_matches_value0_4ef9b1ee', {
                                value0: search,
                            })}
                            description={tc(
                                'console.clear_the_search_to_see_the_whole_catalog_ebbf157e',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            title={tc('console.no_app_has_registered_a_catalog_yet_877fed0b')}
                            description={tc(
                                'console.once_an_app_declares_its_permissions_through_2001981d',
                            )}
                        />
                    )
                ) : (
                    <div className="space-y-4">
                        {declared.map((group) => (
                            <div key={group.app}>
                                <p
                                    className="text-xs font-semibold uppercase mb-1.5"
                                    style={{
                                        color: 'var(--muted-foreground)',
                                        letterSpacing: '0.05em',
                                    }}
                                >
                                    {group.app}
                                </p>
                                <div className="space-y-2">
                                    {group.permissions.map((permission) => (
                                        <ReadOnlyRow key={permission.id} permission={permission} />
                                    ))}
                                </div>
                            </div>
                        ))}

                        {declaredShown < declaredTotal && (
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {tc('console.showing_7282e1fb')}
                                {declaredShown}
                                {tc('console.of_a4282e4b')}
                                {declaredTotal}
                                {tc('console.search_to_reach_the_rest_598edbc6')}
                            </p>
                        )}
                    </div>
                )}
            </Panel>
            <AccessModelGuide current="permissions" />
        </div>
    );
}

/** Author a manual permission. */
function NewPermission({
    sharesEnvironment,
    storeHref,
}: {
    sharesEnvironment: boolean;
    storeHref: string;
}) {
    const { t: tc } = useTranslator();

    const form = useForm({ name: '', description: '', tenantAssignable: true });

    return (
        <Panel
            title={tc('console.new_permission_8ef9b408')}
            description={
                <>
                    A <code className="mono">{tc('console.feature_action_b0ceaf12')}</code>
                    {tc('console.key_you_can_compose_into_roles_e_g_ea9aef3f')}
                    <code className="mono">{tc('console.invoices_create_be35ad14')}</code>.{' '}
                    {/*
                        Says who gets it, before the button is pressed. The two planes write
                        into different tiers and the form is otherwise identical, which is
                        exactly the invisible difference that had a tenant admin editing the
                        whole environment's catalogue believing it was their own.
                    */}
                    <b>
                        {sharesEnvironment
                            ? tc('console.available_to_every_organization_in_this_envi_91f07f63')
                            : tc('console.yours_alone_other_organizations_never_see_it_fba9d3ce')}
                    </b>
                </>
            }
        >
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, {
                        preserveScroll: true,
                        onSuccess: () => form.reset('name', 'description'),
                    });
                }}
            >
                <div className="grid sm:grid-cols-[1fr_1.4fr_auto] gap-2 items-start">
                    <Field label={tc('console.permission_key_1e6e7d30')} error={form.errors.name}>
                        <Input
                            name="name"
                            className="mono"
                            placeholder={tc('console.invoices_create_be35ad14')}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={tc('console.description_526e0087')}
                        optional
                        error={form.errors.description}
                    >
                        <Input
                            name="description"
                            placeholder={tc('console.create_invoices_92872624')}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </Field>
                    <Button
                        type="submit"
                        variant="primary"
                        className="shrink-0 self-end"
                        loading={form.processing}
                    >
                        {tc('console.add_permission_60183b7c')}
                    </Button>
                </div>

                {/*
                    Only on the plane where it decides anything. On the organization plane
                    the row already belongs to the organization, so "may org admins use
                    this" has one possible answer, and offering the checkbox invites an
                    administrator to untick their own permission into uselessness.
                */}
                {sharesEnvironment && (
                    <Checkbox
                        checked={form.data.tenantAssignable}
                        onCheckedChange={(checked) => form.setData('tenantAssignable', checked)}
                        label={tc('console.organizations_may_assign_4fdd115e')}
                        hint={tc('console.organization_admins_may_compose_this_into_th_73f4e50e')}
                    />
                )}
            </form>
        </Panel>
    );
}

/** One permission this administrator owns: editable in place, and removable. */
function ManualRow({
    permission,
    sharesEnvironment,
}: {
    permission: PermissionRow;
    sharesEnvironment: boolean;
}) {
    const { t: tc } = useTranslator();

    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);

    /*
     * FOCUS FOLLOWS THE FORM. The row is replaced in place when Edit is pressed, so the
     * button that had focus stops existing — and focus falls back to <body>, which for
     * anybody navigating by keyboard means the page silently rewound to the top. Moved
     * explicitly rather than with `autoFocus`, which would also fire on a re-render the
     * person did not ask for.
     */
    const description = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (editing) {
            description.current?.focus();
        }
    }, [editing]);

    const form = useForm({
        description: permission.description ?? '',
        tenantAssignable: permission.tenantAssignable,
    });

    return (
        <div className="rounded-lg border px-3 py-2" style={{ borderColor: 'var(--border)' }}>
            {editing ? (
                <form
                    className="space-y-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(permission.urls.update, {
                            preserveScroll: true,
                            onSuccess: () => setEditing(false),
                        });
                    }}
                >
                    <p className="text-sm mono">{permission.name}</p>

                    <Field
                        label={tc('console.description_526e0087')}
                        optional
                        error={form.errors.description}
                    >
                        <Input
                            ref={description}
                            name="description"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </Field>

                    {sharesEnvironment && (
                        <Checkbox
                            checked={form.data.tenantAssignable}
                            onCheckedChange={(checked) => form.setData('tenantAssignable', checked)}
                            label={tc('console.organizations_may_assign_4fdd115e')}
                        />
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                            {tc('console.save_1509f561')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                form.reset();
                                setEditing(false);
                            }}
                        >
                            {tc('console.cancel_19766ed6')}
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="flex items-center gap-2">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-sm mono truncate">{permission.name}</span>
                            <Badge>{tc('console.manual_b0b9fe24')}</Badge>
                            {!permission.tenantAssignable && (
                                <Badge tone="warn">{tc('console.internal_2ea1842b')}</Badge>
                            )}
                            <RoleCount count={permission.roleCount} />
                        </div>
                        {permission.description !== null && (
                            <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                {permission.description}
                            </p>
                        )}
                    </div>

                    <Button size="sm" className="shrink-0" onClick={() => setEditing(true)}>
                        {tc('console.edit_464c4ffd')}
                    </Button>
                    <Button
                        size="sm"
                        variant="danger"
                        className="shrink-0"
                        onClick={() => setConfirming(true)}
                    >
                        {tc('console.delete_e2d0a549')}
                    </Button>
                </div>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={permission.name}
                consequence={tc('console.this_permission_is_removed_from_every_role_t_d88d6738')}
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(permission.urls.destroy, { preserveScroll: true });
                }}
            />
        </div>
    );
}

/** A permission somebody else owns — the environment's, or an app's. */
function ReadOnlyRow({
    permission,
    tone = 'App',
    usageSuffix,
}: {
    permission: PermissionRow;
    tone?: string;
    usageSuffix?: string;
}) {
    const { t: tc } = useTranslator();

    return (
        <div
            className="flex items-center gap-2 rounded-lg border px-3 py-2"
            style={{ borderColor: 'var(--border)' }}
        >
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm mono truncate">{permission.name}</span>
                    <Badge tone={tone === 'App' ? 'info' : 'neutral'}>{tone}</Badge>
                    {permission.orphaned && (
                        <Badge tone="warn">{tc('console.orphaned_8d827807')}</Badge>
                    )}
                    {!permission.tenantAssignable && (
                        <Badge>{tc('console.internal_2ea1842b')}</Badge>
                    )}
                    <RoleCount count={permission.roleCount} suffix={usageSuffix} />
                </div>
                {permission.description !== null && (
                    <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                        {permission.description}
                    </p>
                )}
            </div>
        </div>
    );
}

/** What deleting this would strip: how many of the roles in view grant it. */
function RoleCount({ count, suffix }: { count: number; suffix?: string }) {
    const { t: tc } = useTranslator();

    if (count === 0) {
        return null;
    }

    return (
        <span className="text-xs" style={{ color: 'var(--faint)' }}>
            {tc('console.in_74241f65')}
            {count} {suffix !== undefined && `${suffix} `}
            {count === 1 ? tc('console.role_4b168d88') : tc('console.roles_66cf5513')}
        </span>
    );
}

Permissions.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
