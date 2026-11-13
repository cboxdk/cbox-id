import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { Check, Lock } from 'lucide-react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    ConfirmDelete,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
} from '@/ui';

interface CatalogEntry {
    id: string;
    name: string;
    description: string | null;
    app: string | null;
}

interface Holder {
    /** Stable across redraws: the holder and the scope they hold it in. */
    id: string;
    name: string;
    email: string;
    /** The organization the grant is scoped to, or null for every one of them. */
    scope: string | null;
}

type Props = PageProps<{
    role: {
        id: string;
        name: string;
        description: string | null;
        app: string | null;
    };
    readOnly: boolean;
    declaredByApp: boolean;
    pendingDefinition: {
        version: string;
        permissions: string[];
        currentPermissions: string[];
        tenantAssignable: boolean;
        currentTenantAssignable: boolean;
        activateUrl: string | null;
    } | null;
    catalog: CatalogEntry[];
    granted: string[];
    holders: Holder[];
    /** Where a permission is written by hand; null where this console has no such page. */
    permissionsHref: string | null;
    indexHref: string;
    urls: {
        update: string;
        permissions: string;
        destroy: string;
    };
}>;

export default function RoleDetail({
    role,
    readOnly,
    declaredByApp,
    pendingDefinition,
    catalog,
    granted,
    holders,
    permissionsHref,
    indexHref,
    urls,
}: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [confirming, setConfirming] = useState(false);
    const [confirmingActivation, setConfirmingActivation] = useState<string | null>(null);
    const activationVersion = pendingDefinition?.version ?? '';
    const activation = useForm({ version: activationVersion });
    const details = useForm({
        name: role.name,
        description: role.description ?? '',
    });

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.roles_c2533705')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{role.name}</h1>
                    {/*
                        WHY it is read-only is two different sentences, and the badge is
                        where they are told apart: the app that declares this role owns it,
                        or the environment does.
                    */}
                    {declaredByApp ? (
                        <Badge>
                            <Lock className="h-3 w-3" aria-hidden />
                            {consoleT('console.managed_by_the_app_d9ecd7dd')}
                        </Badge>
                    ) : readOnly ? (
                        <Badge>{consoleT('console.managed_for_the_environment_502c8ccb')}</Badge>
                    ) : (
                        <Badge>{consoleT('console.roles_badge_custom')}</Badge>
                    )}
                    {role.app !== null ? (
                        <Badge>
                            {consoleRich('console._part1_part2_only_5ce234b9', {
                                part1: <Icon name="clients" className="w-3 h-3" />,
                                part2: role.app,
                            })}
                        </Badge>
                    ) : (
                        <Badge>{consoleT('console.all_apps_01bed311')}</Badge>
                    )}
                </div>
                {role.description !== null && (
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {role.description}
                    </p>
                )}
                <p className="mt-1 text-xs mono" style={{ color: 'var(--faint)' }}>
                    {role.id}
                </p>
            </div>

            <RoleSummary
                permissions={readOnly ? catalog : catalog.filter((p) => granted.includes(p.id))}
                holders={holders}
                declaredByApp={declaredByApp}
                readOnly={readOnly}
                app={role.app}
            />

            {pendingDefinition !== null && (
                <Panel
                    title={consoleT('console.review_updated_permissions_2fc73116')}
                    description={consoleT(
                        'console.the_app_published_a_new_definition_existing_access_stay_79254863',
                    )}
                >
                    <div className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <h2 className="font-medium">
                                    {consoleT('console.permissions_added_9f67c5d3')}
                                </h2>
                                <ul className="mt-2 space-y-1 text-sm mono">
                                    {pendingDefinition.permissions
                                        .filter(
                                            (permission) =>
                                                !pendingDefinition.currentPermissions.includes(
                                                    permission,
                                                ),
                                        )
                                        .map((permission) => (
                                            <li key={permission}>{permission}</li>
                                        ))}
                                </ul>
                                {pendingDefinition.permissions.every((permission) =>
                                    pendingDefinition.currentPermissions.includes(permission),
                                ) && <p className="text-sm">{consoleT('console.none_dc937b59')}</p>}
                            </div>
                            <div>
                                <h2 className="font-medium">
                                    {consoleT('console.permissions_removed_e2aa0f3d')}
                                </h2>
                                <ul className="mt-2 space-y-1 text-sm mono">
                                    {pendingDefinition.currentPermissions
                                        .filter(
                                            (permission) =>
                                                !pendingDefinition.permissions.includes(permission),
                                        )
                                        .map((permission) => (
                                            <li key={permission}>{permission}</li>
                                        ))}
                                </ul>
                                {pendingDefinition.currentPermissions.every((permission) =>
                                    pendingDefinition.permissions.includes(permission),
                                ) && <p className="text-sm">{consoleT('console.none_dc937b59')}</p>}
                            </div>
                        </div>
                        <p className="text-sm">
                            {consoleRich(
                                'console.organizations_can_assign_this_role_part1_part2_part3_pa_3d8962bf',
                                {
                                    part1: ' ',
                                    part2: pendingDefinition.currentTenantAssignable
                                        ? consoleText('console.yes_85a39ab3')
                                        : consoleText('console.no_1ea442a1'),
                                    part3: ' ',
                                    part4: pendingDefinition.tenantAssignable
                                        ? consoleText('console.yes_85a39ab3')
                                        : consoleText('console.no_1ea442a1'),
                                },
                            )}
                        </p>
                        <p className="text-sm">
                            {consoleT(
                                'console.activating_changes_access_for_every_existing_holder_of_f6f53ab3',
                            )}
                        </p>
                        {activation.errors.version && (
                            <p role="alert" className="text-sm text-destructive">
                                {activation.errors.version}
                            </p>
                        )}
                        {pendingDefinition.activateUrl !== null && (
                            <Button
                                disabled={activation.processing}
                                onClick={() => {
                                    activation.clearErrors();
                                    setConfirmingActivation(activationVersion);
                                }}
                            >
                                {consoleT('console.activate_reviewed_definition_e9e10728')}
                            </Button>
                        )}
                    </div>
                    <ConfirmDelete
                        open={
                            confirmingActivation !== null &&
                            confirmingActivation === activationVersion
                        }
                        onOpenChange={(open) =>
                            setConfirmingActivation(open ? activationVersion : null)
                        }
                        name={role.name}
                        verb={consoleShortText('console.activate_24433c70')}
                        actionLabel={consoleUiText('console.activate_definition_910bf848')}
                        consequence={consoleUiText(
                            'console.this_changes_permissions_for_all_current_holders_of_thi_a515eb89',
                        )}
                        confirming={activation.processing}
                        onConfirm={() => {
                            if (pendingDefinition.activateUrl !== null) {
                                activation.transform(() => ({
                                    version: confirmingActivation ?? '',
                                }));
                                activation.post(pendingDefinition.activateUrl, {
                                    preserveScroll: true,
                                    onSuccess: () => setConfirmingActivation(null),
                                });
                            }
                        }}
                    />
                </Panel>
            )}

            {readOnly && (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {declaredByApp
                        ? consoleText(
                              'console.this_role_is_declared_by_an_application_which_is_its_so_03e7843e',
                          )
                        : consoleText(
                              'console.this_role_belongs_to_the_environment_and_applies_across_0a2b2ed6',
                          )}
                </p>
            )}

            <Permissions
                readOnly={readOnly}
                declaredByApp={declaredByApp}
                catalog={catalog}
                granted={granted}
                href={urls.permissions}
                permissionsHref={permissionsHref}
            />

            {/*
                WHO HOLDS IT. Nothing in the console answered this: an access review
                enumerates one organization's grants, and an environment-wide grant belongs
                to none — so the only way to learn who held a role was to open every user
                page in turn. A role whose holders you cannot see is a role you cannot
                govern.
            */}
            <Panel
                title={consoleT('console.who_holds_this_75c8257c')}
                description={
                    holders.length === 0
                        ? undefined
                        : consoleFinalText('console.role_holder_grants_summary', {
                              count: holders.length,
                          })
                }
                flush={holders.length > 0}
            >
                {holders.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.nobody_yet_grant_it_on_a_person_s_page_inside_one_organ_851e1d0d',
                        )}
                    </p>
                ) : (
                    <div>
                        {holderGroups(holders).map(([scope, people]) => (
                            <section key={scope ?? '*'}>
                                <div className="idc-rows-group">
                                    <h3>
                                        {scope === null
                                            ? consoleT('console.roles_holders_everywhere')
                                            : scope}
                                    </h3>
                                    <p>
                                        {scope === null
                                            ? consoleT('console.roles_holders_everywhere_lead')
                                            : consoleT('console.roles_holders_in_org_lead')}
                                    </p>
                                </div>
                                {people.map((holder) => (
                                    <div key={holder.id} className="idc-row">
                                        <div className="idc-row-main">
                                            <span className="idc-row-title text-sm">
                                                {holder.name}
                                            </span>
                                            <span className="idc-row-sub">
                                                <span>{holder.email}</span>
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </section>
                        ))}
                    </div>
                )}
            </Panel>

            {!readOnly && (
                <Panel title={consoleT('console.details_45989de4')}>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            details.patch(urls.update, { preserveScroll: true });
                        }}
                    >
                        <Field
                            label={consoleT('console.name_dcd1d522')}
                            error={details.errors.name}
                        >
                            <Input
                                name="name"
                                value={details.data.name}
                                onChange={(event) => details.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={consoleT('console.description_526e0087')}
                            optional
                            error={details.errors.description}
                        >
                            <Input
                                name="description"
                                value={details.data.description}
                                onChange={(event) =>
                                    details.setData('description', event.target.value)
                                }
                            />
                        </Field>
                        <Button type="submit" variant="primary" loading={details.processing}>
                            {consoleT('console.save_changes_dd0ae7a5')}
                        </Button>
                    </form>
                </Panel>
            )}

            {!readOnly && (
                <Panel
                    title={consoleT('console.delete_role_ac18d11a')}
                    description={consoleT(
                        'console.everyone_currently_assigned_this_role_loses_the_access_f7fa28ec',
                    )}
                >
                    <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                        {consoleT('console.delete_role_ac18d11a')}
                    </Button>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={role.name}
                consequence={consoleUiText(
                    'console.everyone_currently_assigned_this_role_loses_the_access_5141955e',
                )}
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

interface PermissionGroup {
    /** The app whose permissions these are, or null for the ones every app shares. */
    app: string | null;
    resources: { resource: string; permissions: CatalogEntry[] }[];
}

/** `invoices:read` → `invoices`; a key with no separator is its own resource. */
function resourceOf(key: string): string {
    const match = /^(.+?)[:.]/.exec(key);

    return match?.[1] ?? key;
}

/**
 * The catalogue as a person reads it: by app, then by the resource each key names
 * (`invoices:read`, `invoices:void` → Invoices), in a stable order.
 */
function grouped(catalog: CatalogEntry[]): PermissionGroup[] {
    const apps = new Map<string, Map<string, CatalogEntry[]>>();

    for (const permission of catalog) {
        const app = permission.app ?? '';
        const resources = apps.get(app) ?? new Map<string, CatalogEntry[]>();
        const resource = resourceOf(permission.name);
        resources.set(resource, [...(resources.get(resource) ?? []), permission]);
        apps.set(app, resources);
    }

    return [...apps.entries()]
        .sort(([a], [b]) => (a === '' ? -1 : b === '' ? 1 : a.localeCompare(b)))
        .map(([app, resources]) => ({
            app: app === '' ? null : app,
            resources: [...resources.entries()]
                .sort(([a], [b]) => a.localeCompare(b))
                .map(([resource, permissions]) => ({
                    resource,
                    permissions: [...permissions].sort((x, y) => x.name.localeCompare(y.name)),
                })),
        }));
}

/** `customer_records` → `Customer records`. */
function humanResource(resource: string): string {
    const words = resource.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * What this role is allowed to do — grouped by app and by resource, each permission in
 * words (the description its app gave it) with the raw key beside it in mono.
 *
 * Each tick writes on its own — there is no Save — so the checkbox is disabled for the
 * round-trip: without that, a second click before the first lands toggles it straight
 * back, and the two requests race to decide which state the role ends in. The server takes
 * an explicit grant/revoke rather than a toggle for the same reason.
 */
function Permissions({
    readOnly,
    declaredByApp,
    catalog,
    granted,
    href,
    permissionsHref,
}: {
    readOnly: boolean;
    declaredByApp: boolean;
    catalog: CatalogEntry[];
    granted: string[];
    href: string;
    permissionsHref: string | null;
}) {
    const { t, rich } = useConsoleTranslator();
    const [pending, setPending] = useState<string | null>(null);
    const [filter, setFilter] = useState('');

    const set = (permission: string, isGranted: boolean): void => {
        setPending(permission);

        router.post(
            href,
            { permission, granted: isGranted },
            { preserveScroll: true, onFinish: () => setPending(null) },
        );
    };

    const needle = filter.trim().toLowerCase();
    const shown =
        needle === ''
            ? catalog
            : catalog.filter(
                  (permission) =>
                      permission.name.toLowerCase().includes(needle) ||
                      (permission.description ?? '').toLowerCase().includes(needle) ||
                      (permission.app ?? '').toLowerCase().includes(needle),
              );
    const groups = grouped(shown);
    const heldHere = catalog.filter((permission) => granted.includes(permission.id)).length;

    const description = readOnly
        ? declaredByApp
            ? t('console.what_this_role_is_allowed_to_do_set_by_the_application_9635e889')
            : t('console.what_this_role_is_allowed_to_do_set_for_the_whole_envir_e0d3c3a0')
        : t('console.what_this_role_is_allowed_to_do_tick_a_permission_to_gr_518298cc');

    if (catalog.length === 0) {
        return (
            <Panel title={t('console.permissions_abccc78c')} description={description}>
                {readOnly ? (
                    <EmptyState
                        icon="key"
                        title={t('console.no_permissions_ef5bb514')}
                        description={
                            declaredByApp
                                ? t(
                                      'console.this_role_grants_no_permissions_the_application_that_de_59b043d0',
                                  )
                                : t('console.this_role_grants_no_permissions_you_can_see_ff65b6fc')
                        }
                    />
                ) : (
                    <EmptyState
                        icon="key"
                        title={t('console.no_permissions_declared_7ed6ab7b')}
                        description={
                            permissionsHref === null
                                ? t(
                                      'console.an_app_registers_its_catalog_over_the_sdk_the_keys_it_d_0b415765',
                                  )
                                : t(
                                      'console.an_app_registers_its_catalog_over_the_sdk_or_you_write_7b5aa437',
                                  )
                        }
                        actions={
                            permissionsHref === null ? undefined : (
                                <Button asChild>
                                    <Link href={permissionsHref}>
                                        {rich('console._part1_write_a_permission_97ac7762', {
                                            part1: <Icon name="plus" className="w-4 h-4" />,
                                        })}
                                    </Link>
                                </Button>
                            )
                        }
                    />
                )}
            </Panel>
        );
    }

    return (
        <Panel
            title={t('console.permissions_abccc78c')}
            description={description}
            action={
                readOnly ? undefined : (
                    <Badge>
                        {t('console.role_permissions_granted_of', {
                            granted: heldHere,
                            total: catalog.length,
                        })}
                    </Badge>
                )
            }
            flush
        >
            {catalog.length > 8 && (
                <div className="px-4 pt-4 pb-2">
                    <Input
                        type="search"
                        style={{ maxWidth: '22rem' }}
                        placeholder={t('console.search_permissions_267b21e2')}
                        aria-label={t('console.search_permissions_267b21e2')}
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                    />
                </div>
            )}

            {groups.length === 0 && (
                <p className="px-4 py-6 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {t('console.no_permission_matches_that_acbdba7a')}
                </p>
            )}

            {groups.map((group) => (
                <section key={group.app ?? ''} className="idc-perm-group">
                    <div className="idc-rows-group">
                        <h3>{group.app ?? t('console.all_apps_01bed311')}</h3>
                        <p>
                            {group.app === null
                                ? t('console.roles_perm_group_shared')
                                : t('console.roles_perm_group_app')}
                        </p>
                    </div>
                    {group.resources.map(({ resource, permissions }) => {
                        const held = permissions.filter((p) => granted.includes(p.id)).length;

                        return (
                            <div key={resource}>
                                <div className="idc-perm-group-head">
                                    <span>{humanResource(resource)}</span>
                                    {!readOnly && (
                                        <span
                                            className="ml-auto mono text-xs"
                                            style={{ color: 'var(--faint)', fontWeight: 400 }}
                                        >
                                            {held}/{permissions.length}
                                        </span>
                                    )}
                                </div>
                                {permissions.map((permission) =>
                                    readOnly ? (
                                        <div key={permission.id} className="idc-perm">
                                            <Check
                                                className="mt-0.5 h-4 w-4 shrink-0"
                                                style={{ color: 'var(--success-strong)' }}
                                                strokeWidth={2}
                                                aria-hidden
                                            />
                                            <PermissionLabel permission={permission} />
                                        </div>
                                    ) : (
                                        <div key={permission.id} className="idc-perm">
                                            <Checkbox
                                                checked={granted.includes(permission.id)}
                                                disabled={pending === permission.id}
                                                onCheckedChange={(checked) =>
                                                    set(permission.id, checked)
                                                }
                                                label={<PermissionLabel permission={permission} />}
                                            />
                                        </div>
                                    ),
                                )}
                            </div>
                        );
                    })}
                </section>
            ))}

            {/* Each tick writes immediately; SC 4.1.3 needs that reported. */}
            {!readOnly && (
                <output aria-live="polite" className="sr-only">
                    {rich('console._part1_of_part2_permissions_granted_ab77a171', {
                        part1: granted.length,
                        part2: catalog.length,
                    })}
                </output>
            )}
        </Panel>
    );
}

/** A permission in words, with its key — what the app checks — beside it in mono. */
function PermissionLabel({ permission }: { permission: CatalogEntry }) {
    return (
        <span className="flex flex-col">
            <span className="text-sm">{permission.description ?? permission.name}</span>
            {permission.description !== null && (
                <span className="idc-perm-key">{permission.name}</span>
            )}
        </span>
    );
}

/** Holders by where they hold it: everywhere first, then each organization. */
function holderGroups(holders: Holder[]): [string | null, Holder[]][] {
    const groups = new Map<string | null, Holder[]>();

    for (const holder of holders) {
        groups.set(holder.scope, [...(groups.get(holder.scope) ?? []), holder]);
    }

    return [...groups.entries()].sort(([a], [b]) =>
        a === null ? -1 : b === null ? 1 : a.localeCompare(b),
    );
}

/**
 * THE ROLE IN ONE LINE OF NUMBERS: how much it allows, across how many apps, and how many
 * people hold it — before the lists that say which.
 */
function RoleSummary({
    permissions,
    holders,
    declaredByApp,
    readOnly,
    app,
}: {
    permissions: CatalogEntry[];
    holders: Holder[];
    declaredByApp: boolean;
    readOnly: boolean;
    app: string | null;
}) {
    const { t } = useConsoleTranslator();
    const apps = new Set(permissions.map((p) => p.app ?? '')).size;
    const people = new Set(holders.map((h) => h.id.split('@')[0])).size;

    return (
        <div className="grid gap-3 sm:grid-cols-3">
            <Stat
                label={t('console.roles_summary_permissions')}
                value={permissions.length}
                caption={t('console.roles_summary_apps', { count: apps })}
            />
            <Stat
                label={t('console.roles_summary_people')}
                value={people}
                caption={t('console.roles_summary_grants', { count: holders.length })}
            />
            <Stat
                label={t('console.roles_summary_source')}
                value={
                    declaredByApp
                        ? t('console.roles_section_app', { app: app ?? '' })
                        : t('console.roles_badge_custom')
                }
                caption={
                    declaredByApp
                        ? t('console.roles_summary_source_app')
                        : readOnly
                          ? t('console.roles_summary_source_environment')
                          : t('console.roles_summary_source_custom')
                }
            />
        </div>
    );
}

function Stat({
    label,
    value,
    caption,
}: {
    label: string;
    value: React.ReactNode;
    caption: string;
}) {
    return (
        <div className="card p-4">
            <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {label}
            </p>
            <p className="mt-1 text-lg font-semibold" style={{ overflowWrap: 'anywhere' }}>
                {value}
            </p>
            <p className="mt-0.5 text-xs" style={{ color: 'var(--faint)' }}>
                {caption}
            </p>
        </div>
    );
}

RoleDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
