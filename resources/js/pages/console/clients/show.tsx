import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
            <Panel title={consoleT('console.connect_it_00ff2248')} description={client.kindLabel}>
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
                    label={consoleT('console.sdk_examples_fa304ed5')}
                />
            </Panel>

            <Panel title={consoleT('console.credentials_d48a617c')}>
                <div className="space-y-3">
                    <CopyableValue
                        label={consoleT('console.issuer_39e02c46')}
                        value={issuer}
                        hint={
                            <>
                                {consoleRich(
                                    'console.this_environment_s_own_issuer_what_an_sdk_discovers_fro_b00e706b',
                                    { part1: <code className="mono">{'iss'}</code> },
                                )}
                            </>
                        }
                    />
                    <CopyableValue
                        label={consoleT('console.client_id_8726db01')}
                        value={client.clientId}
                    />

                    <div>
                        <p className="label">{consoleT('console.client_secret_4aded5fa')}</p>
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {!client.confidential
                                ? consoleText(
                                      'console.none_this_is_a_public_app_and_uses_pkce_instead_of_a_se_b028b084',
                                  )
                                : client.signsAssertions
                                  ? consoleText(
                                        'console.none_this_app_signs_in_with_its_own_keys_instead_of_a_s_3e407298',
                                    )
                                  : consoleText(
                                        'console.stored_as_a_hash_and_shown_only_once_4a2d11fd',
                                    )}
                        </p>
                        {urls.secrets !== null && (
                            <Link
                                href={urls.secrets}
                                className="mt-1 inline-block text-sm underline"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                {consoleT('console.rotate_or_revoke_secrets_56c04853')}
                            </Link>
                        )}
                    </div>
                </div>
            </Panel>

            {!mayManage && (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.this_app_belongs_to_the_platform_and_is_available_to_ev_0c69cd5f',
                        )}
                    </p>
                </Panel>
            )}

            {mayManage && (
                <Panel title={consoleT('console.details_45989de4')}>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <Field
                            label={consoleT('console.name_dcd1d522')}
                            error={details.errors.name}
                        >
                            <Input
                                name="name"
                                value={details.data.name}
                                onChange={(event) => details.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={
                                <>
                                    {consoleRich('console.redirect_uris_part1_part2_428a2fa5', {
                                        part1: ' ',
                                        part2: (
                                            <span
                                                style={{ color: 'var(--faint)', fontWeight: 400 }}
                                            >
                                                {consoleT('console._one_per_line_256f7ece')}
                                            </span>
                                        ),
                                    })}
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
                                    {consoleRich('console.sign_out_uris_part1_part2_e713791a', {
                                        part1: ' ',
                                        part2: (
                                            <span
                                                style={{ color: 'var(--faint)', fontWeight: 400 }}
                                            >
                                                {consoleT('console._one_per_line_256f7ece')}
                                            </span>
                                        ),
                                    })}
                                </>
                            }
                            hint={consoleT(
                                'console.where_cbox_id_sends_people_after_they_sign_out_of_this_3a65532f',
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
                                {consoleT('console.save_changes_dd0ae7a5')}
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

            <Panel title={consoleT('console.connects_via_45b211b3')}>
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {client.kindLabel}
                </p>
                <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                    {consoleT(
                        'console.how_an_app_connects_is_fixed_at_registration_changing_i_0372baec',
                    )}
                </p>
            </Panel>

            {mayManage && (
                <Panel
                    title={consoleT('console.roles_permissions_9d598ceb')}
                    description={consoleT(
                        'console.the_app_declares_these_cbox_id_pulls_them_from_its_mani_f63d9426',
                    )}
                    action={
                        <Button
                            size="sm"
                            className="shrink-0"
                            aria-expanded={manifestOpen}
                            onClick={() => setManifestOpen((open) => !open)}
                        >
                            {consoleRich('console.manifest_part1_96ca0e02', {
                                part1: declaredRoles > 0 ? ` · ${declaredRoles}` : '',
                            })}
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
                                    label={consoleT('console.manifest_url_b2e2812a')}
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
                                {consoleT('console.save_sync_1f4f3c67')}
                            </Button>

                            {client.manifestUrl !== '' && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() =>
                                        router.post(urls.sync, {}, { preserveScroll: true })
                                    }
                                >
                                    {consoleT('console.sync_now_885e8f48')}
                                </Button>
                            )}
                        </form>
                    )}

                    <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {declaredRoles > 0
                            ? consoleFinalText('console._part1_role_s_declared_e0865436', {
                                  part1: declaredRoles,
                              })
                            : consoleText(
                                  'console.no_roles_declared_yet_set_a_manifest_url_and_sync_or_ha_799c34aa',
                              )}
                    </p>
                </Panel>
            )}

            {mayManage && (
                <Panel
                    title={consoleT('console.delete_app_70ddc821')}
                    description={consoleT(
                        'console.anything_using_its_credentials_will_stop_working_this_c_da65f419',
                    )}
                >
                    <Button size="sm" variant="danger" onClick={() => setConfirming('delete')}>
                        {consoleT('console.delete_app_70ddc821')}
                    </Button>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={client.name}
                consequence={consoleUiText(
                    'console.anything_using_this_app_s_credentials_will_stop_working_1ea63206',
                )}
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </AppFrame>
    );
}

ClientDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
