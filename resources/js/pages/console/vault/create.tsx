import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, PageHeader, Panel, Textarea } from '@/ui';

type Props = PageProps<{
    /** Who this secret will belong to, named — "Acme", or "this environment". */
    scopeLabel: string;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateVaultSecret({ scopeLabel, indexHref, storeHref }: Props) {
    const form = useForm({ name: '', provider: '', secret: '' });

    return (
        <>
            <Link
                href={indexHref}
                className="text-sm inline-flex items-center gap-1"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon
                    name="chevron"
                    className="w-3.5 h-3.5"
                    style={{ transform: 'rotate(90deg)' }}
                />
                Token vault
            </Link>

            <div className="mt-2">
                <PageHeader description="A downstream API key your apps and agents present to a provider. It is sealed on store and brokered only to explicitly granted clients." />
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
                    title="What it is"
                    description={`Stored for ${scopeLabel}. Only apps you grant can lease it.`}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="Stripe live key"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label="Provider"
                            hint="Who issued it — the name you would search for in their dashboard."
                            error={form.errors.provider}
                        >
                            <Input
                                name="provider"
                                className="mono"
                                placeholder="stripe"
                                value={form.data.provider}
                                onChange={(event) => form.setData('provider', event.target.value)}
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel
                    title="The value"
                    description="Sealed the moment you save it and never shown again. Keep your own copy if you need one."
                >
                    <Field label="Secret value" error={form.errors.secret}>
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
                            placeholder="sk-live-…"
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
                            This is the only time the value is handled in the clear.
                        </p>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            It is sealed on save and never displayed again — not here, not on the
                            secret's own page, not after a rotation.
                        </p>
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Seal and store
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateVaultSecret.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
