import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    ConfirmDelete,
    Field,
    Input,
    Kv,
    KvList,
    Panel,
    Pill,
    Textarea,
} from '@/ui';
import { statusTone } from './index';

interface Grant {
    clientId: string;
    revokeHref: string;
}

type Props = PageProps<{
    secret: {
        id: string;
        name: string;
        provider: string;
        scope: string;
        status: 'active' | 'expired' | 'revoked';
        revoked: boolean;
        rotatedAt: string | null;
        expiresAt: string | null;
    };
    grants: Grant[];
    indexHref: string;
    urls: { rotate: string; grant: string; revoke: string };
}>;

export default function VaultSecretDetail({ secret, grants, indexHref, urls }: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [revoking, setRevoking] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.token_vault_1d74ef9e')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{secret.name}</h1>
                    <Badge className="mono">{secret.provider}</Badge>
                    <Pill tone={statusTone(secret.status)}>
                        {secret.status === 'revoked'
                            ? consoleText('console.revoked_f6f738d0')
                            : secret.status === 'expired'
                              ? consoleText('console.expired_424a2551')
                              : consoleText('console.active_92340695')}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {secret.id}
                </p>
            </div>

            {/* The sealed value is never here — only its shape. */}
            <Panel title={consoleT('console.details_45989de4')}>
                <KvList>
                    <Kv label={consoleT('console.provider_472590ae')}>{secret.provider}</Kv>
                    <Kv label={consoleT('console.scope_b073f6c6')} prose>
                        {secret.scope}
                    </Kv>
                    <Kv label={consoleT('console.rotated_24b10841')} prose>
                        {secret.rotatedAt ?? consoleT('console.never_6300ef80')}
                    </Kv>
                    <Kv label={consoleT('console.expires_f6725f3a')} prose>
                        {secret.expiresAt ?? consoleT('console.never_6300ef80')}
                    </Kv>
                </KvList>
            </Panel>

            <Rotate revoked={secret.revoked} name={secret.name} href={urls.rotate} />

            <Grants revoked={secret.revoked} grants={grants} href={urls.grant} />

            <Panel
                title={consoleT('console.revoke_secret_2d7f529d')}
                description={
                    secret.revoked
                        ? consoleText(
                              'console.this_secret_is_revoked_no_future_lease_can_open_it_1a5a07c0',
                          )
                        : consoleText(
                              'console.revoking_is_immediate_and_permanent_no_future_lease_can_27e17e18',
                          )
                }
            >
                {!secret.revoked && (
                    <Button size="sm" variant="danger" onClick={() => setRevoking(true)}>
                        {consoleT('console.revoke_secret_2d7f529d')}
                    </Button>
                )}
            </Panel>

            <ConfirmDelete
                open={revoking}
                onOpenChange={setRevoking}
                name={secret.name}
                verb={consoleShortText('console.revoke_87e6d00b')}
                consequence={consoleUiText(
                    'console.no_future_lease_can_open_this_secret_revocation_is_imme_5b330536',
                )}
                onConfirm={() => {
                    setRevoking(false);
                    router.post(urls.revoke, {}, { preserveScroll: true });
                }}
            />
        </div>
    );
}

/**
 * Replace the sealed value.
 *
 * THE FIELD IS REVEALED, not always on screen. A password box sitting open on a page
 * somebody opened to read a rotation date is an invitation to paste a credential into a
 * form nobody meant to submit — and the warning beside it only means something once
 * somebody has decided to rotate.
 */
