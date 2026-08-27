import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    Icon,
    Kv,
    KvList,
    Panel,
    Pill,
} from '@/ui';

type Props = PageProps<{
    stream: {
        id: string;
        name: string;
        destination: string;
        endpointUrl: string;
        scheme: string;
        enabled: boolean;
    };
    indexHref: string;
    urls: { toggle: string; destroy: string };
}>;

export default function LogStreamDetail({ stream, indexHref, urls }: Props) {
    // The signing key, on the flash channel and nowhere else: only ciphertext is
    // persisted, so this is the only time it exists, and props are written into the
    // browser's history entry.
    const newSecret = usePage().flash.newSecret;

    const [confirming, setConfirming] = useState(false);
    const [secretDismissed, setSecretDismissed] = useState(false);

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
                    Log streaming
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{stream.name}</h1>
                    <Badge>{stream.destination}</Badge>
                    <Pill tone={stream.enabled ? 'success' : 'warning'}>
                        {stream.enabled ? 'Delivering' : 'Disabled'}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {stream.id}
                </p>
            </div>

            {newSecret !== undefined && !secretDismissed && (
                <RevealedKey secret={newSecret} onDismiss={() => setSecretDismissed(true)} />
            )}

            <Panel title="Delivery">
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
                        <Kv label="Auth scheme" prose>
                            <Badge>{stream.scheme}</Badge>
                        </Kv>
                    </KvList>
                </div>
            </Panel>

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
                description="Delivery stops immediately and the signing key is destroyed. The audit trail itself is untouched."
            >
                <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                    Delete stream
                </Button>
            </Panel>

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={stream.name}
                consequence="Delivery to this SIEM stops immediately and the signing key is destroyed. The audit trail itself is untouched. This cannot be undone."
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
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
