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
                <h1 className="cbx-page-title">New pipe</h1>
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    Every provider is already set up.{' '}
                    <Link href={indexHref} className="underline">
                        Back to Pipes
                    </Link>
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label="Pipes" />
                <h1 className="cbx-page-title mt-2">New pipe</h1>
            </div>

            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, { onFinish: () => form.setData('client_secret', '') });
                }}
            >
                <Panel title="Provider">
                    <Field label="Provider" error={errors.provider}>
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
                        title={`Create the OAuth app at ${entry.name}`}
                        description={
                            entry.refreshable
                                ? 'Tokens are refreshed here before they expire.'
                                : 'Tokens from this provider do not expire.'
                        }
                    >
                        <ol className="list-decimal space-y-1.5 pl-5 text-sm">
                            {entry.setupSteps.map((step) => (
                                <li key={step}>{step}</li>
                            ))}
                        </ol>
                        <div className="mt-4">
                            <Field
                                label="Redirect URI"
                                hint="Register exactly this — scheme, host and path."
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
                                    {entry.name} documentation
                                </a>
                            </p>
                        )}
                    </Panel>
                )}

                <Panel
                    title="Credentials"
                    description="The client secret is sealed when you save and never shown again."
                >
                    <div className="space-y-4">
                        <Field label="Client ID" error={errors.client_id}>
                            <Input
                                name="client_id"
                                className="mono"
                                autoComplete="off"
                                value={form.data.client_id}
                                onChange={(event) => form.setData('client_id', event.target.value)}
                            />
                        </Field>
                        <Field label="Client secret" error={errors.client_secret}>
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
                            label="Scopes"
                            hint={
                                entry === null || entry.defaultScopes.length === 0
                                    ? 'Separated by spaces.'
                                    : `Separated by spaces. Left empty: ${entry.defaultScopes.join(' ')}`
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
                        Save pipe
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </div>
    );
}

PipeCreate.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
