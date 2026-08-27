import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    Field,
    Icon,
    Input,
    Panel,
    Tab,
    TabPanel,
    Tabs,
    Textarea,
} from '@/ui';

interface Snippet {
    id: string;
    label: string;
    code: string;
    install: string | null;
    docs: string | null;
}

interface ScopeOption {
    key: string;
    label: string;
    description: string;
}

type Props = PageProps<{
    client: {
        id: string;
        name: string;
        clientId: string;
        confidential: boolean;
        /** Authenticates with its own keys, so it has no secret to rotate. */
        signsAssertions: boolean;
        firstParty: boolean;
        redirectUris: string;
        postLogoutRedirectUris: string;
        manifestUrl: string;
        scopes: string[];
        customScopes: string;
        kind: string;
        kindLabel: string;
    };
    issuer: string;
    discovery: string;
    snippets: Snippet[];
    scopeGroups: Record<string, ScopeOption[]>;
    declaredRoles: number;
    mayManage: boolean;
    /** A step-up was just cleared for a rotation — say so, do not perform it. */
    stepUpCleared: boolean;
    indexHref: string;
    urls: {
        update: string;
        manifest: string;
        sync: string;
        rotate: string;
        destroy: string;
    };
}>;

export default function ClientDetail({
    client,
    issuer,
    snippets,
    scopeGroups,
    declaredRoles,
    mayManage,
    stepUpCleared,
    indexHref,
    urls,
}: Props) {
    // The plaintext, on the flash channel and nowhere else: page props are written into
    // the browser's history entry, where a live credential outlives the page that showed
    // it and is retrievable by pressing Back.
    const revealedSecret = usePage().flash.revealedSecret;

    const [confirming, setConfirming] = useState<'rotate' | 'delete' | null>(null);
    const [sdk, setSdk] = useState(snippets[0]?.id ?? '');
    const [manifestOpen, setManifestOpen] = useState(false);

    const details = useForm({
        name: client.name,
        redirectUris: client.redirectUris,
        postLogoutRedirectUris: client.postLogoutRedirectUris,
        scopes: client.scopes,
        customScopes: client.customScopes,
    });

    const manifest = useForm({ manifestUrl: client.manifestUrl });

    const save = (): void => details.patch(urls.update, { preserveScroll: true });

    return (
        <div className="space-y-6">
            <div>
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
                    Apps &amp; API keys
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{client.name}</h1>
                    {client.firstParty && (
                        <span
                            className="text-xs rounded-full px-2 py-0.5"
                            style={{
                                background: 'var(--accent-soft)',
                                color: 'var(--accent-strong)',
                            }}
                        >
                            First-party
                        </span>
                    )}
                    <Badge>{client.confidential ? 'Confidential' : 'Public'}</Badge>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {client.id}
                </p>
            </div>

            {revealedSecret !== undefined && (
                <RevealedSecret
                    secret={revealedSecret}
                    issuer={issuer}
                    clientId={client.clientId}
                />
            )}

            {/*
                A STEP-UP THAT HAS JUST BEEN CLEARED. Rotation sends the administrator to
                re-enter their password and brings them back here — to a page that looks
                exactly as it did, with nothing rotated and nothing explaining why. People
                concluded it was broken and did the whole thing again.
            */}
            {stepUpCleared && revealedSecret === undefined && mayManage && (
                <output
                    className="block rounded-xl border p-4 text-sm"
                    style={{
                        borderColor: 'color-mix(in oklch, var(--accent) 40%, transparent)',
                        background: 'var(--accent-soft)',
                    }}
                >
                    Your password is confirmed. Press <strong>Rotate secret</strong> below to issue
                    the new one.
                </output>
            )}

            {/*
                ALWAYS, NOT ONLY IN THE MINUTE AFTER CREATION. This lived inside the reveal
                card, which renders only when a plaintext secret was just flashed — so a
                public client never saw it at all, and that is precisely the CLI and the
                SPA: the two kinds whose author has no existing snippet to adapt.
            */}
            <Panel title="Connect it" description={client.kindLabel}>
                {/*
                    TABS, because the page used to show ONE example in JavaScript whatever
                    the reader was building — and this screen is also where a one-time
                    secret sits, so translating a JS snippet into Go happens under time
                    pressure. Which SDKs appear follows from the app's kind: a device-flow
                    tab under a service app is a tab that cannot work.
                */}
                {snippets.length > 0 && (
                    <Tabs
                        value={sdk}
                        onValueChange={setSdk}
                        label="SDK examples"
                        panels={snippets.map((snippet) => (
                            <TabPanel key={snippet.id} value={snippet.id}>
                                <SnippetPanel snippet={snippet} />
                            </TabPanel>
                        ))}
                    >
                        {snippets.map((snippet) => (
                            <Tab key={snippet.id} value={snippet.id}>
                                {snippet.label}
                            </Tab>
                        ))}
                    </Tabs>
                )}
            </Panel>

            <Panel title="Credentials">
                <div className="space-y-3">
                    <CopyableValue
                        label="Issuer"
                        value={issuer}
                        hint={
                            <>
                                This environment's own issuer — what an SDK discovers from and what
                                the <code className="mono">iss</code> claim carries.
                            </>
                        }
                    />
                    <CopyableValue label="Client ID" value={client.clientId} />

                    <div>
                        <p className="label">Client secret</p>
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {client.confidential
                                ? 'Stored as a hash and shown only once. Rotate to issue a new one.'
                                : 'None — this is a public app and uses PKCE instead of a secret.'}
                        </p>
                    </div>
                </div>
            </Panel>

            {!mayManage && (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        This app belongs to the platform and is available to every organization in
                        this environment. Your operator manages it.
                    </p>
                </Panel>
            )}

            {mayManage && (
                <Panel title="Details">
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <Field label="Name" error={details.errors.name}>
                            <Input
                                name="name"
                                value={details.data.name}
                                onChange={(event) => details.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={
                                <>
                                    Redirect URIs{' '}
                                    <span style={{ color: 'var(--faint)', fontWeight: 400 }}>
                                        — one per line
                                    </span>
                                </>
                            }
                            error={details.errors.redirectUris}
                        >
                            <Textarea
                                name="redirectUris"
                                rows={3}
                                className="mono"
                                spellCheck={false}
                                placeholder="https://app.example.com/auth/callback"
                                value={details.data.redirectUris}
                                onChange={(event) =>
                                    details.setData('redirectUris', event.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label={
                                <>
                                    Sign-out URIs{' '}
                                    <span style={{ color: 'var(--faint)', fontWeight: 400 }}>
                                        — one per line
                                    </span>
                                </>
                            }
                            hint="Where Cbox ID sends people after they sign out of this app. The URI the app asks for has to appear here character for character — trailing slash and all — or Cbox ID leaves the person on its own signed-out page. Leave empty if the app never sends people back."
                            error={details.errors.postLogoutRedirectUris}
                        >
                            <Textarea
                                name="postLogoutRedirectUris"
                                rows={3}
                                className="mono"
                                spellCheck={false}
                                placeholder="https://app.example.com/signed-out"
                                value={details.data.postLogoutRedirectUris}
                                onChange={(event) =>
                                    details.setData('postLogoutRedirectUris', event.target.value)
                                }
                            />
                        </Field>

                        <Button type="submit" variant="primary" loading={details.processing}>
                            Save changes
                        </Button>
                    </form>
                </Panel>
            )}

            <Panel title="Connection &amp; permissions">
                <div>
                    <p className="label">Connects via</p>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {client.kindLabel}
                    </p>
                    <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                        How an app connects is fixed at registration: changing it changes what the
                        credentials mean, so register a new app rather than repurposing this one.
                    </p>
                </div>

                {mayManage ? (
                    <form
                        className="mt-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <span className="label">What this app may ask for</span>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            Scopes — the ceiling on this app's access. Narrowing it takes effect on
                            the next token, and a device or agent request naming a scope you remove
                            is refused rather than quietly given less.
                        </p>

                        <div className="mt-3 space-y-4">
                            {Object.entries(scopeGroups).map(([group, scopes]) => (
                                <div key={group}>
                                    <p className="cbx-nav-group mb-2">{group}</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {scopes.map((scope) => (
                                            <div
                                                key={scope.key}
                                                className="rounded-lg p-2.5"
                                                style={{ border: '1px solid var(--border)' }}
                                            >
                                                <Checkbox
                                                    checked={details.data.scopes.includes(
                                                        scope.key,
                                                    )}
                                                    onCheckedChange={(checked) =>
                                                        details.setData(
                                                            'scopes',
                                                            checked
                                                                ? [
                                                                      ...details.data.scopes,
                                                                      scope.key,
                                                                  ]
                                                                : details.data.scopes.filter(
                                                                      (s) => s !== scope.key,
                                                                  ),
                                                        )
                                                    }
                                                    label={
                                                        <span className="flex items-center gap-2 flex-wrap">
                                                            {scope.label}
                                                            <span
                                                                className="text-xs rounded-full px-2 py-0.5 mono"
                                                                style={{
                                                                    background: 'var(--surface-2)',
                                                                    color: 'var(--muted-foreground)',
                                                                }}
                                                            >
                                                                {scope.key}
                                                            </span>
                                                        </span>
                                                    }
                                                    hint={scope.description}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="mt-4">
                            <Field
                                label="Advanced — custom scopes"
                                hint="Keys your own APIs check for. Kept as typed — a scope the catalogue does not know survives an edit here."
                                error={details.errors.customScopes}
                            >
                                <Input
                                    name="customScopes"
                                    className="mono"
                                    placeholder="api.read, tax.data"
                                    value={details.data.customScopes}
                                    onChange={(event) =>
                                        details.setData('customScopes', event.target.value)
                                    }
                                />
                            </Field>
                        </div>

                        <Button
                            type="submit"
                            variant="primary"
                            className="mt-4"
                            loading={details.processing}
                        >
                            Save scopes
                        </Button>
                    </form>
                ) : (
                    <div className="mt-4">
                        <p className="label">Scopes</p>
                        {client.scopes.length === 0 ? (
                            <span className="text-sm" style={{ color: 'var(--faint)' }}>
                                —
                            </span>
                        ) : (
                            <div className="flex flex-wrap gap-1.5">
                                {client.scopes.map((scope) => (
                                    <Badge key={scope} className="mono">
                                        {scope}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </Panel>

            {mayManage && (
                <Panel
                    title="Roles &amp; permissions"
                    description="The app declares these — Cbox ID pulls them from its manifest URL, or the app pushes them. They become assignable once they arrive."
                    action={
                        <Button
                            size="sm"
                            className="shrink-0"
                            aria-expanded={manifestOpen}
                            onClick={() => setManifestOpen((open) => !open)}
                        >
                            Manifest{declaredRoles > 0 ? ` · ${declaredRoles}` : ''}
                        </Button>
                    }
                >
                    {manifestOpen && (
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                manifest.put(urls.manifest, { preserveScroll: true });
                            }}
                        >
                            <div className="flex-1 min-w-[18rem]">
                                <Field label="Manifest URL" error={manifest.errors.manifestUrl}>
                                    <Input
                                        name="manifestUrl"
                                        type="url"
                                        className="mono"
                                        spellCheck={false}
                                        placeholder="https://app.example.com/.well-known/cbox-authz"
                                        value={manifest.data.manifestUrl}
                                        onChange={(event) =>
                                            manifest.setData('manifestUrl', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>

                            <Button
                                type="submit"
                                variant="primary"
                                size="sm"
                                loading={manifest.processing}
                            >
                                Save &amp; sync
                            </Button>

                            {client.manifestUrl !== '' && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() =>
                                        router.post(urls.sync, {}, { preserveScroll: true })
                                    }
                                >
                                    Sync now
                                </Button>
                            )}
                        </form>
                    )}

                    <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {declaredRoles > 0
                            ? `${declaredRoles} role(s) declared.`
                            : 'No roles declared yet — set a manifest URL and sync, or have the app push its manifest.'}
                    </p>
                </Panel>
            )}

            {/*
                Not shown for an app that authenticates with its own keys: there is no
                secret to rotate, and "the current one stops working" would be false — the
                button would CREATE a credential rather than replace one.
            */}
            {mayManage && client.confidential && !client.signsAssertions && (
                <Panel
                    title="Rotate secret"
                    description="Issue a fresh client secret. The current one stops working — update the app before rotating."
                >
                    <Button size="sm" onClick={() => setConfirming('rotate')}>
                        Rotate secret
                    </Button>
                </Panel>
            )}

            {mayManage && (
                <Panel
                    title="Delete app"
                    description="Anything using its credentials will stop working. This cannot be undone."
                >
                    <Button size="sm" variant="danger" onClick={() => setConfirming('delete')}>
                        Delete app
                    </Button>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming === 'rotate'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={client.name}
                verb="Rotate"
                consequence="The current client secret stops working immediately and cannot be recovered — every deployment still holding it starts failing authentication."
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.rotate, {}, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={client.name}
                consequence="Anything using this app's credentials will stop working immediately. This cannot be undone."
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * The one moment the plaintext exists.
 *
 * IT BRINGS ITSELF INTO VIEW. Rotation is triggered from the bottom of the page and
 * reveals the new secret at the TOP of it, so the only feedback in the viewport was a
 * toast in the opposite corner — and the person was left to guess that the thing they
 * asked for had happened somewhere they could not see. Focus moves too, so a screen reader
 * lands on the heading rather than being scrolled silently.
 */
function RevealedSecret({
    secret,
    issuer,
    clientId,
}: {
    secret: string;
    issuer: string;
    clientId: string;
}) {
    const card = useRef<HTMLDivElement>(null);

    useEffect(() => {
        card.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        card.current?.focus({ preventScroll: true });
    }, []);

    return (
        <div
            ref={card}
            tabIndex={-1}
            className="rounded-xl border p-5"
            style={{
                borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)',
                background: 'var(--warning-soft)',
            }}
        >
            <p className="text-sm font-semibold" style={{ color: 'var(--warning-strong)' }}>
                Copy your client secret now
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Only a hash is stored, so it won't be shown again. If you lose it, rotate to issue a
                new one.
            </p>

            {/*
                A COPY BUTTON ON EVERY VALUE. Each of these is going into a config file or
                an environment variable, and one of them is shown exactly once — so "select
                it carefully with the mouse" is the wrong ask, and a mis-selected secret is
                unrecoverable rather than merely annoying.
            */}
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <CopyableValue label="Issuer" value={issuer} boxed />
                <CopyableValue label="Client ID" value={clientId} boxed />
                <div className="sm:col-span-2">
                    <CopyableValue
                        label="Client secret — copy it now, it won't be shown again"
                        value={secret}
                        boxed
                        emphatic
                    />
                </div>
            </div>
        </div>
    );
}

function CopyableValue({
    label,
    value,
    hint,
    boxed = false,
    emphatic = false,
}: {
    label: React.ReactNode;
    value: string;
    hint?: React.ReactNode;
    boxed?: boolean;
    emphatic?: boolean;
}) {
    return (
        <div
            className={boxed ? 'rounded-lg p-3' : undefined}
            style={
                boxed
                    ? { background: 'var(--surface-2)', border: '1px solid var(--border)' }
                    : undefined
            }
        >
            <p
                className={boxed ? 'text-xs' : 'label'}
                style={
                    emphatic
                        ? { color: 'var(--warning-strong)', fontWeight: 600 }
                        : boxed
                          ? { color: 'var(--muted-foreground)' }
                          : undefined
                }
            >
                {label}
            </p>
            <div className="mt-1 flex items-start gap-2">
                <code className="mono text-sm break-all select-all flex-1">{value}</code>
                <CopyButton
                    value={value}
                    variant={emphatic ? 'primary' : 'ghost'}
                    label={emphatic ? 'Copy secret' : 'Copy'}
                />
            </div>
            {hint !== undefined && (
                <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                    {hint}
                </p>
            )}
        </div>
    );
}

function SnippetPanel({ snippet }: { snippet: Snippet }) {
    return (
        <div>
            {snippet.install !== null && (
                <div className="flex items-center gap-2">
                    <p
                        className="mono text-xs rounded-lg px-3 py-2 flex-1 select-all"
                        style={{
                            background: 'var(--surface-2)',
                            border: '1px solid var(--border)',
                            color: 'var(--muted-foreground)',
                        }}
                    >
                        {snippet.install}
                    </p>
                    <CopyButton value={snippet.install} />
                </div>
            )}

            <div className="mt-2 flex items-start gap-2">
                <pre
                    className="rounded-lg p-3 overflow-x-auto text-xs mono flex-1"
                    style={{
                        background: 'var(--surface-2)',
                        border: '1px solid var(--border)',
                        lineHeight: 1.6,
                    }}
                >
                    {snippet.code}
                </pre>
                <CopyButton value={snippet.code} />
            </div>

            {snippet.docs !== null && (
                <p className="mt-2 text-xs">
                    {/*
                        A new tab: this is a reference opened WHILE wiring an app up, and on
                        the one screen that shows a secret exactly once. Navigating away
                        from it is how the secret is lost.
                    */}
                    <a
                        href={snippet.docs}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="underline"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {snippet.label} SDK reference
                        <Icon name="external" className="w-3 h-3 inline ml-0.5" />
                    </a>
                </p>
            )}
        </div>
    );
}

ClientDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
