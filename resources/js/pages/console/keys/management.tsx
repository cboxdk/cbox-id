import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    EmptyState,
    ExpiryField,
    Field,
    Input,
    type KeyLifecycle,
    type KeyLifetimeOption,
    KeyStatusPill,
    KeyTimeline,
    type LinkTab,
    LinkTabs,
    PageHeader,
    Panel,
    RevealedKey,
    Select,
} from '@/ui';

/** A scope in words, with the API's own key beside it. */
interface Scope {
    value: string;
    label: string;
    /** True for anything that is not `:read` — the distinction that matters here. */
    writes: boolean;
    /** Cannot be undone — erasing a person. Offered, and said so. */
    critical: boolean;
}

interface KeyRow {
    id: string;
    name: string;
    prefix: string;
    scopes: Scope[];
    lifecycle: KeyLifecycle;
    /** Null once the key is no longer active: there is nothing left to stop. */
    revokeHref: string | null;
}

type Props = PageProps<{
    tabs: LinkTab[];
    /** False on the environment console, which mints for the environment it stands on. */
    pickEnvironment: boolean;
    environments: { id: string; name: string }[];
    selected: string;
    keys: KeyRow[];
    scopes: Scope[];
    lifetimes: KeyLifetimeOption[];
    defaultScopes: string[];
    storeHref: string;
    help: HelpContent;
}>;

