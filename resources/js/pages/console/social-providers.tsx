import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, OrganizationFilter, OrganizationPicker, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    Field,
    FilterChips,
    Input,
    OrganizationFilterChip,
    OrganizationPickerField,
    PageHeader,
    Panel,
    Pill,
    ProviderMark,
    Textarea,
} from '@/ui';

/** A provider set up for the environment or for one organization, with its controls. */
interface ProviderRow {
    id: string;
    name: string;
    provider: string | null;
    protocol: string;
    enabled: boolean;
    /** The REAL redirect URI — it contains the provider's id. */
    callbackUri: string;
    /** Extra scopes, beyond the ones sign-in needs. */
    scopes: string[];
    /** Whose page offers it — named on the environment view's list of organizations' own. */
    organization: string | null;
    /** An organization's own that stands in for the environment's on its page. */
    replacesEnvironment: boolean;
    editHref: string;
    enableHref: string;
    disableHref: string;
    removeHref: string;
}

/** One button on one organization's sign-in page, and where it comes from. */
interface PageRow {
    id: string;
    name: string;
    provider: string | null;
    protocol: string;
    enabled: boolean;
    callbackUri: string;
    source: 'environment' | 'organization';
    /**
     * `offered` — on the page; `hidden` — the environment's, turned off for this page;
     * `replaced` — the environment's, with the organization's own in its place; `off` —
     * turned off by its owner.
     */
    state: 'offered' | 'hidden' | 'replaced' | 'off';
    /** Turn the environment's off for this page, or back on. Null where it would change nothing. */
    inheritHref: string | null;
    // An organization's own row carries the full controls as well.
    scopes?: string[];
    replacesEnvironment?: boolean;
    editHref?: string;
    enableHref?: string;
    disableHref?: string;
    removeHref?: string;
}

/** An organization that turned one of the environment's providers off for its page. */
interface OptOutRow {
    organizationId: string;
    organization: string;
    provider: string;
    name: string;
    inheritHref: string;
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
    /** A PEM key is four lines, not a word — and is never shown back. */
    multiline: boolean;
}

interface Template {
    key: string;
    name: string;
    protocol: string;
    documentationUrl: string | null;
    setupSteps: string[];
    parameters: Parameter[];
    /** Apple issues no client secret — it mints a signed assertion from a key instead. */
    mintsItsOwnSecret: boolean;
}

interface SetupTemplate extends Template {
    /** The id reserved for the provider, so its redirect URI is real before it is saved. */
    reservedId: string;
    redirectUri: string;
}

interface Editing extends Template {
    id: string;
    /** Null for the environment's own. */
    organization: string | null;
    redirectUri: string;
    clientId: string;
    /** What is on file for each provider value; empty for key material, which is never shown. */
    values: Record<string, string>;
    scopes: string;
    updateHref: string;
    fromOrganization: boolean;
}

type Props = PageProps<{
    /** The environment's providers and every organization's, or what one organization's page shows. */
    view: 'environment' | 'organization';
    organizationName: string | null;
    environmentProviders: ProviderRow[];
    organizationProviders: ProviderRow[];
    optOuts: OptOutRow[];
    page: PageRow[];
    available: CatalogueEntry[];
    template: SetupTemplate | null;
    editing: Editing | null;
    /** The environment-wide list's Organization chip; null on the organization console. */
    organizationFilter: OrganizationFilter | null;
    /** "Who is it for?" on the setup form — the environment console only. */
    organization: OrganizationPicker | null;
    /** Back to the environment's providers, from one organization's view on the environment console. */
    environmentHref: string | null;
    indexHref: string;
    storeHref: string;
    help: HelpContent;
}>;

