import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    CodeBlock,
    ConfirmDelete,
    CopyButton,
    Kv,
    KvList,
    Panel,
    Pill,
    type PillTone,
} from '@/ui';
import { HEALTH, type StreamHealth } from './fields';

type Props = PageProps<{
    stream: {
        id: string;
        name: string;
        destination: string;
        destinationValue: string;
        endpointUrl: string;
        /** An HTTP collector's auth scheme; null for the cloud destinations. */
        scheme: string | null;
        options: { label: string; value: string }[];
        enabled: boolean;
        health: StreamHealth;
        lastSuccessAt: string | null;
        lastError: string | null;
        lastFailureKind: string | null;
        lastFailureAt: string | null;
    };
    /** An S3 stream's AWS setup: the policies to paste, and an assumed role's external ID. */
    aws: {
        externalId: string | null;
        trustPolicy: string | null;
        permissionsPolicy: string | null;
        principalConfigured: boolean;
    } | null;
    indexHref: string;
    urls: { edit: string; test: string; toggle: string; destroy: string };
}>;

const FAILURE = (tc: Translator['t']): Record<string, string> => ({
    transient: tc('console.the_destination_was_unreachable_or_busy_deli_cc9141ca'),
    authentication: tc('console.the_destination_refused_the_credential_nothi_c276c6ea'),
    configuration: tc('console.the_destination_refused_the_settings_a_missi_27207d67'),
});

