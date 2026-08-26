import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    ConfirmDelete,
    CopyButton,
    Field,
    Input,
    PageHeader,
    Panel,
    ProviderMark,
    Textarea,
} from '@/ui';

interface EnabledProvider {
    id: string;
    name: string;
    provider: string | null;
    protocol: string;
    /** The REAL redirect URI — it only exists once the connection does. */
    callbackUri: string;
    removeHref: string;
}

interface CatalogueEntry {
    key: string;
    name: string;
    protocol: string;
    href: string;
}

interface Parameter {
    key: string;
    label: string;
    help: string;
    example: string;
    /** A PEM key is four lines, not a word. */
    multiline: boolean;
}

interface Template {
    key: string;
    name: string;
    protocol: string;
    documentationUrl: string | null;
    redirectUri: string;
    setupSteps: string[];
    parameters: Parameter[];
    /** Apple issues no client secret — it mints a signed assertion from a key instead. */
    mintsItsOwnSecret: boolean;
}

type Props = PageProps<{
    enabled: EnabledProvider[];
    available: CatalogueEntry[];
    template: Template | null;
    indexHref: string;
    storeHref: string;
    help: HelpContent;
}>;

export default function SocialProviders({
    enabled,
    available,
    template,
    indexHref,
    storeHref,
    help,
}: Props) {
    const [removing, setRemoving] = useState<EnabledProvider | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description="Let people sign in with an account they already have. You supply the credentials from your own account with each provider; everything else is filled in for you."
            />

            <div className="mt-6 space-y-5">
                <Panel
                    title="On your sign-in page"
                    description="These appear as buttons, in this order."
                >
                    {enabled.length === 0 ? (
                        <div className="py-6 text-center">
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                No social providers yet — people sign in with a password, a magic
                                link or a passkey.
                            </p>
                            <p
                                className="mt-1 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                Add one below to offer it as a button.
                            </p>
                        </div>
                    ) : (
                        <ul>
                            {enabled.map((connection, index) => (
                                <li
                                    key={connection.id}
                                    className="flex items-center gap-3 py-3.5"
                                    style={
                                        index < enabled.length - 1
                                            ? { borderBottom: '1px solid var(--border)' }
                                            : undefined
                                    }
                                >
                                    <ProviderMark provider={connection.provider ?? ''} size={20} />

                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-sm truncate">
                                            {connection.name}
                                        </p>
                                        <p
                                            className="text-xs truncate"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {connection.protocol}
                                        </p>
                                        {/*
                                            The one value the provider must be given, available
                                            nowhere else after saving: the setup panel can only
                                            show a {'{connection}'} placeholder, and a mismatch
                                            fails with an error naming the client id rather than
                                            the URI.
                                        */}
                                        <p className="mt-1.5 flex items-center gap-2 flex-wrap">
                                            <span
                                                className="mono text-xs break-all"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                <span style={{ color: 'var(--foreground)' }}>
                                                    Redirect URI:
                                                </span>{' '}
                                                {connection.callbackUri}
                                            </span>
                                            <CopyButton
                                                size="sm"
                                                value={connection.callbackUri}
                                                label={`Copy the redirect URI for ${connection.name}`}
                                            />
                                        </p>
                                    </div>

                                    <Button size="sm" onClick={() => setRemoving(connection)}>
                                        Remove
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                {template !== null && (
                    <SetupPanel template={template} storeHref={storeHref} cancelHref={indexHref} />
                )}

                {available.length > 0 && (
                    <Panel
                        title="Add a provider"
                        description="You will need credentials from your own account with the provider — usually a client ID and secret, though Apple issues a signing key instead."
                    >
                        <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                            {available.map((option) => {
                                const chosen = template?.key === option.key;

                                return (
                                    <Link
                                        key={option.key}
                                        href={option.href}
                                        preserveScroll
                                        aria-current={chosen ? 'true' : undefined}
                                        className="flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-start transition"
                                        style={{
                                            border: `1px solid ${chosen ? 'var(--accent)' : 'var(--border)'}`,
                                            background: chosen
                                                ? 'var(--accent-soft)'
                                                : 'transparent',
                                        }}
                                    >
                                        <ProviderMark provider={option.key} size={20} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium text-sm truncate">
                                                {option.name}
                                            </span>
                                            <span
                                                className="block text-xs truncate"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {option.protocol}
                                            </span>
                                        </span>
                                    </Link>
                                );
                            })}
                        </div>
                    </Panel>
                )}
            </div>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.name ?? ''}
                verb="Remove"
                consequence="It stops appearing as a button on your sign-in page. Anyone who signed in with it keeps their account and can still use their password."
                onConfirm={() => {
                    const connection = removing;
                    setRemoving(null);

                    if (connection !== null) {
                        router.delete(connection.removeHref, { preserveScroll: true });
                    }
                }}
            />
        </>
    );
}

/**
 * The setup panel, in the order the work actually happens.
 *
 * The redirect URI comes FIRST, before the credential fields, because a mismatch there is
 * the most common way any of these fails — and the error the provider returns for it names
 * its own client id rather than the URI, so it reads as a credential problem and gets
 * debugged as one. The provider's own steps sit beside the fields rather than behind a
 * link: whoever is filling this in already has two tabs open, and every extra one costs
 * them their place.
 */
function SetupPanel({
    template,
    storeHref,
    cancelHref,
}: {
    template: Template;
    storeHref: string;
    cancelHref: string;
}) {
    const form = useForm({
        provider: template.key,
        clientId: '',
        clientSecret: '',
        parameters: Object.fromEntries(
            template.parameters.map((parameter) => [parameter.key, '']),
        ) as Record<string, string>,
    });

    return (
        <div className="card">
            <div
                className="p-4 flex items-center gap-2.5"
                style={{ borderBottom: '1px solid var(--border)' }}
            >
                <ProviderMark provider={template.key} size={22} />
                <div className="min-w-0">
                    <h2 className="font-semibold text-sm">Set up {template.name}</h2>
                    <p className="text-sm mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                        {template.protocol}
                        {template.documentationUrl !== null && (
                            <>
                                {' · '}
                                <a
                                    href={template.documentationUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline underline-offset-2"
                                    style={{ color: 'var(--accent-strong)' }}
                                >
                                    {template.name}’s own guide ↗
                                </a>
                            </>
                        )}
                    </p>
                </div>
            </div>

            <form
                className="p-4 space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, { preserveScroll: true });
                }}
            >
                <div>
                    <p className="text-sm font-semibold">1. Register this redirect URI</p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {template.name} refuses the sign-in if this does not match exactly. Replace{' '}
                        <span className="mono">{'{connection}'}</span> with the id shown after you
                        enable it.
                    </p>
                    <div className="mt-2 flex items-center gap-2">
                        <p
                            className="mono text-xs break-all rounded-lg px-3 py-2 min-w-0 flex-1"
                            style={{
                                background: 'var(--surface-2)',
                                border: '1px solid var(--border)',
                            }}
                        >
                            {template.redirectUri}
                        </p>
                        <CopyButton value={template.redirectUri} label="Copy the redirect URI" />
                    </div>
                </div>

                {template.setupSteps.length > 0 && (
                    <div>
                        <p className="text-sm font-semibold">2. In {template.name}</p>
                        <ol
                            className="mt-2 space-y-1.5 text-sm list-decimal ps-5"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {template.setupSteps.map((step) => (
                                <li key={step}>{step}</li>
                            ))}
                        </ol>
                    </div>
                )}

                <div className="space-y-4">
                    <p className="text-sm font-semibold">3. Paste what {template.name} gave you</p>

                    {template.parameters.map((parameter) => (
                        <Field
                            key={parameter.key}
                            label={parameter.label}
                            hint={parameter.help === '' ? undefined : parameter.help}
                            error={form.errors[`parameters.${parameter.key}` as never]}
                        >
                            {parameter.multiline ? (
                                <Textarea
                                    rows={4}
                                    className="mono text-xs"
                                    placeholder={parameter.example}
                                    value={form.data.parameters[parameter.key] ?? ''}
                                    onChange={(event) =>
                                        form.setData('parameters', {
                                            ...form.data.parameters,
                                            [parameter.key]: event.target.value,
                                        })
                                    }
                                />
                            ) : (
                                <Input
                                    placeholder={parameter.example}
                                    value={form.data.parameters[parameter.key] ?? ''}
                                    onChange={(event) =>
                                        form.setData('parameters', {
                                            ...form.data.parameters,
                                            [parameter.key]: event.target.value,
                                        })
                                    }
                                />
                            )}
                        </Field>
                    ))}

                    <Field
                        label={template.mintsItsOwnSecret ? 'Services ID' : 'Client ID'}
                        hint={
                            template.mintsItsOwnSecret
                                ? 'The Services ID you enabled Sign in with Apple on — not the App ID.'
                                : undefined
                        }
                        error={form.errors.clientId}
                    >
                        <Input
                            name="clientId"
                            value={form.data.clientId}
                            onChange={(event) => form.setData('clientId', event.target.value)}
                        />
                    </Field>

                    {/*
                        ASKED FOR ONLY WHERE ONE EXISTS. Apple issues no client secret: the
                        credential is an ES256 assertion minted per request from the key
                        above. The field used to be shown anyway, relabelled, which left the
                        form asking twice for the Services ID — once as the client id and once
                        as a password — and made enabling Apple impossible to get right.
                    */}
                    {template.mintsItsOwnSecret ? (
                        <p
                            className="text-sm rounded-lg px-3 py-2.5"
                            style={{
                                background: 'var(--surface-2)',
                                border: '1px solid var(--border)',
                                color: 'var(--muted-foreground)',
                            }}
                        >
                            There is no client secret to paste. {template.name} expects a signed
                            assertion instead, which we mint from the key above for each sign-in and
                            renew long before it expires.
                        </p>
                    ) : (
                        <Field label="Client secret" error={form.errors.clientSecret}>
                            <Input
                                name="clientSecret"
                                type="password"
                                autoComplete="off"
                                value={form.data.clientSecret}
                                onChange={(event) =>
                                    form.setData('clientSecret', event.target.value)
                                }
                            />
                        </Field>
                    )}
                </div>

                <div className="flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
                    <Button asChild>
                        <Link href={cancelHref} preserveScroll>
                            Cancel
                        </Link>
                    </Button>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {form.processing
                            ? `Checking with ${template.name}…`
                            : `Enable ${template.name}`}
                    </Button>
                </div>
            </form>
        </div>
    );
}

SocialProviders.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
