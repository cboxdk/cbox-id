import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, Field, Input, PageHeader, Panel, Textarea } from '@/ui';

type Props = PageProps<{
    /** Who this secret will belong to, named — "Acme", or "this environment". */
    scopeLabel: string;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateVaultSecret({ scopeLabel, indexHref, storeHref }: Props) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ name: '', provider: '', secret: '' });

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.token_vault_1d74ef9e')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.a_downstream_api_key_your_apps_and_agents_present_to_a_069a6a5e',
                    )}
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, {
                        // The value is never echoed back — not into the response, not into
                        // the old-input bag — so the field is cleared here whatever happens.
                        onFinish: () => form.setData('secret', ''),
                    });
                }}
            >
                <Panel
                    title={consoleT('console.what_it_is_1f36becc')}
                    description={consoleFinalText(
                        'console.stored_for_part1_only_apps_you_grant_can_lease_it_6fcbee13',
                        { part1: scopeLabel },
                    )}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={consoleT('console.name_dcd1d522')} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder={consoleUiText('console.stripe_live_key_884e00ff')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={consoleT('console.provider_472590ae')}
                            hint={consoleT(
                                'console.who_issued_it_the_name_you_would_search_for_in_their_da_22a5c245',
                            )}
                            error={form.errors.provider}
                        >
                            <Input
                                name="provider"
                                className="mono"
                                placeholder={consoleUiText('console.stripe_2bed2032')}
                                value={form.data.provider}
                                onChange={(event) => form.setData('provider', event.target.value)}
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel
                    title={consoleT('console.the_value_b4215363')}
                    description={consoleT(
                        'console.sealed_the_moment_you_save_it_and_never_shown_again_kee_db995569',
                    )}
                >
                    <Field
                        label={consoleT('console.secret_value_6ef47d98')}
                        error={form.errors.secret}
                    >
                        {/*
                            A textarea rather than an input: a downstream credential is
                            whatever the provider issues, and a PEM private key does not fit
                            on one line. `spellCheck` off, and no autocomplete — a browser
                            offering to remember this is offering to store it somewhere we
                            went to some trouble to avoid.
                        */}
                        <Textarea
                            name="secret"
                            rows={4}
                            className="mono"
                            spellCheck={false}
                            autoComplete="off"
                            placeholder={consoleUiText('console.sk_live_2897b00f')}
                            value={form.data.secret}
                            onChange={(event) => form.setData('secret', event.target.value)}
                        />
                    </Field>

                    <div
                        className="mt-4 rounded-xl border p-4"
                        style={{
                            borderColor: 'color-mix(in oklch, var(--warning) 35%, transparent)',
                            background: 'var(--warning-soft)',
                        }}
                    >
                        <p
                            className="text-sm font-medium"
                            style={{ color: 'var(--warning-strong)' }}
                        >
                            {consoleT(
                                'console.this_is_the_only_time_the_value_is_handled_in_the_clear_ccb3680f',
                            )}
                        </p>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleT(
                                'console.it_is_sealed_on_save_and_never_displayed_again_not_here_e9ace615',
                            )}
                        </p>
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.seal_and_store_2c06d5f0')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateVaultSecret.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
