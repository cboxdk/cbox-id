import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, Checkbox, Field, Input, Panel } from '@/ui';
import { AppFrame, type AppHeaderData } from './frame';

interface ScopeOption {
    key: string;
    label: string;
    description: string;
}

/** `App\Http\Props\Console\ApiScopeGroupProps` */
interface ApiGroup {
    id: string;
    name: string;
    identifier: string;
    owner: string;
    scopes: { key: string; description: string | null }[];
}

/** `App\Http\Props\Console\AppAudienceProps` */
interface Audience {
    shape: 'issuer' | 'api' | 'several';
    issuer: string;
    identifiers: string[];
    withIssuer: boolean;
    unowned: string[];
    refused: string[];
}

type Props = PageProps<{
    appHeader: AppHeaderData;
    stored: { catalogue: string[]; api: string[]; custom: string; all: string[] };
    scopeGroups: Record<string, ScopeOption[]>;
    apiGroups: ApiGroup[];
    audience: Audience;
    mayManage: boolean;
    updateHref: string;
    /** The environment console's APIs page; null on an organization's console. */
    apisHref: string | null;
}>;

export default function ClientScopes({
    appHeader,
    stored,
    scopeGroups,
    apiGroups,
    audience,
    mayManage,
    updateHref,
    apisHref,
}: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({
        scopes: [...stored.catalogue, ...stored.api],
        customScopes: stored.custom,
    });

    const toggle = (key: string, checked: boolean): void =>
        form.setData(
            'scopes',
            checked ? [...form.data.scopes, key] : form.data.scopes.filter((s) => s !== key),
        );

    return (
        <AppFrame app={appHeader}>
            <AudiencePanel audience={audience} />

            {!mayManage ? (
                <Panel title={tc('console.scopes_0d5644ff')}>
                    {stored.all.length === 0 ? (
                        <span className="text-sm" style={{ color: 'var(--faint)' }}>
                            —
                        </span>
                    ) : (
                        <div className="flex flex-wrap gap-1.5">
                            {stored.all.map((scope) => (
                                <Badge key={scope} className="mono">
                                    {scope}
                                </Badge>
                            ))}
                        </div>
                    )}
                </Panel>
            ) : (
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(updateHref, { preserveScroll: true });
                    }}
                >
                    <Panel
                        title={tc('console.what_this_app_may_ask_for_1955fcf4')}
                        description={tc(
                            'console.the_ceiling_on_this_app_s_access_narrowing_i_5f2865bc',
                        )}
                    >
                        <div className="space-y-4">
                            {Object.entries(scopeGroups).map(([group, scopes]) => (
                                <div key={group}>
                                    <p className="cbx-nav-group mb-2">{group}</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {scopes.map((scope) => (
                                            <ScopeBox key={scope.key}>
                                                <Checkbox
                                                    checked={form.data.scopes.includes(scope.key)}
                                                    onCheckedChange={(checked) =>
                                                        toggle(scope.key, checked)
                                                    }
                                                    label={
                                                        <span className="flex items-center gap-2 flex-wrap">
                                                            {scope.label}
                                                            <ScopeKey>{scope.key}</ScopeKey>
                                                        </span>
                                                    }
                                                    hint={scope.description}
                                                />
                                            </ScopeBox>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <Panel
                        title={tc('console.your_apis_62ad094f')}
                        description={tc(
                            'console.scopes_of_the_apis_registered_in_this_enviro_4ea61056',
                        )}
                    >
                        {apiGroups.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                {tc(
                                    'console.no_registered_api_offers_scopes_to_this_app__bef8405b',
                                )}{' '}
                                {apisHref !== null ? (
                                    <Link
                                        href={apisHref}
                                        className="underline"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        {tc('console.register_an_api_f94653f0')}
                                    </Link>
                                ) : (
                                    tc(
                                        'console.the_environment_s_administrators_register_ap_eb40bdf1',
                                    )
                                )}
                            </p>
                        ) : (
                            <div className="space-y-5">
                                {apiGroups.map((api) => (
                                    <fieldset key={api.id}>
                                        <legend className="w-full">
                                            <span className="font-medium">{api.name}</span>{' '}
                                            <span
                                                className="text-xs"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                · {api.owner}
                                            </span>
                                            <span
                                                className="block text-xs mono mt-0.5"
                                                style={{
                                                    color: 'var(--faint)',
                                                    overflowWrap: 'anywhere',
                                                }}
                                            >
                                                {api.identifier}
                                            </span>
                                        </legend>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            {api.scopes.map((scope) => (
                                                <ScopeBox key={scope.key}>
                                                    <Checkbox
                                                        checked={form.data.scopes.includes(
                                                            scope.key,
                                                        )}
                                                        onCheckedChange={(checked) =>
                                                            toggle(scope.key, checked)
                                                        }
                                                        label={<ScopeKey>{scope.key}</ScopeKey>}
                                                        hint={scope.description ?? undefined}
                                                    />
                                                </ScopeBox>
                                            ))}
                                        </div>
                                    </fieldset>
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel title={tc('console.advanced_9f088dbe')}>
                        <Field
                            label={tc('console.typed_scopes_05291f81')}
                            hint={tc(
                                'console.comma_or_space_separated_a_scope_you_type_is_c5513416',
                            )}
                            error={form.errors.customScopes}
                        >
                            <Input
                                name="customScopes"
                                className="mono"
                                spellCheck={false}
                                placeholder={tc('console.api_read_reports_export_db00448d')}
                                value={form.data.customScopes}
                                onChange={(event) =>
                                    form.setData('customScopes', event.target.value)
                                }
                            />
                        </Field>
                    </Panel>

                    {form.errors.scopes !== undefined && (
                        <div
                            role="alert"
                            className="rounded-xl border p-4 text-sm"
                            style={{
                                borderColor:
                                    'color-mix(in oklch, var(--destructive) 40%, transparent)',
                                background: 'var(--destructive-soft)',
                            }}
                        >
                            <p
                                className="font-medium"
                                style={{ color: 'var(--destructive-strong)' }}
                            >
                                {tc('console.scopes_not_saved_261b06f6')}
                            </p>
                            <p className="mt-1">{form.errors.scopes}</p>
                        </div>
                    )}

                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.save_scopes_1d8c2d04')}
                    </Button>
                </form>
            )}
        </AppFrame>
    );
}

/**
 * WHERE THIS APP'S TOKENS WILL GO, said before the first one is minted.
 *
 * A registered API's scope changes the `aud` of every token that carries it, and a scope
 * of a second API makes a request without `resource` fail. Both are true the moment the
 * boxes are saved, and both used to be found out at the resource server.
 */
function AudiencePanel({ audience }: { audience: Audience }) {
    const { t: tc } = useTranslator();

    return (
        <Panel title={tc('console.token_audience_0783a89f')}>
            <div className="space-y-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {audience.shape === 'issuer' && (
                    <p>
                        {tc('console.access_tokens_for_this_app_name_this_environ_d29fc236')}{' '}
                        <Mono>{audience.issuer}</Mono>
                        {tc('console.as_their_audience_that_changes_when_it_holds_24f21d69')}
                    </p>
                )}
                {audience.shape === 'api' && (
                    <p>
                        {tc('console.access_tokens_for_this_app_name_e4603199')}
                        <Mono>{audience.identifiers[0]}</Mono>
                        {tc('console.as_their_audience_e7c5f9ab')}
                        {audience.withIssuer ? (
                            <>
                                {tc('console.together_with_the_issuer_3e98cbc9')}
                                <Mono>{audience.issuer}</Mono>
                                {tc('console.because_the_app_also_signs_people_in_01f9fbf5')}
                            </>
                        ) : null}
                        {tc('console.that_api_should_accept_a_token_only_when_it__3d65466b')}
                    </p>
                )}
                {audience.shape === 'several' && (
                    <p>
                        {tc('console.this_app_holds_scopes_of_more_than_one_api_422e41ea')}{' '}
                        {audience.identifiers.map((identifier, index) => (
                            <span key={identifier}>
                                {index > 0 && ', '}
                                <Mono>{identifier}</Mono>
                            </span>
                        ))}
                        {tc('console.a_token_is_for_one_api_at_a_time_so_the_app__6dd50c9a')}
                        <Mono>{tc('console.resource_5de95319')}</Mono>
                        {tc('console.parameter_a_request_for_scopes_of_two_apis_a_7b59f6ec')}
                    </p>
                )}
                {audience.unowned.length > 0 && (
                    <p>
                        {tc('console.typed_scopes_99c0ce9b')}
                        <Mono>{audience.unowned.join(' ')}</Mono>
                        {tc('console.are_unowned_they_travel_as_they_are_and_neve_ebc67146')}
                    </p>
                )}
                {audience.refused.length > 0 && (
                    <p role="note" style={{ color: 'var(--warning-strong)' }}>
                        <Mono>{audience.refused.join(' ')}</Mono>
                        {tc('console.belong_to_an_api_this_app_may_not_use_they_a_d169343d')}
                    </p>
                )}
            </div>
        </Panel>
    );
}

function ScopeBox({ children }: { children: React.ReactNode }) {
    return (
        <div className="rounded-lg p-2.5" style={{ border: '1px solid var(--border)' }}>
            {children}
        </div>
    );
}

function ScopeKey({ children }: { children: React.ReactNode }) {
    return (
        <span
            className="text-xs rounded-full px-2 py-0.5 mono"
            style={{
                background: 'var(--surface-2)',
                color: 'var(--muted-foreground)',
                overflowWrap: 'anywhere',
            }}
        >
            {children}
        </span>
    );
}

function Mono({ children }: { children: React.ReactNode }) {
    return (
        <code className="mono" style={{ color: 'var(--foreground)', overflowWrap: 'anywhere' }}>
            {children}
        </code>
    );
}

ClientScopes.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
