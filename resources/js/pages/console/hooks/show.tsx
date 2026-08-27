import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, ConfirmDelete, CopyButton, Icon, Kv, KvList, Panel, Pill } from '@/ui';

type Props = PageProps<{
    hook: {
        id: string;
        url: string;
        point: string;
        pointDescription: string;
        /** The organization it belongs to, or null when the environment owns it. */
        owner: string | null;
        active: boolean;
    };
    mayManage: boolean;
    indexHref: string;
    urls: { toggle: string; destroy: string };
}>;

export default function HookDetail({ hook, mayManage, indexHref, urls }: Props) {
    // The signing secret, on the flash channel and nowhere else: the endpoint authenticates
    // every call we make to it with this, and props are written into the history entry.
    const newSecret = usePage().flash.newSecret;

    const [confirming, setConfirming] = useState(false);

    /*
     * DISMISSING THE SECRET IS THE READER'S, not the server's.
     *
     * Under Volt this was a round trip, because the banner's visibility was server state.
     * By the time anybody presses it the secret is already off the server — the whole point
     * of a reveal-once credential — so making the banner go IS the entire job, and it
     * belongs where the person pressing it is. It matters: this is plaintext on screen, and
     * somebody who has copied it, or who is sharing a screen, needs it gone now rather than
     * at the next navigation.
     */
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
                    Inline hooks
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title mono truncate" style={{ fontSize: '1.25rem' }}>
                        {hook.url}
                    </h1>
                    <Pill tone={hook.active ? 'success' : 'warning'}>
                        {hook.active ? 'Active' : 'Paused'}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {hook.id}
                </p>
            </div>

            {newSecret !== undefined && !secretDismissed && (
                <RevealedSecret secret={newSecret} onDismiss={() => setSecretDismissed(true)} />
            )}

            <Panel title="Details">
                <KvList>
                    <Kv label="Hook point" prose>
                        <span className="flex items-center gap-2 flex-wrap">
                            <Badge>{hook.point}</Badge>
                            <span style={{ color: 'var(--muted-foreground)' }}>
                                {hook.pointDescription}
                            </span>
                        </span>
                    </Kv>
                    <Kv label="Organization" prose>
                        <Badge>{hook.owner ?? 'All organizations'}</Badge>
                    </Kv>
                    <Kv label="Endpoint URL">{hook.url}</Kv>
                </KvList>
            </Panel>

            {mayManage ? (
                <>
                    <Panel
                        title={hook.active ? 'Pause endpoint' : 'Resume endpoint'}
                        description={
                            hook.active
                                ? 'It stops being called at the hook point. The operation it answers at goes ahead without it.'
                                : 'It is called again at the hook point from the next matching operation.'
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {hook.active ? 'Pause' : 'Activate'}
                        </Button>
                    </Panel>

                    <Panel
                        title="Remove endpoint"
                        description="The hook stops being called and its signing secret is destroyed."
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                            Remove endpoint
                        </Button>
                    </Panel>
                </>
            ) : (
                <Panel title="Managed by your operator">
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        This endpoint belongs to the environment and fires for every organization
                        in it. It is shown here because it runs on your sign-ins — your operator
                        manages it.
                    </p>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={hook.url}
                verb="Remove"
                consequence="The hook stops being called and its signing secret is destroyed. This cannot be undone."
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * The signing secret, shown exactly once.
 *
 * IT BRINGS ITSELF INTO VIEW and takes focus with it. Registration happens on another page
 * and lands here, so without this the credential a person must copy right now is simply
 * somewhere on a screen they have not read yet.
 */
function RevealedSecret({ secret, onDismiss }: { secret: string; onDismiss: () => void }) {
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
                        Copy this signing secret now — it won't be shown again.
                    </p>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        There is no rotation on an inline hook: if this is lost, the endpoint has
                        to be registered again.
                    </p>
                </div>
                <Button size="sm" className="shrink-0" onClick={onDismiss}>
                    Dismiss
                </Button>
            </div>

            <div className="mt-4 flex items-start gap-2">
                <code className="mono text-sm break-all select-all flex-1">{secret}</code>
                <CopyButton value={secret} variant="primary" label="Copy secret" />
            </div>

            <p className="mt-3 text-xs" style={{ color: 'var(--warning-strong)' }}>
                Your endpoint verifies the <code className="mono">X-Cbox-Signature</code> header on
                each request with this secret.
            </p>
        </div>
    );
}

HookDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
