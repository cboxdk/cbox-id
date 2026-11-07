import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, CopyButton, Field, Input, Panel, Select, Textarea } from '@/ui';

export interface CatalogueEntry {
    key: string;
    name: string;
    defaultScopes: string[];
    refreshable: boolean;
    revokes: boolean;
    apiBaseUrl: string;
    documentationUrl: string | null;
    setupSteps: string[];
    redirectUri: string;
    parameters: { key: string; label: string; default: string; help: string }[];
}

type Props = PageProps<{
    providers: CatalogueEntry[];
    selected: string | null;
    indexHref: string;
    storeHref: string;
}>;

/**
 * Set up a pipe: the provider's own steps for creating the OAuth app, the redirect URI to
 * paste there, then its client id and secret here. The secret is sealed on submit and
 * never shown again.
 */
export default function PipeCreate({ providers, selected, indexHref, storeHref }: Props) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        provider: selected ?? providers[0]?.key ?? '',
        client_id: '',
        client_secret: '',
        scopes: '',
        parameters: {} as Record<string, string>,
    });
    const entry = providers.find((p) => p.key === form.data.provider) ?? null;
    const errors = form.errors as Record<string, string | undefined>;

    if (providers.length === 0) {
        return (
            <div className="space-y-4">
                <h1 className="cbx-page-title">{consoleT('console.new_pipe_16b266b0')}</h1>
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich('console.every_provider_is_already_set_up_part1_part2_610158ae', {
                        part1: ' ',
                        part2: (
                            <Link href={indexHref} className="underline">
                                {consoleT('console.back_to_pipes_9e27609f')}
                            </Link>
                        ),
                    })}
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.pipes_685afe34')} />
                <h1 className="cbx-page-title mt-2">{consoleT('console.new_pipe_16b266b0')}</h1>
            </div>

            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, { onFinish: () => form.setData('client_secret', '') });
                }}
            >
                <Panel title={consoleT('console.provider_472590ae')}>
                    <Field label={consoleT('console.provider_472590ae')} error={errors.provider}>
                        <Select
                            name="provider"
                            value={form.data.provider}
                            onValueChange={(value) => form.setData('provider', value)}
                            options={providers.map((p) => ({ value: p.key, label: p.name }))}
                        />
                    </Field>
                </Panel>

                {entry !== null && (
                    <Panel
                        title={consoleFinalText('console.create_the_oauth_app_at_part1_a822f989', {
                            part1: entry.name,
                        })}
                        description={
                            entry.refreshable
                                ? consoleText(
                                      'console.tokens_are_refreshed_here_before_they_expire_0bb22b40',
                                  )
                                : consoleText(
                                      'console.tokens_from_this_provider_do_not_expire_45988f31',
                                  )
                        }
                    >
                        <ol className="list-decimal space-y-1.5 pl-5 text-sm">
                            {entry.setupSteps.map((step) => (
                                <li key={step}>{step}</li>
                            ))}
                        </ol>
                        <div className="mt-4">
                            <Field
                                label={consoleT('console.redirect_uri_98e548b4')}
                                hint={consoleT(
                                    'console.register_exactly_this_scheme_host_and_path_935c6de2',
                                )}
                            >
                                <div className="flex items-center gap-2">
                                    <Input readOnly className="mono" value={entry.redirectUri} />
                                    <CopyButton value={entry.redirectUri} />
                                </div>
                            </Field>
                        </div>
                        {entry.documentationUrl !== null && (
                            <p className="mt-3 text-sm">
                                <a
                                    href={entry.documentationUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="underline"
                                >
                                    {consoleRich('console._part1_documentation_cbbab95e', {
                                        part1: entry.name,
                                    })}
                                </a>
                            </p>
                        )}
                    </Panel>
                )}

                <Panel
                    title={consoleT('console.credentials_d48a617c')}
                    description={consoleT(
                        'console.the_client_secret_is_sealed_when_you_save_and_never_sho_9631bbe0',
                    )}
                >
                    <div className="space-y-4">
                        <Field
                            label={consoleT('console.client_id_8726db01')}
                            error={errors.client_id}
                        >
                            <Input
                                name="client_id"
                                className="mono"
                                autoComplete="off"
                                value={form.data.client_id}
                                onChange={(event) => form.setData('client_id', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={consoleT('console.client_secret_4aded5fa')}
                            error={errors.client_secret}
                        >
                            <Input
                                name="client_secret"
                                type="password"
                                className="mono"
                                autoComplete="off"
                                value={form.data.client_secret}
                                onChange={(event) =>
                                    form.setData('client_secret', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={consoleT('console.scopes_0d5644ff')}
                            hint={
                                entry === null || entry.defaultScopes.length === 0
                                    ? consoleText('console.separated_by_spaces_19d6b908')
                                    : consoleFinalText(
                                          'console.separated_by_spaces_left_empty_part1_d24c136a',
                                          { part1: entry.defaultScopes.join(' ') },
                                      )
                            }
                            error={errors.scopes}
                        >
                            <Textarea
                                name="scopes"
                                rows={2}
                                className="mono"
                                spellCheck={false}
                                value={form.data.scopes}
                                onChange={(event) => form.setData('scopes', event.target.value)}
                            />
                        </Field>
                        {entry?.parameters.map((parameter) => (
                            <Field
                                key={parameter.key}
                                label={parameter.label}
                                hint={parameter.help}
                                error={errors.parameters}
                            >
                                <Input
                                    name={`parameters[${parameter.key}]`}
                                    className="mono"
                                    placeholder={parameter.default}
                                    value={form.data.parameters[parameter.key] ?? ''}
                                    onChange={(event) =>
                                        form.setData('parameters', {
                                            ...form.data.parameters,
                                            [parameter.key]: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                        ))}
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.save_pipe_130c76d3')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </div>
    );
}

PipeCreate.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
