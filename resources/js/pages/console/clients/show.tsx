import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { lines } from '@/lib/apiSnippets';
import {
    ApiEquivalent,
    Button,
    ConfirmDelete,
    Field,
    Input,
    Panel,
    SnippetTabs,
    Textarea,
} from '@/ui';
import { AppFrame, type AppHeaderData, CopyableValue } from './frame';

interface Snippet {
    id: string;
    label: string;
    code: string;
    install: string | null;
    docs: string | null;
}

type Props = PageProps<{
    appHeader: AppHeaderData;
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
        kind: string;
        kindLabel: string;
    };
    issuer: string;
    discovery: string;
    snippets: Snippet[];
    declaredRoles: number;
    mayManage: boolean;
    urls: {
        update: string;
        manifest: string;
        sync: string;
        /** Null when this app holds no shared secret, or this administrator may not manage it. */
        secrets: string | null;
        destroy: string;
    };
}>;

export default function ClientDetail({
    appHeader,
    client,
    issuer,
    snippets,
    declaredRoles,
    mayManage,
    urls,
}: Props) {
    const [confirming, setConfirming] = useState<'delete' | null>(null);
    const [sdk, setSdk] = useState(snippets[0]?.id ?? '');
    const [manifestOpen, setManifestOpen] = useState(false);

    const details = useForm({
        name: client.name,
        redirectUris: client.redirectUris,
        postLogoutRedirectUris: client.postLogoutRedirectUris,
    });

    const manifest = useForm({ manifestUrl: client.manifestUrl });

    const save = (): void => details.patch(urls.update, { preserveScroll: true });

    return (
        <AppFrame app={appHeader} issuer={issuer}>
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
                <SnippetTabs
                    snippets={snippets}
                    value={sdk}
                    onValueChange={setSdk}
                    label="SDK examples"
                />
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
                            {!client.confidential
                                ? 'None — this is a public app and uses PKCE instead of a secret.'
                                : client.signsAssertions
                                  ? 'None — this app signs in with its own keys instead of a secret.'
                                  : 'Stored as a hash and shown only once.'}
                        </p>
                        {urls.secrets !== null && (
                            <Link
                                href={urls.secrets}
                                className="mt-1 inline-block text-sm underline"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                Rotate or revoke secrets
                            </Link>
                        )}
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

                        <div className="flex flex-wrap items-start gap-2">
                            <Button type="submit" variant="primary" loading={details.processing}>
                                Save changes
                            </Button>
                            <ApiEquivalent
                                action="apps.update"
                                values={{
                                    name: details.data.name,
                                    redirect_uris: lines(details.data.redirectUris),
                                    post_logout_redirect_uris: lines(
                                        details.data.postLogoutRedirectUris,
                                    ),
                                }}
                            />
                        </div>
                    </form>
                </Panel>
            )}

            <Panel title="Connects via">
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {client.kindLabel}
                </p>
                <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                    How an app connects is fixed at registration: changing it changes what the
                    credentials mean, so register a new app rather than repurposing this one.
                </p>
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
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={client.name}
                consequence="Anything using this app's credentials will stop working immediately. This cannot be undone."
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </AppFrame>
    );
}

ClientDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
