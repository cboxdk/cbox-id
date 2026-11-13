import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { relativeTime } from '@/lib/time';
import { Button, Field, Input, Kv, KvList, Panel, Pill, SnippetTabs } from '@/ui';
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
    summary: {
        host: string | null;
        browserSignIn: boolean;
        redirectCount: number;
        postLogoutCount: number;
        backchannelLogout: boolean;
        scopeCount: number;
        lastUsedAt: string | null;
        createdAt: string | null;
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
        /** The Sign-in & logout tab; null for somebody who may only look at the app. */
        signIn: string | null;
        settings: string | null;
    };
}>;

export default function ClientDetail({
    appHeader,
    client,
    summary,
    issuer,
    snippets,
    declaredRoles,
    mayManage,
    urls,
}: Props) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [sdk, setSdk] = useState(snippets[0]?.id ?? '');
    const [manifestOpen, setManifestOpen] = useState(false);

    const manifest = useForm({ manifestUrl: client.manifestUrl });

    return (
        <AppFrame app={appHeader} issuer={issuer}>
            <AtAGlance client={client} summary={summary} urls={urls} />

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
        </AppFrame>
    );
}

/**
 * WHAT THE APP IS, before how to connect it: its kind, where it lives, whether sign-out is
 * wired, when it last asked for a token — each with the tab where it is changed.
 */
function AtAGlance({
    client,
    summary,
    urls,
}: {
    client: Props['client'];
    summary: Props['summary'];
    urls: Props['urls'];
}) {
    const { t, locale } = useTranslator();
    const edit = (href: string | null) =>
        href === null ? null : (
            <Link
                href={href}
                className="text-sm underline"
                style={{ color: 'var(--accent-strong)' }}
            >
                {t('console.app_glance_change')}
            </Link>
        );

    return (
        <Panel title={t('console.app_glance_title')}>
            <KvList>
                <Kv prose label={t('console.app_glance_kind')}>
                    {client.kindLabel}
                    <span className="block text-xs" style={{ color: 'var(--faint)' }}>
                        {t(
                            'console.how_an_app_connects_is_fixed_at_registration_changing_i_0372baec',
                        )}
                    </span>
                </Kv>
                {summary.browserSignIn && (
                    <Kv prose label={t('console.app_glance_sign_in')}>
                        <span className="flex flex-wrap items-center gap-2">
                            {summary.host !== null && <span className="mono">{summary.host}</span>}
                            {summary.redirectCount === 0 ? (
                                <Pill tone="warning">{t('console.apps_no_redirect')}</Pill>
                            ) : (
                                <span style={{ color: 'var(--muted-foreground)' }}>
                                    {t('console.app_glance_redirects', {
                                        count: summary.redirectCount,
                                    })}
                                </span>
                            )}
                            {edit(urls.signIn)}
                        </span>
                    </Kv>
                )}
                <Kv prose label={t('console.app_glance_logout')}>
                    <span className="flex flex-wrap items-center gap-2">
                        {summary.backchannelLogout ? (
                            <Pill tone="success">{t('console.app_glance_backchannel_on')}</Pill>
                        ) : (
                            <Pill>{t('console.app_glance_backchannel_off')}</Pill>
                        )}
                        {summary.postLogoutCount > 0 && (
                            <span style={{ color: 'var(--muted-foreground)' }}>
                                {t('console.app_glance_post_logout', {
                                    count: summary.postLogoutCount,
                                })}
                            </span>
                        )}
                        {edit(urls.signIn)}
                    </span>
                </Kv>
                <Kv prose label={t('console.app_glance_last_used')}>
                    {summary.lastUsedAt === null
                        ? t('console.apps_never_used')
                        : relativeTime(summary.lastUsedAt, locale)}
                </Kv>
            </KvList>
        </Panel>
    );
}

ClientDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