export default function LogStreamDetail({ stream, aws, indexHref, urls }: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    // The signing key, on the flash channel and nowhere else: only ciphertext is
    // persisted, so this is the only time it exists, and props are written into the
    // browser's history entry.
    const { newSecret, streamTest, awsSetup } = usePage().flash;

    const [confirming, setConfirming] = useState(false);
    const [secretDismissed, setSecretDismissed] = useState(false);
    const [testing, setTesting] = useState(false);
    const health = stream.enabled
        ? HEALTH(tc)[stream.health]
        : { label: tc('console.disabled_75081b59'), tone: 'warning' as PillTone };

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={tc('console.log_streams_aff03056')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{stream.name}</h1>
                    <Badge>{stream.destination}</Badge>
                    <Pill tone={health.tone}>{health.label}</Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {stream.id}
                </p>
            </div>

            {newSecret !== undefined && !secretDismissed && (
                <RevealedKey secret={newSecret} onDismiss={() => setSecretDismissed(true)} />
            )}

            {aws?.externalId != null && aws.trustPolicy !== null && (
                <AwsRoleSetup
                    externalId={aws.externalId}
                    trustPolicy={aws.trustPolicy}
                    principalConfigured={aws.principalConfigured}
                    justCreated={awsSetup === stream.id}
                />
            )}

            {stream.lastError !== null && stream.health !== 'healthy' && (
                <div
                    className="rounded-xl border p-5"
                    style={{
                        borderColor: 'color-mix(in oklch, var(--destructive) 35%, transparent)',
                        background: 'var(--surface-2)',
                    }}
                >
                    <p className="text-sm font-semibold">
                        {tc('console.last_delivery_failed_e3c01f8f')}
                    </p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {FAILURE(tc)[stream.lastFailureKind ?? 'transient'] ??
                            FAILURE(tc).transient}
                    </p>
                    <p className="mt-3 mono text-xs break-all">{stream.lastError}</p>
                    {stream.lastFailureAt !== null && (
                        <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                            {new Date(stream.lastFailureAt).toLocaleString(locale)}
                        </p>
                    )}
                </div>
            )}

            <Panel
                title={tc('console.delivery_52bfe584')}
                action={
                    <Button size="sm" asChild>
                        <Link href={urls.edit}>{tc('console.edit_464c4ffd')}</Link>
                    </Button>
                }
            >
                <div className="space-y-4">
                    <div>
                        <p className="label">{tc('console.endpoint_url_2578179d')}</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 truncate mono text-sm">
                                {stream.endpointUrl}
                            </code>
                            <CopyButton value={stream.endpointUrl} />
                        </div>
                    </div>

                    <KvList>
                        <Kv label={tc('console.destination_293d404a')} prose>
                            <Badge>{stream.destination}</Badge>
                        </Kv>
                        {stream.scheme !== null && (
                            <Kv label={tc('console.auth_scheme_4a894695')} prose>
                                <Badge>{stream.scheme}</Badge>
                            </Kv>
                        )}
                        {stream.options.map((row) => (
                            <Kv key={row.label} label={row.label}>
                                {row.value}
                            </Kv>
                        ))}
                        <Kv label={tc('console.last_delivery_c9f13a73')} prose>
                            {stream.lastSuccessAt === null
                                ? tc('console.nothing_delivered_yet_c97f9916')
                                : new Date(stream.lastSuccessAt).toLocaleString(locale)}
                        </Kv>
                    </KvList>
                </div>
            </Panel>

            <Panel
                title={tc('console.send_test_event_bcd75861')}
                description={tc('console.one_marked_event_siem_stream_test_sent_now_w_c0cf0c4a')}
            >
                <div className="space-y-3">
                    <Button
                        size="sm"
                        loading={testing}
                        onClick={() =>
                            router.post(
                                urls.test,
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setTesting(true),
                                    onFinish: () => setTesting(false),
                                },
                            )
                        }
                    >
                        {tc('console.send_test_event_bcd75861')}
                    </Button>
                    {streamTest !== undefined && streamTest.id === stream.id && (
                        <output
                            className="block text-sm"
                            style={{
                                color: streamTest.delivered
                                    ? 'var(--success-strong, var(--success))'
                                    : 'var(--destructive)',
                            }}
                        >
                            {streamTest.delivered
                                ? tc(
                                      'console.delivered_the_destination_accepted_the_test__a2e48597',
                                  )
                                : tc('console.not_delivered_value0_e4ed9c8e', {
                                      value0:
                                          streamTest.error ??
                                          tc('console.the_destination_refused_it_f6cbd990'),
                                  })}
                        </output>
                    )}
                </div>
            </Panel>

            {aws?.permissionsPolicy != null && (
                <Panel
                    title={tc('console.aws_permissions_570c4df3')}
                    description={
                        aws.externalId !== null
                            ? tc('console.attach_this_to_the_role_it_may_write_objects_3d2c7384')
                            : tc('console.attach_this_to_the_iam_user_whose_access_key_7659ba28')
                    }
                >
                    <CodeBlock
                        code={aws.permissionsPolicy}
                        copyLabel={tc('console.copy_permissions_policy_7cf8d120')}
                    />
                </Panel>
            )}

            <Panel
                title={
                    stream.enabled
                        ? tc('console.disable_stream_217fe695')
                        : tc('console.resume_stream_e469f3ae')
                }
                description={
                    stream.enabled
                        ? tc('console.entries_stop_being_delivered_and_are_kept_no_7fab8213')
                        : tc('console.delivery_resumes_entries_written_while_it_wa_c6e7d5a4')
                }
            >
                <Button
                    size="sm"
                    onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                >
                    {stream.enabled
                        ? tc('console.disable_b7e3e4aa')
                        : tc('console.resume_d640c742')}
                </Button>
            </Panel>

            <Panel
                title={tc('console.delete_stream_6fcb17d1')}
                description={tc('console.delivery_stops_immediately_and_the_stored_cr_735dadec')}
            >
                <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                    {tc('console.delete_stream_6fcb17d1')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={stream.name}
                consequence={tc('console.delivery_to_this_destination_stops_immediate_7e4c8900')}
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * An assumed-role bucket's one remaining step: the role must trust this platform AND
 * require this stream's external ID, or nothing is ever written. Brought into view right
 * after the stream is created, like a revealed key — it is the next thing to do.
 */
function AwsRoleSetup({
    externalId,
    trustPolicy,
    principalConfigured,
    justCreated,
}: {
    externalId: string;
    trustPolicy: string;
    principalConfigured: boolean;
    justCreated: boolean;
}) {
    const { t: tc } = useTranslator();

    const card = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (justCreated) {
            card.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            card.current?.focus({ preventScroll: true });
        }
    }, [justCreated]);

    return (
        <div ref={card} tabIndex={-1}>
            <Panel
                title={
                    justCreated
                        ? tc('console.finish_in_aws_trust_this_platform_51f4ad0d')
                        : tc('console.aws_role_trust_bd6e087e')
                }
                description={tc('console.set_this_as_the_role_s_trust_policy_iam_role_d432cbe5')}
            >
                <div className="space-y-4">
                    <div>
                        <p className="label">{tc('console.external_id_69da56ba')}</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 break-all mono text-sm">
                                {externalId}
                            </code>
                            <CopyButton
                                value={externalId}
                                label={tc('console.copy_e21f935f')}
                                aria-label={tc('console.copy_external_id_56e7149f')}
                            />
                        </div>
                    </div>
                    <CodeBlock
                        code={trustPolicy}
                        copyLabel={tc('console.copy_trust_policy_5b107449')}
                        caption={
                            principalConfigured
                                ? undefined
                                : tc(
                                      'console.replace_the_principal_with_this_platform_s_a_a5e64445',
                                  )
                        }
                    />
                </div>
            </Panel>
        </div>
    );
}

/**
 * The signing key, shown exactly once.
 *
 * IT BRINGS ITSELF INTO VIEW and takes focus with it: the stream is created on another
 * page and lands here, so without this the credential somebody must copy right now is
 * simply somewhere on a screen they have not read yet.
 */
function RevealedKey({ secret, onDismiss }: { secret: string; onDismiss: () => void }) {
    const { t: tc } = useTranslator();

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
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-sm font-semibold" style={{ color: 'var(--warning-strong)' }}>
                        {tc('console.copy_this_signing_key_now_it_won_t_be_shown__ec511d44')}
                    </p>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.only_the_encrypted_form_is_stored_so_it_cann_8466f568')}
                    </p>
                </div>
                <Button size="sm" className="shrink-0" onClick={onDismiss}>
                    {tc('console.dismiss_48845bff')}
                </Button>
            </div>

            <div className="mt-4 flex items-start gap-2">
                <code className="mono text-sm break-all select-all flex-1">{secret}</code>
                <CopyButton
                    value={secret}
                    variant="primary"
                    label={tc('console.copy_key_e47c8bae')}
                />
            </div>
        </div>
    );
}

LogStreamDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