export default function SocialProviders({
    view,
    organizationName,
    environmentProviders,
    organizationProviders,
    optOuts,
    page,
    available,
    template,
    editing,
    organizationFilter,
    organization,
    environmentHref,
    indexHref,
    storeHref,
    help,
}: Props) {
    const [removing, setRemoving] = useState<{ name: string; href: string } | null>(null);
    const [turningOff, setTurningOff] = useState<{
        name: string;
        href: string;
        everywhere: boolean;
    } | null>(null);

    const controls: RowControls = {
        onRemove: (row) => setRemoving({ name: row.name, href: row.removeHref }),
        onTurnOff: (row, everywhere) =>
            setTurningOff({ name: row.name, href: row.disableHref, everywhere }),
    };

    return (
        <>
            <PageHeader
                help={help}
                description={
                    view === 'environment'
                        ? 'Let people sign in with an account they already have. Set a provider up once for the environment and every organization offers it; an organization can bring its own credentials instead, or turn one off.'
                        : `What ${organizationName ?? 'this organization'}’s sign-in page offers. Providers from the environment are inherited; this organization can turn one off, or set up its own in its place.`
                }
            />

            <div className="mt-6 space-y-5">
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}

                {editing !== null && <EditPanel editing={editing} cancelHref={indexHref} />}

                {template !== null && (
                    <SetupPanel
                        template={template}
                        organization={organization}
                        storeHref={storeHref}
                        cancelHref={indexHref}
                    />
                )}

                {view === 'environment' ? (
                    <>
                        <Panel
                            title="On every sign-in page"
                            description="Your own credentials with each provider, offered to everyone in this environment. Every organization inherits these."
                        >
                            {environmentProviders.length === 0 ? (
                                <Empty
                                    lines={[
                                        'No providers for the environment yet — people sign in with a password, a magic link or a passkey.',
                                        'Choose one under Add a provider to offer it on every sign-in page.',
                                    ]}
                                />
                            ) : (
                                <ul>
                                    {environmentProviders.map((row, index) => (
                                        <ProviderItem
                                            key={row.id}
                                            row={row}
                                            last={index === environmentProviders.length - 1}
                                            everywhere
                                            controls={controls}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Panel>

                        {(organizationProviders.length > 0 || optOuts.length > 0) && (
                            <Panel
                                title="Set by one organization"
                                description="An organization's own provider replaces the environment's on its sign-in page. One that turned the environment's off is listed too."
                            >
                                <ul>
                                    {organizationProviders.map((row, index) => (
                                        <ProviderItem
                                            key={row.id}
                                            row={row}
                                            last={
                                                index === organizationProviders.length - 1 &&
                                                optOuts.length === 0
                                            }
                                            everywhere={false}
                                            controls={controls}
                                        />
                                    ))}
                                    {optOuts.map((row, index) => (
                                        <li
                                            key={`${row.organizationId}:${row.provider}`}
                                            className="flex flex-wrap items-center gap-3 py-3.5"
                                            style={
                                                index < optOuts.length - 1
                                                    ? { borderBottom: '1px solid var(--border)' }
                                                    : undefined
                                            }
                                        >
                                            <ProviderMark provider={row.provider} size={20} />
                                            <p className="min-w-0 flex-1 text-sm">
                                                <span className="font-medium">{row.name}</span>{' '}
                                                <span style={{ color: 'var(--muted-foreground)' }}>
                                                    is turned off on {row.organization}’s sign-in
                                                    page.
                                                </span>
                                            </p>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.put(
                                                        row.inheritHref,
                                                        {
                                                            organization: row.organizationId,
                                                            offered: true,
                                                        },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Offer it again
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </Panel>
                        )}
                    </>
                ) : (
                    <Panel
                        title={`On ${organizationName ?? 'this organization'}’s sign-in page`}
                        description="In the order people see them."
                        action={
                            environmentHref !== null ? (
                                <Link href={environmentHref} className="btn btn-ghost btn-sm">
                                    The environment’s providers
                                </Link>
                            ) : undefined
                        }
                    >
                        {page.length === 0 ? (
                            <Empty
                                lines={[
                                    'No social providers here yet — people sign in with a password, a magic link or a passkey.',
                                    'Add one below to offer it on this organization’s page.',
                                ]}
                            />
                        ) : (
                            <ul>
                                {page.map((row, index) => (
                                    <PageItem
                                        key={row.id}
                                        row={row}
                                        last={index === page.length - 1}
                                        organizationName={organizationName}
                                        controls={controls}
                                    />
                                ))}
                            </ul>
                        )}
                    </Panel>
                )}

                {available.length > 0 && (
                    <Panel
                        title="Add a provider"
                        description="You need credentials from your own account with the provider — usually a client ID and secret, though Apple issues a signing key instead."
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
                                        aria-label={`Add ${option.name}`}
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
                consequence="It stops appearing as a button, and its credentials are deleted. Anyone who signed in with it keeps their account and can still use their password. To keep the credentials, turn it off instead."
                onConfirm={() => {
                    const target = removing;
                    setRemoving(null);

                    if (target !== null) {
                        router.delete(target.href, { preserveScroll: true });
                    }
                }}
            />

            <ConfirmDelete
                open={turningOff !== null}
                onOpenChange={(open) => !open && setTurningOff(null)}
                name={turningOff?.name ?? ''}
                verb="Turn off"
                consequence={
                    turningOff?.everywhere === true
                        ? 'The button disappears from every sign-in page in this environment that inherits it. Its credentials are kept, so you can turn it back on.'
                        : 'The button disappears from this organization’s sign-in page — the environment’s own does not take its place. Its credentials are kept, so you can turn it back on.'
                }
                onConfirm={() => {
                    const target = turningOff;
                    setTurningOff(null);

                    if (target !== null) {
                        router.post(target.href, {}, { preserveScroll: true });
                    }
                }}
            />
        </>
    );
}

interface RowControls {
    onRemove: (row: { name: string; removeHref: string }) => void;
    onTurnOff: (row: { name: string; disableHref: string }, everywhere: boolean) => void;
}

function Empty({ lines }: { lines: string[] }) {
    return (
        <div className="py-6 text-center">
            {lines.map((line) => (
                <p
                    key={line}
                    className="text-sm mt-1 first:mt-0"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    {line}
                </p>
            ))}
        </div>
    );
}

/**
 * The redirect URI a provider must be given, copyable. Shown on every row: it is available
 * nowhere else, and a mismatch fails with an error naming the client id rather than the URI.
 */
function RedirectUri({ name, uri }: { name: string; uri: string }) {
    return (
        <p className="mt-1.5 flex items-center gap-2 flex-wrap">
            <span className="mono text-xs break-all" style={{ color: 'var(--muted-foreground)' }}>
                <span style={{ color: 'var(--foreground)' }}>Redirect URI:</span> {uri}
            </span>
            <CopyButton size="sm" value={uri} label={`Copy the redirect URI for ${name}`} />
        </p>
    );
}

/** The controls a provider's OWNER has: change, turn off or on, remove. */
function OwnerControls({
    row,
    everywhere,
    controls,
}: {
    row: {
        name: string;
        enabled: boolean;
        editHref: string;
        enableHref: string;
        disableHref: string;
        removeHref: string;
    };
    everywhere: boolean;
    controls: RowControls;
}) {
    return (
        <div className="flex flex-wrap gap-2">
            <Button size="sm" asChild>
                <Link href={row.editHref} preserveScroll aria-label={`Change ${row.name}`}>
                    Change
                </Link>
            </Button>
            {row.enabled ? (
                <Button
                    size="sm"
                    onClick={() => controls.onTurnOff(row, everywhere)}
                    aria-label={`Turn off ${row.name}`}
                >
                    Turn off
                </Button>
            ) : (
                <Button
                    size="sm"
                    onClick={() => router.post(row.enableHref, {}, { preserveScroll: true })}
                    aria-label={`Turn on ${row.name}`}
                >
                    Turn on
                </Button>
            )}
            <Button
                size="sm"
                variant="danger"
                onClick={() => controls.onRemove(row)}
                aria-label={`Remove ${row.name}`}
            >
                Remove
            </Button>
        </div>
    );
}

function ProviderItem({
    row,
    last,
    everywhere,
    controls,
}: {
    row: ProviderRow;
    last: boolean;
    everywhere: boolean;
    controls: RowControls;
}) {
    return (
        <li
            className="flex flex-col gap-3 py-3.5 sm:flex-row sm:items-start"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="flex min-w-0 flex-1 gap-3">
                <ProviderMark provider={row.provider ?? ''} size={20} />
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-sm flex flex-wrap items-center gap-1.5">
                        {row.name}
                        {row.organization !== null && <Badge>{row.organization}</Badge>}
                        {row.replacesEnvironment && <Badge>Replaces the environment’s</Badge>}
                        <Pill tone={row.enabled ? 'success' : 'neutral'}>
                            {row.enabled ? 'On' : 'Off'}
                        </Pill>
                    </p>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {row.protocol}
                        {row.scopes.length > 0 && (
                            <>
                                {' · also asks for '}
                                <span className="mono">{row.scopes.join(' ')}</span>
                            </>
                        )}
                    </p>
                    <RedirectUri name={row.name} uri={row.callbackUri} />
                </div>
            </div>
            <OwnerControls row={row} everywhere={everywhere} controls={controls} />
        </li>
    );
}

const PAGE_STATE: Record<PageRow['state'], { label: string; tone: 'success' | 'neutral' }> = {
    offered: { label: 'On', tone: 'success' },
    hidden: { label: 'Turned off here', tone: 'neutral' },
    replaced: { label: 'Replaced by its own', tone: 'neutral' },
    off: { label: 'Off', tone: 'neutral' },
};

/** One button on one organization's page — inherited, or the organization's own. */
function PageItem({
    row,
    last,
    organizationName,
    controls,
}: {
    row: PageRow;
    last: boolean;
    organizationName: string | null;
    controls: RowControls;
}) {
    const own = row.source === 'organization';
    const state = PAGE_STATE[row.state];

    return (
        <li
            className="flex flex-col gap-3 py-3.5 sm:flex-row sm:items-start"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="flex min-w-0 flex-1 gap-3">
                <ProviderMark provider={row.provider ?? ''} size={20} />
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-sm flex flex-wrap items-center gap-1.5">
                        {row.name}
                        <Badge>
                            {own
                                ? `${organizationName ?? 'This organization'}’s own`
                                : 'From the environment'}
                        </Badge>
                        {own && row.replacesEnvironment === true && (
                            <Badge>Replaces the environment’s</Badge>
                        )}
                        <Pill tone={state.tone}>{state.label}</Pill>
                    </p>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {row.protocol}
                        {row.state === 'off' && !own && ' · turned off for the whole environment'}
                    </p>
                    {own && <RedirectUri name={row.name} uri={row.callbackUri} />}
                </div>
            </div>
            {own &&
            row.editHref !== undefined &&
            row.enableHref !== undefined &&
            row.disableHref !== undefined &&
            row.removeHref !== undefined ? (
                <OwnerControls
                    row={{
                        name: row.name,
                        enabled: row.enabled,
                        editHref: row.editHref,
                        enableHref: row.enableHref,
                        disableHref: row.disableHref,
                        removeHref: row.removeHref,
                    }}
                    everywhere={false}
                    controls={controls}
                />
            ) : (
                row.inheritHref !== null &&
                (row.state === 'offered' || row.state === 'hidden') && (
                    <Button
                        size="sm"
                        aria-label={
                            row.state === 'offered'
                                ? `Turn off ${row.name} on this page`
                                : `Offer ${row.name} on this page again`
                        }
                        onClick={() => {
                            const href = row.inheritHref;

                            if (href !== null) {
                                router.put(
                                    href,
                                    { offered: row.state === 'hidden' },
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        {row.state === 'offered' ? 'Turn off here' : 'Offer it again'}
                    </Button>
                )
            )}
        </li>
    );
}

/**
 * The credential fields both forms share, in the order the work happens: the provider's own
 * values, the client id, the secret (or Apple's note that there is none), extra scopes.
 */
function CredentialFields({
    template,
    data,
    errors,
    setData,
    keepingSecrets,
}: {
    template: Template;
    data: {
        clientId: string;
        clientSecret: string;
        parameters: Record<string, string>;
        scopes: string;
    };
    errors: Partial<Record<string, string>>;
    setData: (key: 'clientId' | 'clientSecret' | 'parameters' | 'scopes', value: never) => void;
    /** On the change form: a blank secret keeps the one on file. */
    keepingSecrets: boolean;
}) {
    const setParameter = (key: string, value: string) =>
        setData('parameters', { ...data.parameters, [key]: value } as never);

    return (
        <>
            {template.parameters.map((parameter) => (
                <Field
                    key={parameter.key}
                    label={parameter.label}
                    hint={
                        keepingSecrets && parameter.multiline
                            ? 'Leave empty to keep the one on file.'
                            : parameter.help === ''
                              ? undefined
                              : parameter.help
                    }
                    error={errors[`parameters.${parameter.key}`]}
                >
                    {parameter.multiline ? (
                        <Textarea
                            rows={4}
                            className="mono text-xs"
                            placeholder={parameter.example}
                            value={data.parameters[parameter.key] ?? ''}
                            onChange={(event) => setParameter(parameter.key, event.target.value)}
                        />
                    ) : (
                        <Input
                            placeholder={parameter.example}
                            value={data.parameters[parameter.key] ?? ''}
                            onChange={(event) => setParameter(parameter.key, event.target.value)}
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
                error={errors.clientId}
            >
                <Input
                    name="clientId"
                    value={data.clientId}
                    onChange={(event) => setData('clientId', event.target.value as never)}
                />
            </Field>

            {/*
                ASKED FOR ONLY WHERE ONE EXISTS. Apple issues no client secret: the
                credential is an ES256 assertion minted per request from the key above.
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
                    There is no client secret to paste. {template.name} expects a signed assertion
                    instead, which we mint from the key above for each sign-in and renew long before
                    it expires.
                </p>
            ) : (
                <Field
                    label="Client secret"
                    hint={keepingSecrets ? 'Leave empty to keep the one on file.' : undefined}
                    error={errors.clientSecret}
                >
                    <Input
                        name="clientSecret"
                        type="password"
                        autoComplete="off"
                        value={data.clientSecret}
                        onChange={(event) => setData('clientSecret', event.target.value as never)}
                    />
                </Field>
            )}

            <Field
                label="Extra scopes (optional)"
                hint={`Only if your app needs more from ${template.name} than sign-in does — separated by spaces. Sign-in's own scopes are always requested.`}
                error={errors.scopes}
            >
                <Input
                    name="scopes"
                    className="mono"
                    value={data.scopes}
                    onChange={(event) => setData('scopes', event.target.value as never)}
                />
            </Field>
        </>
    );
}

/** A copyable redirect URI on a setup or change form. */
function RedirectUriBlock({ uri }: { uri: string }) {
    return (
        <div className="mt-2 flex items-center gap-2">
            <p
                className="mono text-xs break-all rounded-lg px-3 py-2 min-w-0 flex-1"
                style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
            >
                {uri}
            </p>
            <CopyButton value={uri} label="Copy the redirect URI" />
        </div>
    );
}

function PanelHead({ template, title }: { template: Template; title: string }) {
    return (
        <div
            className="p-4 flex items-center gap-2.5"
            style={{ borderBottom: '1px solid var(--border)' }}
        >
            <ProviderMark provider={template.key} size={22} />
            <div className="min-w-0">
                <h2 className="font-semibold text-sm">{title}</h2>
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
    );
}

/**
 * The setup panel, in the order the work actually happens.
 *
 * WHO IT IS FOR comes first on the environment console, and defaults to the whole
 * environment — the common case, and the one the button on every sign-in page needs. The
 * redirect URI comes next, before the credential fields, and it is the REAL one: the id it
 * contains was reserved when this form was drawn, so it can be pasted into the provider's
 * console now rather than fixed up after saving.
 */
function SetupPanel({
    template,
    organization,
    storeHref,
    cancelHref,
}: {
    template: SetupTemplate;
    organization: OrganizationPicker | null;
    storeHref: string;
    cancelHref: string;
}) {
    const form = useForm({
        provider: template.key,
        organization: organization?.selected?.id ?? '',
        reservedId: template.reservedId,
        clientId: '',
        clientSecret: '',
        scopes: '',
        parameters: Object.fromEntries(
            template.parameters.map((parameter) => [parameter.key, '']),
        ) as Record<string, string>,
    });
    const forEnvironment = organization !== null && form.data.organization === '';

    return (
        <div className="card">
            <PanelHead template={template} title={`Set up ${template.name}`} />

            <form
                className="p-4 space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, { preserveScroll: true });
                }}
            >
                {organization !== null && (
                    <OrganizationPickerField
                        picker={organization}
                        label="Who is it for?"
                        error={form.errors.organization}
                        onChange={(id) => form.setData('organization', id)}
                        hint="The whole environment offers it on every sign-in page. Choose one organization to set up its own instead — it then replaces the environment's on that organization's page."
                    />
                )}

                <div>
                    <p className="text-sm font-semibold">1. Register this redirect URI</p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {template.name} refuses the sign-in unless this matches exactly. It is
                        reserved for this {template.name} — paste it into {template.name} before you
                        save here.
                    </p>
                    <RedirectUriBlock uri={template.redirectUri} />
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
                    <CredentialFields
                        template={template}
                        data={form.data}
                        errors={form.errors as Partial<Record<string, string>>}
                        setData={(key, value) => form.setData(key, value)}
                        keepingSecrets={false}
                    />
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
                            : forEnvironment
                              ? `Turn on ${template.name} for everyone`
                              : `Turn on ${template.name}`}
                    </Button>
                </div>
            </form>
        </div>
    );
}

/** New credentials for a provider that exists — rotating a secret keeps its redirect URI. */
function EditPanel({ editing, cancelHref }: { editing: Editing; cancelHref: string }) {
    const form = useForm({
        clientId: editing.clientId,
        clientSecret: '',
        scopes: editing.scopes,
        parameters: editing.values,
        fromOrganization: editing.fromOrganization,
    });

    return (
        <div className="card">
            <PanelHead
                template={editing}
                title={`Change ${editing.name}${editing.organization === null ? ' for the environment' : ` for ${editing.organization}`}`}
            />

            <form
                className="p-4 space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(editing.updateHref, { preserveScroll: true });
                }}
            >
                <div>
                    <p className="text-sm font-semibold">Redirect URI</p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        Unchanged by anything here, so nothing to update in {editing.name}.
                    </p>
                    <RedirectUriBlock uri={editing.redirectUri} />
                </div>

                <CredentialFields
                    template={editing}
                    data={form.data}
                    errors={form.errors as Partial<Record<string, string>>}
                    setData={(key, value) => form.setData(key, value)}
                    keepingSecrets
                />

                <div className="flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
                    <Button asChild>
                        <Link href={cancelHref} preserveScroll>
                            Cancel
                        </Link>
                    </Button>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save {editing.name}
                    </Button>
                </div>
            </form>
        </div>
    );
}

SocialProviders.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
