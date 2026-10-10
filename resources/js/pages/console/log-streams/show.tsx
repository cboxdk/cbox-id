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

const FAILURE: Record<string, string> = {
    transient: 'The destination was unreachable or busy. Delivery is retried on its own.',
    authentication:
        'The destination refused the credential. Nothing is delivered until it is replaced — entries wait, they are not dropped.',
    configuration:
        'The destination refused the settings (a missing bucket, a wrong region or site). Nothing is delivered until they are fixed — entries wait, they are not dropped.',
};

export default function LogStreamDetail({ stream, aws, indexHref, urls }: Props) {
    // The signing key, on the flash channel and nowhere else: only ciphertext is
    // persisted, so this is the only time it exists, and props are written into the
    // browser's history entry.
    const { newSecret, streamTest, awsSetup } = usePage().flash;

    const [confirming, setConfirming] = useState(false);
    const [secretDismissed, setSecretDismissed] = useState(false);
    const [testing, setTesting] = useState(false);
    const health = stream.enabled
        ? HEALTH[stream.health]
        : { label: 'Disabled', tone: 'warning' as PillTone };

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label="Log streams" />
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
                    <p className="text-sm font-semibold">Last delivery failed</p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {FAILURE[stream.lastFailureKind ?? 'transient'] ?? FAILURE.transient}
                    </p>
                    <p className="mt-3 mono text-xs break-all">{stream.lastError}</p>
                    {stream.lastFailureAt !== null && (
                        <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                            {new Date(stream.lastFailureAt).toLocaleString()}
                        </p>
                    )}
                </div>
            )}

            <Panel
                title="Delivery"
                action={
                    <Button size="sm" asChild>
                        <Link href={urls.edit}>Edit</Link>
                    </Button>
                }
            >
                <div className="space-y-4">
                    <div>
                        <p className="label">Endpoint URL</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 truncate mono text-sm">
                                {stream.endpointUrl}
                            </code>
                            <CopyButton value={stream.endpointUrl} />
                        </div>
                    </div>

                    <KvList>
                        <Kv label="Destination" prose>
                            <Badge>{stream.destination}</Badge>
                        </Kv>
                        {stream.scheme !== null && (
                            <Kv label="Auth scheme" prose>
                                <Badge>{stream.scheme}</Badge>
                            </Kv>
                        )}
                        {stream.options.map((row) => (
                            <Kv key={row.label} label={row.label}>
                                {row.value}
                            </Kv>
                        ))}
                        <Kv label="Last delivery" prose>
                            {stream.lastSuccessAt === null
                                ? 'Nothing delivered yet'
                                : new Date(stream.lastSuccessAt).toLocaleString()}
                        </Kv>
                    </KvList>
                </div>
            </Panel>

            <Panel
                title="Send test event"
                description="One marked event (siem.stream.test), sent now with this stream's settings and credential — not queued, not retried. A success also clears “Action required”."
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
                        Send test event
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
                                ? 'Delivered — the destination accepted the test event.'
                                : `Not delivered: ${streamTest.error ?? 'the destination refused it.'}`}
                        </output>
                    )}
                </div>
            </Panel>

            {aws?.permissionsPolicy != null && (
                <Panel
                    title="AWS permissions"
                    description={
                        aws.externalId !== null
                            ? 'Attach this to the role: it may write objects under the prefix and nothing else — no read, list or delete.'
                            : 'Attach this to the IAM user whose access key the stream uses: it may write objects under the prefix and nothing else — no read, list or delete.'
                    }
                >
                    <CodeBlock code={aws.permissionsPolicy} copyLabel="Copy permissions policy" />
                </Panel>
            )}

            <Panel
                title={stream.enabled ? 'Disable stream' : 'Resume stream'}
                description={
                    stream.enabled
                        ? 'Entries stop being delivered and are KEPT — nothing is dropped from the trail while it is off.'
                        : 'Delivery resumes. Entries written while it was off are still pending and go out with the next batch.'
                }
            >
                <Button
                    size="sm"
                    onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                >
                    {stream.enabled ? 'Disable' : 'Resume'}
                </Button>
            </Panel>

            <Panel
                title="Delete stream"
                description="Delivery stops immediately and the stored credential is destroyed. The audit trail itself is untouched."
            >
                <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                    Delete stream
                </Button>
            </Panel>

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={stream.name}
                consequence="Delivery to this destination stops immediately and the stored credential is destroyed. The audit trail itself is untouched. This cannot be undone."
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
                title={justCreated ? 'Finish in AWS: trust this platform' : 'AWS role trust'}
                description="Set this as the role's trust policy (IAM › Roles › Trust relationships). It lets this platform assume the role only when it presents this stream's external ID, so no one else on this platform can point a stream at your role."
            >
                <div className="space-y-4">
                    <div>
                        <p className="label">External ID</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 break-all mono text-sm">
                                {externalId}
                            </code>
                            <CopyButton
                                value={externalId}
                                label="Copy"
                                aria-label="Copy external ID"
                            />
                        </div>
                    </div>
                    <CodeBlock
                        code={trustPolicy}
                        copyLabel="Copy trust policy"
                        caption={
                            principalConfigured
                                ? undefined
                                : "Replace the Principal with this platform's AWS user ARN — ask whoever runs this install (SIEM_AWS_PRINCIPAL_ARN)."
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
                        Copy this signing key now — it won't be shown again.
                    </p>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        Only the encrypted form is stored, so it cannot be retrieved. Your SIEM
                        verifies each delivery's signature with it.
                    </p>
                </div>
                <Button size="sm" className="shrink-0" onClick={onDismiss}>
                    Dismiss
                </Button>
            </div>

            <div className="mt-4 flex items-start gap-2">
                <code className="mono text-sm break-all select-all flex-1">{secret}</code>
                <CopyButton value={secret} variant="primary" label="Copy key" />
            </div>
        </div>
    );
}

LogStreamDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
