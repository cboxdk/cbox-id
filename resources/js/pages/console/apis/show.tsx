import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Input,
    Panel,
    Pill,
    Select,
} from '@/ui';

/** `App\Http\Props\Console\ApiScopeProps` */
interface ScopeRow {
    id: string;
    key: string;
    description: string | null;
    tenantRequestable: boolean;
    updateHref: string;
    destroyHref: string;
}

/**
 * "No linked app", as a value the picker can hold: an empty string is not a value a
 * listbox option may carry, so the trigger drew its placeholder instead of the choice.
 */
const NOBODY = 'none';

type Props = PageProps<{
    api: {
        id: string;
        name: string;
        identifier: string;
        owner: string;
        environmentOwned: boolean;
        clientId: string;
    };
    scopes: ScopeRow[];
    apps: { value: string; label: string }[];
    indexHref: string;
    urls: { update: string; scopes: string; destroy: string };
}>;

export default function ApiDetail({ api, scopes, apps, indexHref, urls }: Props) {
    const { t: tc } = usePublicTranslator();
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [deleting, setDeleting] = useState(false);
    const [removing, setRemoving] = useState<ScopeRow | null>(null);

    const details = useForm({ name: api.name, clientId: api.clientId });
    const adding = useForm({ key: '', description: '', tenantRequestable: true });

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.apis_8d266d3e')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title" style={{ overflowWrap: 'anywhere' }}>
                        {api.name}
                    </h1>
                    <Badge>{api.owner}</Badge>
                </div>
                <div className="mt-1 flex items-start gap-2">
                    <code
                        className="mono text-sm"
                        style={{ color: 'var(--muted-foreground)', overflowWrap: 'anywhere' }}
                    >
                        {api.identifier}
                    </code>
                    <CopyButton value={api.identifier} />
                </div>
                <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                    {consoleT(
                        'console.the_audience_its_tokens_name_fixed_every_token_already_a3d06aee',
                    )}
                </p>
            </div>

            <Panel title={consoleT('console.details_45989de4')}>
                <form
                    className="space-y-4"
                    style={{ maxWidth: '36rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        details.patch(urls.update, { preserveScroll: true });
                    }}
                >
                    <Field label={consoleT('console.name_dcd1d522')} error={details.errors.name}>
                        <Input
                            name="name"
                            value={details.data.name}
                            onChange={(event) => details.setData('name', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={consoleT('console.roles_and_permissions_from_484a0cfa')}
                        optional
                        hint={consoleFinalText(
                            'console.the_app_whose_declared_roles_and_permissions_tokens_for_f9092feb',
                            {
                                part1: api.environmentOwned
                                    ? consoleText('console.this_environment_42756031')
                                    : api.owner,
                            },
                        )}
                        error={details.errors.clientId}
                    >
                        <Select
                            name="clientId"
                            value={details.data.clientId === '' ? NOBODY : details.data.clientId}
                            onValueChange={(clientId) =>
                                details.setData('clientId', clientId === NOBODY ? '' : clientId)
                            }
                            options={[
                                {
                                    value: NOBODY,
                                    label: consoleText('console.the_app_that_asks_4a500e90'),
                                },
                                ...apps.map((app) => ({ value: app.value, label: app.label })),
                            ]}
                        />
                    </Field>
                    <Button type="submit" size="sm" variant="primary" loading={details.processing}>
                        {consoleT('console.save_changes_dd0ae7a5')}
                    </Button>
                </form>
            </Panel>

            <Panel
                title={consoleT('console.scopes_0d5644ff')}
                description={
                    api.environmentOwned
                        ? consoleText(
                              'console.what_an_app_may_be_allowed_to_do_at_this_api_your_own_a_94f7133a',
                          )
                        : consoleFinalText(
                              'console.what_an_app_may_be_allowed_to_do_at_this_api_only_part1_c0ec6f18',
                              { part1: api.owner },
                          )
                }
            >
                {scopes.length === 0 ? (
                    <EmptyState
                        icon="key"
                        title={consoleT('console.no_scopes_yet_f181e074')}
                        description={consoleT(
                            'console.add_the_scopes_this_api_understands_until_it_owns_one_n_ba2ba5f9',
                        )}
                    />
                ) : (
                    <ul className="space-y-2">
                        {scopes.map((scope) => (
                            <ScopeItem
                                key={scope.id}
                                scope={scope}
                                environmentOwned={api.environmentOwned}
                                onRemove={() => setRemoving(scope)}
                            />
                        ))}
                    </ul>
                )}

                <form
                    className="mt-5 pt-5 space-y-3"
                    style={{ borderTop: '1px solid var(--border)' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        adding.post(urls.scopes, {
                            preserveScroll: true,
                            onSuccess: () => adding.reset(),
                        });
                    }}
                >
                    <p className="text-sm font-medium">
                        {consoleT('console.add_a_scope_9f8c6fb0')}
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            label={consoleT('console.key_99a52df3')}
                            hint={consoleT(
                                'console.what_apps_ask_for_such_as_invoices_read_unique_in_this_2109e70c',
                            )}
                            error={adding.errors.key}
                        >
                            <Input
                                name="key"
                                className="mono"
                                spellCheck={false}
                                autoCapitalize="off"
                                placeholder={tc('console.invoices_read_459482fd')}
                                value={adding.data.key}
                                onChange={(event) => adding.setData('key', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={consoleT('console.description_526e0087')}
                            optional
                            error={adding.errors.description}
                        >
                            <Input
                                name="description"
                                placeholder={consoleUiText('console.read_invoices_fc67518c')}
                                value={adding.data.description}
                                onChange={(event) =>
                                    adding.setData('description', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                    {api.environmentOwned && (
                        <Checkbox
                            checked={adding.data.tenantRequestable}
                            onCheckedChange={(checked) =>
                                adding.setData('tenantRequestable', checked)
                            }
                            label={consoleT('console.organizations_apps_may_request_this_106ac4fb')}
                            hint={consoleT(
                                'console.apps_registered_by_the_organizations_in_this_environmen_1fd60d9d',
                            )}
                        />
                    )}
                    <Button type="submit" size="sm" variant="primary" loading={adding.processing}>
                        {consoleT('console.add_scope_461afd9e')}
                    </Button>
                </form>
            </Panel>

            <Panel
                title={consoleT('console.delete_api_6e8eec0c')}
                description={consoleT(
                    'console.its_scopes_go_with_it_apps_that_held_them_keep_them_as_bf31a699',
                )}
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    {consoleT('console.delete_api_6e8eec0c')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.key ?? ''}
                verb={consoleShortText('console.remove_c3812fc4')}
                title={consoleFinalText('console.remove_the_scope_part1_37ad2687', {
                    part1: removing?.key ?? '',
                })}
                actionLabel={consoleUiText('console.remove_scope_c05e86d1')}
                consequence={consoleUiText(
                    'console.apps_that_hold_it_keep_it_as_a_typed_scope_which_no_lon_6498e819',
                )}
                onConfirm={() => {
                    const href = removing?.destroyHref;
                    setRemoving(null);

                    if (href !== undefined) {
                        router.delete(href, { preserveScroll: true });
                    }
                }}
            />

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={api.name}
                consequence={consoleUiText(
                    'console.tokens_stop_being_issued_for_this_audience_this_cannot_7912313c',
                )}
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * One scope, editable in place: its description and — on the environment's own API —
 * whether organizations' apps may request it. The key is fixed; apps already hold it.
 */
function ScopeItem({
    scope,
    environmentOwned,
    onRemove,
}: {
    scope: ScopeRow;
    environmentOwned: boolean;
    onRemove: () => void;
}) {
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        description: scope.description ?? '',
        tenantRequestable: scope.tenantRequestable,
    });

    const changed =
        form.data.description !== (scope.description ?? '') ||
        form.data.tenantRequestable !== scope.tenantRequestable;

    return (
        <li className="rounded-lg p-3" style={{ border: '1px solid var(--border)' }}>
            <form
                className="space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(scope.updateHref, { preserveScroll: true });
                }}
            >
                <div className="flex items-center gap-2 flex-wrap">
                    <code className="mono text-sm font-medium" style={{ overflowWrap: 'anywhere' }}>
                        {scope.key}
                    </code>
                    {environmentOwned &&
                        (scope.tenantRequestable ? (
                            <Pill tone="info">
                                {consoleT('console.organizations_apps_may_request_c04f2280')}
                            </Pill>
                        ) : (
                            <Pill>{consoleT('console.your_apps_only_8ca8dc57')}</Pill>
                        ))}
                </div>
                <Field
                    label={consoleT('console.description_526e0087')}
                    optional
                    error={form.errors.description}
                >
                    <Input
                        name={`description-${scope.id}`}
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                    />
                </Field>
                {environmentOwned && (
                    <Checkbox
                        checked={form.data.tenantRequestable}
                        onCheckedChange={(checked) => form.setData('tenantRequestable', checked)}
                        label={consoleT('console.organizations_apps_may_request_this_106ac4fb')}
                    />
                )}
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        variant="primary"
                        disabled={!changed}
                        loading={form.processing}
                    >
                        {consoleT('console.save_1509f561')}
                    </Button>
                    <Button type="button" size="sm" variant="ghost" onClick={onRemove}>
                        {consoleT('console.remove_c3812fc4')}
                    </Button>
                </div>
            </form>
        </li>
    );
}

ApiDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
