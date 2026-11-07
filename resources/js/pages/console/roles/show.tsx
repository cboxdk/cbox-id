import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
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
    Kv,
    KvList,
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
                        <Badge>{consoleT('console.managed_by_the_app_d9ecd7dd')}</Badge>
                    ) : (
                        readOnly && (
                            <Badge>
                                {consoleT('console.managed_for_the_environment_502c8ccb')}
                            </Badge>
                        )
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
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {role.id}
                </p>
            </div>

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

            <Panel
                title={consoleT('console.details_45989de4')}
                description={
                    readOnly
                        ? declaredByApp
                            ? consoleText(
                                  'console.this_role_is_declared_by_an_application_which_is_its_so_03e7843e',
                              )
                            : consoleText(
                                  'console.this_role_belongs_to_the_environment_and_applies_across_0a2b2ed6',
                              )
                        : undefined
                }
            >
                {readOnly ? (
                    <KvList>
                        <Kv label={consoleT('console.name_dcd1d522')}>{role.name}</Kv>
                        <Kv label={consoleT('console.description_526e0087')}>
                            {role.description ?? '—'}
                        </Kv>
                    </KvList>
                ) : (
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
                )}
            </Panel>

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
                        {holders.map((holder, index) => (
                            <div
                                key={holder.id}
                                className="flex items-center gap-3 flex-wrap px-4 py-2.5"
                                style={
                                    index === holders.length - 1
                                        ? undefined
                                        : { borderBottom: '1px solid var(--border)' }
                                }
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium truncate">{holder.name}</p>
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {holder.email}
                                    </p>
                                </div>
                                {holder.scope === null ? (
                                    // What "Everywhere" means is in the panel description
                                    // above, not a tooltip on each row.
                                    <Badge>{consoleT('console.everywhere_dcc10bfd')}</Badge>
                                ) : (
                                    <Badge>{holder.scope}</Badge>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

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

/**
 * What this role is allowed to do.
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
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [pending, setPending] = useState<string | null>(null);

    const set = (permission: string, isGranted: boolean): void => {
        setPending(permission);

        router.post(
            href,
            { permission, granted: isGranted },
            { preserveScroll: true, onFinish: () => setPending(null) },
        );
    };

    return (
        <Panel
            title={consoleT('console.permissions_abccc78c')}
            description={
                readOnly
                    ? declaredByApp
                        ? consoleText(
                              'console.what_this_role_is_allowed_to_do_set_by_the_application_9635e889',
                          )
                        : consoleText(
                              'console.what_this_role_is_allowed_to_do_set_for_the_whole_envir_e0d3c3a0',
                          )
                    : consoleText(
                          'console.what_this_role_is_allowed_to_do_tick_a_permission_to_gr_518298cc',
                      )
            }
        >
            {readOnly ? (
                catalog.length === 0 ? (
                    <EmptyState
                        icon="key"
                        title={consoleT('console.no_permissions_ef5bb514')}
                        description={
                            declaredByApp
                                ? consoleText(
                                      'console.this_role_grants_no_permissions_the_application_that_de_59b043d0',
                                  )
                                : consoleText(
                                      'console.this_role_grants_no_permissions_you_can_see_ff65b6fc',
                                  )
                        }
                    />
                ) : (
                    <div className="flex flex-wrap gap-1.5">
                        {catalog.map((permission) => (
                            <Badge key={permission.id} className="mono">
                                {permission.name}
                            </Badge>
                        ))}
                    </div>
                )
            ) : (
                <>
                    <div
                        className="space-y-1.5 rounded-lg border p-3"
                        style={{
                            borderColor: 'var(--border)',
                            maxHeight: '24rem',
                            overflowY: 'auto',
                        }}
                    >
                        {catalog.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title={consoleT('console.no_permissions_declared_7ed6ab7b')}
                                description={
                                    permissionsHref === null
                                        ? consoleText(
                                              'console.an_app_registers_its_catalog_over_the_sdk_the_keys_it_d_0b415765',
                                          )
                                        : consoleText(
                                              'console.an_app_registers_its_catalog_over_the_sdk_or_you_write_7b5aa437',
                                          )
                                }
                                actions={
                                    permissionsHref === null ? undefined : (
                                        <Button asChild>
                                            <Link href={permissionsHref}>
                                                {consoleRich(
                                                    'console._part1_write_a_permission_97ac7762',
                                                    {
                                                        part1: (
                                                            <Icon name="plus" className="w-4 h-4" />
                                                        ),
                                                    },
                                                )}
                                            </Link>
                                        </Button>
                                    )
                                }
                            />
                        ) : (
                            catalog.map((permission) => (
                                <Checkbox
                                    key={permission.id}
                                    checked={granted.includes(permission.id)}
                                    disabled={pending === permission.id}
                                    onCheckedChange={(checked) => set(permission.id, checked)}
                                    label={
                                        <span className="flex items-center gap-2 flex-wrap">
                                            <span className="text-sm mono">{permission.name}</span>
                                            {permission.app !== null ? (
                                                <Badge tone="info">{permission.app}</Badge>
                                            ) : (
                                                <Badge>
                                                    {consoleT('console.all_apps_01bed311')}
                                                </Badge>
                                            )}
                                        </span>
                                    }
                                    hint={permission.description ?? undefined}
                                />
                            ))
                        )}
                    </div>

                    {/* Each tick writes immediately; SC 4.1.3 needs that reported. */}
                    <output aria-live="polite" className="sr-only">
                        {consoleRich('console._part1_of_part2_permissions_granted_ab77a171', {
                            part1: granted.length,
                            part2: catalog.length,
                        })}
                    </output>
                </>
            )}
        </Panel>
    );
}

RoleDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