export default function ManagementKeys({
    tabs,
    pickEnvironment,
    environments,
    selected,
    keys,
    scopes,
    lifetimes,
    defaultScopes,
    storeHref,
    help,
}: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // Shown once: only a hash is stored, and props are written into the browser's history
    // entry, so the plaintext rides the flash channel and nowhere else. The SAME key the
    // account-plane API keys page uses — one management-plane credential channel, so the
    // shell cannot end up with two spellings of the same one-time reveal.
    const freshKey = usePage().flash.freshKey;

    const [revoking, setRevoking] = useState<KeyRow | null>(null);

    const form = useForm({
        environment: selected,
        name: '',
        scopes: defaultScopes,
        expires: 'never',
        expiresOn: '',
    });

    // The environment travels in the URL, so the form's copy follows it rather than
    // drifting: minting against one environment while the list shows another is the one
    // mistake this page can make that nobody would notice.
    useEffect(() => {
        form.setData('environment', selected);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selected]);

    const toggleScope = (value: string, checked: boolean): void => {
        form.setData(
            'scopes',
            checked ? [...form.data.scopes, value] : form.data.scopes.filter((s) => s !== value),
        );
    };

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.keys_for_an_environment_s_management_api_run_one_enviro_6197b6d2',
                )}
                actions={
                    <Button asChild size="sm" className="shrink-0">
                        <a href="/api/v1/environment/openapi.yaml" target="_blank" rel="noreferrer">
                            {consoleT('console.api_reference_f05394ed')}
                        </a>
                    </Button>
                }
            />

            <div className="mt-4">
                <LinkTabs tabs={tabs} label={consoleT('console.key_types_0562e0e6')} />
            </div>

            {environments.length === 0 ? (
                <div className="card mt-6">
                    <EmptyState
                        icon="layers"
                        title={consoleT('console.no_environments_yet_8c63cac4')}
                        description={consoleT(
                            'console.create_an_environment_first_then_you_can_create_keys_sc_6b259c9b',
                        )}
                    />
                </div>
            ) : (
                <div className="mt-6 space-y-6">
                    {pickEnvironment && (
                        <Field
                            label={consoleT('console.environment_9e471951')}
                            className="max-w-sm"
                        >
                            <Select
                                value={selected}
                                onValueChange={(environment) =>
                                    router.get(
                                        window.location.pathname,
                                        { environment },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                            replace: true,
                                        },
                                    )
                                }
                                options={environments.map((environment) => ({
                                    value: environment.id,
                                    label: environment.name,
                                }))}
                            />
                        </Field>
                    )}

                    {freshKey !== undefined && <RevealedKey value={freshKey} />}

                    <Panel title={consoleT('console.keys_f0d66a79')} flush={keys.length > 0}>
                        {keys.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                {consoleT('console.no_keys_for_this_environment_yet_3faf26a6')}
                            </p>
                        ) : (
                            <div>
                                {keys.map((key, index) => (
                                    <div
                                        key={key.id}
                                        className="flex items-start gap-3 flex-wrap px-4 py-3"
                                        style={
                                            index === keys.length - 1
                                                ? undefined
                                                : { borderBottom: '1px solid var(--border)' }
                                        }
                                    >
                                        <div className="min-w-0 flex-1 space-y-1.5">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-medium truncate">
                                                    {key.name}
                                                </span>
                                                <KeyStatusPill lifecycle={key.lifecycle} />
                                            </div>
                                            <ul
                                                className="flex flex-wrap gap-1.5"
                                                aria-label={consoleT('console.scopes_0d5644ff')}
                                            >
                                                {key.scopes.map((scope) => (
                                                    <li key={scope.value}>
                                                        <Badge
                                                            tone={
                                                                scope.critical
                                                                    ? 'danger'
                                                                    : scope.writes
                                                                      ? 'warn'
                                                                      : 'neutral'
                                                            }
                                                        >
                                                            {scope.label}
                                                            <span
                                                                className="mono"
                                                                style={{ opacity: 0.7 }}
                                                            >
                                                                {scope.value}
                                                            </span>
                                                        </Badge>
                                                    </li>
                                                ))}
                                            </ul>
                                            <KeyTimeline
                                                lifecycle={key.lifecycle}
                                                prefix={key.prefix}
                                            />
                                        </div>

                                        {key.revokeHref !== null && (
                                            <Button
                                                size="sm"
                                                variant="danger"
                                                className="shrink-0"
                                                onClick={() => setRevoking(key)}
                                            >
                                                {consoleT('console.revoke_87e6d00b')}
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        title={consoleT('console.new_key_eda5496b')}
                        description={consoleT(
                            'console.the_key_can_do_only_what_its_scopes_allow_read_never_im_78fe4757',
                        )}
                    >
                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(storeHref, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('name'),
                                });
                            }}
                        >
                            <Field
                                label={consoleT('console.name_dcd1d522')}
                                error={form.errors.name}
                            >
                                <Input
                                    name="name"
                                    placeholder={consoleUiText(
                                        'console.provisioning_worker_5872a034',
                                    )}
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>

                            <div>
                                <fieldset>
                                    <legend className="label">
                                        {consoleT('console.scopes_0d5644ff')}
                                    </legend>
                                    <div className="mt-1 grid gap-2 sm:grid-cols-2">
                                        {scopes.map((scope) => (
                                            <Checkbox
                                                key={scope.value}
                                                checked={form.data.scopes.includes(scope.value)}
                                                onCheckedChange={(checked) =>
                                                    toggleScope(scope.value, checked)
                                                }
                                                label={
                                                    <span className="flex items-center gap-2 flex-wrap">
                                                        <span className="text-sm">
                                                            {scope.label}
                                                        </span>
                                                        <span
                                                            className="mono text-xs"
                                                            style={{
                                                                color: 'var(--muted-foreground)',
                                                            }}
                                                        >
                                                            {scope.value}
                                                        </span>
                                                        {/*
                                                            The one distinction that matters
                                                            when ticking boxes for a credential
                                                            that can provision people.
                                                        */}
                                                        {scope.critical ? (
                                                            <Badge tone="danger">
                                                                {consoleT(
                                                                    'console.critical_cannot_be_undone_2f6dd900',
                                                                )}
                                                            </Badge>
                                                        ) : (
                                                            scope.writes && (
                                                                <Badge tone="warn">
                                                                    {consoleT(
                                                                        'console.writes_531995e8',
                                                                    )}
                                                                </Badge>
                                                            )
                                                        )}
                                                    </span>
                                                }
                                            />
                                        ))}
                                    </div>
                                </fieldset>
                                {form.errors.scopes !== undefined && (
                                    <p className="field-error" role="alert">
                                        {form.errors.scopes}
                                    </p>
                                )}
                            </div>

                            <ExpiryField
                                lifetimes={lifetimes}
                                lifetime={form.data.expires}
                                onLifetimeChange={(expires) => form.setData('expires', expires)}
                                date={form.data.expiresOn}
                                onDateChange={(expiresOn) => form.setData('expiresOn', expiresOn)}
                                lifetimeError={form.errors.expires}
                                dateError={form.errors.expiresOn}
                            />

                            <Button type="submit" variant="primary" loading={form.processing}>
                                {consoleT('console.create_key_b6cde903')}
                            </Button>
                        </form>
                    </Panel>
                </div>
            )}

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                name={revoking?.name ?? ''}
                verb={consoleShortText('console.revoke_87e6d00b')}
                consequence={consoleUiText(
                    'console.whatever_is_using_this_key_stops_working_immediately_th_8e55e6f5',
                )}
                onConfirm={() => {
                    const key = revoking;
                    setRevoking(null);

                    if (key?.revokeHref) {
                        router.delete(key.revokeHref, {
                            data: { environment: selected },
                            preserveScroll: true,
                        });
                    }
                }}
            />
        </>
    );
}

ManagementKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
