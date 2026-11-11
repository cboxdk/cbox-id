import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleNestedText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                <Panel title={consoleT('console.scopes_0d5644ff')}>
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
                        title={consoleT('console.what_this_app_may_ask_for_1955fcf4')}
                        description={consoleT(
                            'console.the_ceiling_on_this_app_s_access_narrowing_it_takes_eff_5f2865bc',
                        )}
                    >
                        <div className="space-y-4">
                            {Object.entries(scopeGroups).map(([group, scopes]) => (
                                <div key={group}>
                                    <p className="idc-nav-group mb-2">{group}</p>
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
                        title={consoleT('console.your_apis_62ad094f')}
                        description={consoleT(
                            'console.scopes_of_the_apis_registered_in_this_environment_that_4ea61056',
                        )}
                    >
                        {apiGroups.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                {consoleRich(
                                    'console.no_registered_api_offers_scopes_to_this_app_yet_part1_p_bb8e8388',
                                    {
                                        part1: ' ',
                                        part2:
                                            apisHref !== null ? (
                                                <Link
                                                    href={apisHref}
                                                    className="underline"
                                                    style={{ color: 'var(--accent-strong)' }}
                                                >
                                                    {consoleT('console.register_an_api_f94653f0')}
                                                </Link>
                                            ) : (
                                                consoleNestedText(
                                                    'console.the_environment_s_administrators_register_apis_and_deci_eb40bdf1',
                                                )
                                            ),
                                    },
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

                    <Panel title={consoleT('console.advanced_9f088dbe')}>
                        <Field
                            label={consoleT('console.typed_scopes_05291f81')}
                            hint={consoleT(
                                'console.comma_or_space_separated_a_scope_you_type_is_unowned_it_c5513416',
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
                                {consoleT('console.scopes_not_saved_261b06f6')}
                            </p>
                            <p className="mt-1">{form.errors.scopes}</p>
                        </div>
                    )}

                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.save_scopes_1d8c2d04')}
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
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <Panel title={consoleT('console.token_audience_0783a89f')}>
            <div className="space-y-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {audience.shape === 'issuer' && (
                    <p>
                        {consoleRich(
                            'console.access_tokens_for_this_app_name_this_environment_s_issu_13b4d3ef',
                            { part1: ' ', part2: <Mono>{audience.issuer}</Mono> },
                        )}
                    </p>
                )}
                {audience.shape === 'api' && (
                    <p>
                        {consoleRich(
                            'console.access_tokens_for_this_app_name_part1_as_their_audience_0da966a0',
                            {
                                part1: <Mono>{audience.identifiers[0]}</Mono>,
                                part2: audience.withIssuer ? (
                                    <>
                                        {consoleRich(
                                            'console._together_with_the_issuer_part1_because_the_app_also_si_e67abff1',
                                            { part1: <Mono>{audience.issuer}</Mono> },
                                        )}
                                    </>
                                ) : null,
                            },
                        )}
                    </p>
                )}
                {audience.shape === 'several' && (
                    <p>
                        {consoleRich(
                            'console.this_app_holds_scopes_of_more_than_one_api_part1_part2_163d032f',
                            {
                                part1: ' ',
                                part2: audience.identifiers.map((identifier, index) => (
                                    <span key={identifier}>
                                        {index > 0 && ', '}
                                        <Mono>{identifier}</Mono>
                                    </span>
                                )),
                                part3: <Mono>{consoleT('console.resource_5de95319')}</Mono>,
                            },
                        )}
                    </p>
                )}
                {audience.unowned.length > 0 && (
                    <p>
                        {consoleRich(
                            'console.typed_scopes_part1_are_unowned_they_travel_as_they_are_e061e25f',
                            { part1: <Mono>{audience.unowned.join(' ')}</Mono> },
                        )}
                    </p>
                )}
                {audience.refused.length > 0 && (
                    <p role="note" style={{ color: 'var(--warning-strong)' }}>
                        {consoleRich(
                            'console._part1_belong_to_an_api_this_app_may_not_use_they_are_l_99264721',
                            { part1: <Mono>{audience.refused.join(' ')}</Mono> },
                        )}
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
