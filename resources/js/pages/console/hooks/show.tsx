import { useTranslator } from '@/i18n';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    ConfirmDelete,
    CopyButton,
    Kv,
    KvList,
    Panel,
    Pill,
} from '@/ui';

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
    const { t: tc } = useTranslator();

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
                <Breadcrumb href={indexHref} label={tc('console.hooks_d412a062')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title mono truncate" style={{ fontSize: '1.25rem' }}>
                        {hook.url}
                    </h1>
                    <Pill tone={hook.active ? 'success' : 'warning'}>
                        {hook.active
                            ? tc('console.active_92340695')
                            : tc('console.paused_e159b061')}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {hook.id}
                </p>
            </div>

            {newSecret !== undefined && !secretDismissed && (
                <RevealedSecret secret={newSecret} onDismiss={() => setSecretDismissed(true)} />
            )}

            <Panel title={tc('console.details_45989de4')}>
                <KvList>
                    <Kv label={tc('console.hook_point_57b75ff5')} prose>
                        <span className="flex items-center gap-2 flex-wrap">
                            <Badge>{hook.point}</Badge>
                            <span style={{ color: 'var(--muted-foreground)' }}>
                                {hook.pointDescription}
                            </span>
                        </span>
                    </Kv>
                    <Kv label={tc('console.organization_d764d425')} prose>
                        <Badge>{hook.owner ?? tc('console.all_organizations_e3002b23')}</Badge>
                    </Kv>
                    <Kv label={tc('console.endpoint_url_2578179d')}>{hook.url}</Kv>
                </KvList>
            </Panel>

            {mayManage ? (
                <>
                    <Panel
                        title={
                            hook.active
                                ? tc('console.pause_endpoint_b58b90e3')
                                : tc('console.resume_endpoint_f4e2aed4')
                        }
                        description={
                            hook.active
                                ? tc(
                                      'console.it_stops_being_called_at_the_hook_point_the__72937229',
                                  )
                                : tc(
                                      'console.it_is_called_again_at_the_hook_point_from_th_a4d12b55',
                                  )
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {hook.active
                                ? tc('console.pause_858e4ba7')
                                : tc('console.activate_24433c70')}
                        </Button>
                    </Panel>

                    <Panel
                        title={tc('console.remove_endpoint_576e9393')}
                        description={tc(
                            'console.the_hook_stops_being_called_and_its_signing__baa86fc7',
                        )}
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                            {tc('console.remove_endpoint_576e9393')}
                        </Button>
                    </Panel>
                </>
            ) : (
                <Panel title={tc('console.managed_by_your_operator_3e30ba47')}>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.this_endpoint_belongs_to_the_environment_and_2bbbb6fd')}
                    </p>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={hook.url}
                verb="Remove"
                consequence={tc('console.the_hook_stops_being_called_and_its_signing__ad833a77')}
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
                        {tc('console.copy_this_signing_secret_now_it_won_t_be_sho_2331f9ee')}
                    </p>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.there_is_no_rotation_on_a_hook_if_this_is_lo_5577289c')}
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
                    label={tc('console.copy_secret_9b80d4b3')}
                />
            </div>

            <p className="mt-3 text-xs" style={{ color: 'var(--warning-strong)' }}>
                {tc('console.your_endpoint_verifies_the_7f130ed2')}
                <code className="mono">{tc('console.x_cbox_signature_f04741a9')}</code>
                {tc('console.header_on_each_request_with_this_secret_c60410e2')}
            </p>
        </div>
    );
}

HookDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
