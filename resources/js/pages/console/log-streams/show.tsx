import type { Translator } from '@/i18n';
import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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

const FAILURE = (t: Translator['t']): Record<string, string> => ({
    transient: t('console.the_destination_was_unreachable_or_busy_delivery_is_ret_cc9141ca'),
    authentication: t('console.the_destination_refused_the_credential_nothing_is_deliv_c276c6ea'),
    configuration: t('console.the_destination_refused_the_settings_a_missing_bucket_a_27207d67'),
});

export default function LogStreamDetail({ stream, aws, indexHref, urls }: Props) {
    const { t: consoleNestedText } = useConsoleTranslator();
    const { t: consoleStaticText } = useConsoleTranslator();
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // The signing key, on the flash channel and nowhere else: only ciphertext is
    // persisted, so this is the only time it exists, and props are written into the
    // browser's history entry.
    const { newSecret, streamTest, awsSetup } = usePage().flash;

    const [confirming, setConfirming] = useState(false);
    const [secretDismissed, setSecretDismissed] = useState(false);
    const [testing, setTesting] = useState(false);
    const health = stream.enabled
        ? HEALTH(consoleOptionText)[stream.health]
        : { label: consoleText('console.disabled_75081b59'), tone: 'warning' as PillTone };

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.log_streams_aff03056')} />
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
                        {consoleT('console.last_delivery_failed_e3c01f8f')}
                    </p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {FAILURE(consoleStaticText)[stream.lastFailureKind ?? 'transient'] ??
                            FAILURE(consoleStaticText).transient}
                    </p>
                    <p className="mt-3 mono text-xs break-all">{stream.lastError}</p>
                    {stream.lastFailureAt !== null && (
                        <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                            {new Date(stream.lastFailureAt).toLocaleString(consoleLocale)}
                        </p>
                    )}
                </div>
            )}

            <Panel
                title={consoleT('console.delivery_52bfe584')}
                action={
                    <Button size="sm" asChild>
                        <Link href={urls.edit}>{consoleT('console.edit_464c4ffd')}</Link>
                    </Button>
                }
            >
                <div className="space-y-4">
                    <div>
                        <p className="label">{consoleT('console.endpoint_url_2578179d')}</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 truncate mono text-sm">
                                {stream.endpointUrl}
                            </code>
                            <CopyButton value={stream.endpointUrl} />
                        </div>
                    </div>

                    <KvList>
                        <Kv label={consoleT('console.destination_293d404a')} prose>
                            <Badge>{stream.destination}</Badge>
                        </Kv>
                        {stream.scheme !== null && (
                            <Kv label={consoleT('console.auth_scheme_4a894695')} prose>
                                <Badge>{stream.scheme}</Badge>
                            </Kv>
                        )}
                        {stream.options.map((row) => (
                            <Kv key={row.label} label={row.label}>
                                {row.value}
                            </Kv>
                        ))}
                        <Kv label={consoleT('console.last_delivery_c9f13a73')} prose>
                            {stream.lastSuccessAt === null
                                ? consoleText('console.nothing_delivered_yet_c97f9916')
                                : new Date(stream.lastSuccessAt).toLocaleString(consoleLocale)}
                        </Kv>
                    </KvList>
                </div>
            </Panel>

            <Panel
                title={consoleT('console.send_test_event_bcd75861')}
                description={consoleT(
                    'console.one_marked_event_siem_stream_test_sent_now_with_this_st_c0cf0c4a',
                )}
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
                        {consoleT('console.send_test_event_bcd75861')}
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
                                ? consoleText(
                                      'console.delivered_the_destination_accepted_the_test_event_a2e48597',
                                  )
                                : consoleFinalText('console.not_delivered_part1_73616e53', {
                                      part1:
                                          streamTest.error ??
                                          consoleNestedText(
                                              'console.the_destination_refused_it_f6cbd990',
                                          ),
                                  })}
                        </output>
                    )}
                </div>
            </Panel>

            {aws?.permissionsPolicy != null && (
                <Panel
                    title={consoleT('console.aws_permissions_570c4df3')}
                    description={
                        aws.externalId !== null
                            ? consoleText(
                                  'console.attach_this_to_the_role_it_may_write_objects_under_the_3d2c7384',
                              )
                            : consoleText(
                                  'console.attach_this_to_the_iam_user_whose_access_key_the_stream_7659ba28',
                              )
                    }
                >
                    <CodeBlock
                        code={aws.permissionsPolicy}
                        copyLabel={consoleUiText('console.copy_permissions_policy_7cf8d120')}
                    />
                </Panel>
            )}

            <Panel
                title={
                    stream.enabled
                        ? consoleText('console.disable_stream_217fe695')
                        : consoleText('console.resume_stream_e469f3ae')
                }
                description={
                    stream.enabled
                        ? consoleText(
                              'console.entries_stop_being_delivered_and_are_kept_nothing_is_dr_7fab8213',
                          )
                        : consoleText(
                              'console.delivery_resumes_entries_written_while_it_was_off_are_s_c6e7d5a4',
                          )
                }
            >
                <Button
                    size="sm"
                    onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                >
                    {stream.enabled
                        ? consoleText('console.disable_b7e3e4aa')
                        : consoleText('console.resume_d640c742')}
                </Button>
            </Panel>

            <Panel
                title={consoleT('console.delete_stream_6fcb17d1')}
                description={consoleT(
                    'console.delivery_stops_immediately_and_the_stored_credential_is_735dadec',
                )}
            >
                <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                    {consoleT('console.delete_stream_6fcb17d1')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={stream.name}
                consequence={consoleUiText(
                    'console.delivery_to_this_destination_stops_immediately_and_the_7e4c8900',
                )}
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
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                        ? consoleText('console.finish_in_aws_trust_this_platform_51f4ad0d')
                        : consoleText('console.aws_role_trust_bd6e087e')
                }
                description={consoleT(
                    'console.set_this_as_the_role_s_trust_policy_iam_roles_trust_rel_d432cbe5',
                )}
            >
                <div className="space-y-4">
                    <div>
                        <p className="label">{consoleT('console.external_id_69da56ba')}</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 break-all mono text-sm">
                                {externalId}
                            </code>
                            <CopyButton
                                value={externalId}
                                label={consoleT('console.copy_e21f935f')}
                                aria-label={consoleT('console.copy_external_id_56e7149f')}
                            />
                        </div>
                    </div>
                    <CodeBlock
                        code={trustPolicy}
                        copyLabel={consoleUiText('console.copy_trust_policy_5b107449')}
                        caption={
                            principalConfigured
                                ? undefined
                                : consoleAttributeText(
                                      'console.replace_the_principal_with_this_platform_s_aws_user_arn_a5e64445',
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
    const { t: consoleT } = useConsoleTranslator();
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
                        {consoleT(
                            'console.copy_this_signing_key_now_it_won_t_be_shown_again_ec511d44',
                        )}
                    </p>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.only_the_encrypted_form_is_stored_so_it_cannot_be_retri_8466f568',
                        )}
                    </p>
                </div>
                <Button size="sm" className="shrink-0" onClick={onDismiss}>
                    {consoleT('console.dismiss_48845bff')}
                </Button>
            </div>

            <div className="mt-4 flex items-start gap-2">
                <code className="mono text-sm break-all select-all flex-1">{secret}</code>
                <CopyButton
                    value={secret}
                    variant="primary"
                    label={consoleT('console.copy_key_e47c8bae')}
                />
            </div>
        </div>
    );
}

LogStreamDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
