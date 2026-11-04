import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

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
            <Panel title={tc('console.connect_it_00ff2248')} description={client.kindLabel}>
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
                    label={tc('console.sdk_examples_fa304ed5')}
                />
            </Panel>

            <Panel title={tc('console.credentials_d48a617c')}>
                <div className="space-y-3">
                    <CopyableValue
                        label={tc('console.issuer_39e02c46')}
                        value={issuer}
                        hint={
                            <>
                                {tc(
                                    'console.this_environment_s_own_issuer_what_an_sdk_di_2adf2886',
                                )}
                                <code className="mono">{tc('console.iss_f029e50f')}</code>
                                {tc('console.claim_carries_910485ac')}
                            </>
                        }
                    />
                    <CopyableValue
                        label={tc('console.client_id_8726db01')}
                        value={client.clientId}
                    />

                    <div>
                        <p className="label">{tc('console.client_secret_4aded5fa')}</p>
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {!client.confidential
                                ? tc(
                                      'console.none_this_is_a_public_app_and_uses_pkce_inst_b028b084',
                                  )
                                : client.signsAssertions
                                  ? tc(
                                        'console.none_this_app_signs_in_with_its_own_keys_ins_3e407298',
                                    )
                                  : tc('console.stored_as_a_hash_and_shown_only_once_4a2d11fd')}
                        </p>
                        {urls.secrets !== null && (
                            <Link
                                href={urls.secrets}
                                className="mt-1 inline-block text-sm underline"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                {tc('console.rotate_or_revoke_secrets_56c04853')}
                            </Link>
                        )}
                    </div>
                </div>
            </Panel>

            {!mayManage && (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.this_app_belongs_to_the_platform_and_is_avai_0c69cd5f')}
                    </p>
                </Panel>
            )}

            {mayManage && (
                <Panel title={tc('console.details_45989de4')}>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <Field label={tc('console.name_dcd1d522')} error={details.errors.name}>
                            <Input
                                name="name"
                                value={details.data.name}
                                onChange={(event) => details.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={
                                <>
                                    {tc('console.redirect_uris_721353f1')}{' '}
                                    <span style={{ color: 'var(--faint)', fontWeight: 400 }}>
                                        {tc('console.one_per_line_256f7ece')}
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
                                    {tc('console.sign_out_uris_8a668dbe')}{' '}
                                    <span style={{ color: 'var(--faint)', fontWeight: 400 }}>
                                        {tc('console.one_per_line_256f7ece')}
                                    </span>
                                </>
                            }
                            hint={tc(
                                'console.where_cbox_id_sends_people_after_they_sign_o_3a65532f',
                            )}
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
                                {tc('console.save_changes_dd0ae7a5')}
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

            <Panel title={tc('console.connects_via_45b211b3')}>
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {client.kindLabel}
                </p>
                <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                    {tc('console.how_an_app_connects_is_fixed_at_registration_0372baec')}
                </p>
            </Panel>

            {mayManage && (
                <Panel
                    title={tc('console.roles_amp_permissions_22a82b7c')}
                    description={tc(
                        'console.the_app_declares_these_cbox_id_pulls_them_fr_f63d9426',
                    )}
                    action={
                        <Button
                            size="sm"
                            className="shrink-0"
                            aria-expanded={manifestOpen}
                            onClick={() => setManifestOpen((open) => !open)}
                        >
                            {tc('console.manifest_3c99f1f3')}
                            {declaredRoles > 0 ? ` · ${declaredRoles}` : ''}
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
                                <Field
                                    label={tc('console.manifest_url_b2e2812a')}
                                    error={manifest.errors.manifestUrl}
                                >
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
                                {tc('console.save_amp_sync_9ebfe6df')}
                            </Button>

                            {client.manifestUrl !== '' && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() =>
                                        router.post(urls.sync, {}, { preserveScroll: true })
                                    }
                                >
                                    {tc('console.sync_now_885e8f48')}
                                </Button>
                            )}
                        </form>
                    )}

                    <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {declaredRoles > 0
                            ? tc('console.value0_role_s_declared_055c2372', {
                                  value0: declaredRoles,
                              })
                            : tc('console.no_roles_declared_yet_set_a_manifest_url_and_799c34aa')}
                    </p>
                </Panel>
            )}

            {mayManage && (
                <Panel
                    title={tc('console.delete_app_70ddc821')}
                    description={tc(
                        'console.anything_using_its_credentials_will_stop_wor_da65f419',
                    )}
                >
                    <Button size="sm" variant="danger" onClick={() => setConfirming('delete')}>
                        {tc('console.delete_app_70ddc821')}
                    </Button>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={client.name}
                consequence={tc('console.anything_using_this_app_s_credentials_will_s_1ea63206')}
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </AppFrame>
    );
}

ClientDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