function Rotate({ revoked, name, href }: { revoked: boolean; name: string; href: string }) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [open, setOpen] = useState(false);
    const form = useForm({ secret: '' });

    return (
        <Panel
            title={consoleT('console.rotate_c3613b17')}
            description={
                revoked
                    ? consoleText(
                          'console.this_secret_is_revoked_it_can_no_longer_be_rotated_cba2e557',
                      )
                    : consoleText(
                          'console.replace_the_sealed_value_with_a_new_credential_the_stor_17b46d38',
                      )
            }
            action={
                !revoked && !open ? (
                    <Button size="sm" className="shrink-0" onClick={() => setOpen(true)}>
                        {consoleT('console.rotate_c3613b17')}
                    </Button>
                ) : undefined
            }
        >
            {!revoked && open && (
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(href, {
                            preserveScroll: true,
                            // Cleared whatever happens: the value is never echoed back, so
                            // leaving it in the field would be the one copy still in the clear.
                            onFinish: () => {
                                form.setData('secret', '');
                                setOpen(false);
                            },
                        });
                    }}
                >
                    <Field
                        label={consoleFinalText('console.new_value_for_part1_c99769be', {
                            part1: name,
                        })}
                        error={form.errors.secret}
                    >
                        <Textarea
                            name="secret"
                            rows={3}
                            className="mono"
                            spellCheck={false}
                            autoComplete="off"
                            placeholder={consoleUiText('console.sk_live_2897b00f')}
                            value={form.data.secret}
                            onChange={(event) => form.setData('secret', event.target.value)}
                        />
                    </Field>

                    <div
                        className="rounded-xl border p-4"
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
                                'console.it_replaces_the_sealed_value_on_rotate_and_is_never_sho_cec701f1',
                            )}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                            {consoleT('console.rotate_c3613b17')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                form.setData('secret', '');
                                setOpen(false);
                            }}
                        >
                            {consoleT('console.cancel_19766ed6')}
                        </Button>
                    </div>
                </form>
            )}
        </Panel>
    );
}

/**
 * Who may lease this secret.
 *
 * DENY BY DEFAULT: nothing can open it until a client is named here, so this list is the
 * access-control decision rather than a setting. Revoked grants are not shown — a revoked
 * grant authorizes nothing, and listing it beside the live ones invites the list to be
 * read as "who has access".
 */
function Grants({ revoked, grants, href }: { revoked: boolean; grants: Grant[]; href: string }) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [revokingClient, setRevokingClient] = useState<Grant | null>(null);
    const form = useForm({ client: '' });

    return (
        <Panel
            title={consoleT('console.client_grants_e42a14c7')}
            description={consoleT(
                'console.only_the_apps_listed_here_can_lease_this_secret_everyth_3afb49f2',
            )}
        >
            <div className="space-y-2">
                {grants.length === 0 ? (
                    <p
                        className="rounded-xl border p-4 text-sm"
                        style={{ borderColor: 'var(--border)', color: 'var(--muted-foreground)' }}
                    >
                        {consoleT(
                            'console.no_clients_are_authorized_to_lease_this_secret_58c00826',
                        )}
                    </p>
                ) : (
                    grants.map((grant) => (
                        <div
                            key={grant.clientId}
                            className="flex items-center justify-between gap-2 rounded-lg px-3 py-2"
                            style={{ background: 'var(--surface-2)' }}
                        >
                            <span className="mono text-xs break-all">{grant.clientId}</span>
                            <Button
                                size="sm"
                                variant="danger"
                                className="shrink-0"
                                onClick={() => setRevokingClient(grant)}
                            >
                                {consoleT('console.revoke_87e6d00b')}
                            </Button>
                        </div>
                    ))
                )}
            </div>

            {!revoked && (
                <form
                    className="mt-4 flex items-end gap-2 flex-wrap"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(href, {
                            preserveScroll: true,
                            onSuccess: () => form.reset('client'),
                        });
                    }}
                >
                    <Field
                        label={consoleT('console.authorize_a_client_24a03bcb')}
                        className="flex-1"
                        error={form.errors.client}
                    >
                        <Input
                            name="client"
                            className="mono"
                            placeholder={consoleUiText('console.agent_client_1_1b358275')}
                            value={form.data.client}
                            onChange={(event) => form.setData('client', event.target.value)}
                        />
                    </Field>
                    <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                        {consoleT('console.add_grant_f8cf2049')}
                    </Button>
                </form>
            )}

            <ConfirmDelete
                open={revokingClient !== null}
                onOpenChange={(open) => !open && setRevokingClient(null)}
                name={revokingClient?.clientId ?? ''}
                verb={consoleShortText('console.revoke_87e6d00b')}
                consequence={consoleUiText(
                    'console.this_client_can_no_longer_lease_the_secret_any_lease_it_c5728383',
                )}
                onConfirm={() => {
                    const grant = revokingClient;
                    setRevokingClient(null);

                    if (grant !== null) {
                        router.delete(grant.revokeHref, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

VaultSecretDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
