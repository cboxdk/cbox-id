import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import type { HelpContent } from '@/types';
import { Button, ConfirmDelete, Icon, type LinkTab, LinkTabs, PageHeader, Pill } from '@/ui';

/** `RadarController::modeProps()` */
export interface RadarMode {
    mode: 'monitor' | 'enforce';
    /** True while the environment follows the deployment's RISK_MODE rather than its own choice. */
    inherited: boolean;
    deploymentMode: 'monitor' | 'enforce';
    intelligence: 'none' | 'maxmind' | 'ipinfo';
    href: string;
}

const INTELLIGENCE: Record<RadarMode['intelligence'], string> = {
    none: 'No IP intelligence configured — country, network and travel rules cannot fire.',
    maxmind: 'IP intelligence: MaxMind database files on this deployment.',
    ipinfo: 'IP intelligence: IPinfo.',
};

/**
 * The top of every Radar page: the title, the three tabs, and the one switch that decides
 * whether anything on them is acted on.
 *
 * The switch asks before it flips, both ways. Enforce with wrong rules locks real people out;
 * monitor silently stops refusing credential stuffing. The route behind it asks for a fresh
 * password too.
 */
export function RadarFrame({
    help,
    tabs,
    mode,
    description,
    actions,
    children,
}: {
    help: HelpContent;
    tabs: LinkTab[];
    mode?: RadarMode;
    description: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const target = mode?.mode === 'enforce' ? 'monitor' : 'enforce';

    return (
        <>
            <PageHeader help={help} description={description} actions={actions} />

            {mode !== undefined && (
                <section
                    className="mt-5 card p-4 flex flex-wrap items-center gap-3"
                    aria-label="Radar mode"
                >
                    <Pill tone={mode.mode === 'enforce' ? 'success' : 'warning'}>
                        {mode.mode === 'enforce' ? 'Enforcing' : 'Monitoring'}
                    </Pill>
                    <p className="text-sm flex-1 min-w-[16rem]" style={{ color: 'var(--muted)' }}>
                        {mode.mode === 'enforce'
                            ? 'Blocks refuse sign-ins and sign-ups; challenges ask for a second factor.'
                            : 'Every verdict is recorded and none is acted on. Read the decisions before you enforce.'}
                        {mode.inherited && ' Following this deployment’s default until you choose.'}{' '}
                        <span style={{ color: 'var(--faint)' }}>
                            {INTELLIGENCE[mode.intelligence]}
                        </span>
                    </p>
                    <Button
                        variant={target === 'enforce' ? 'primary' : 'danger'}
                        onClick={() => setConfirming(true)}
                    >
                        <Icon name="shield" className="w-4 h-4" />
                        {target === 'enforce' ? 'Switch to enforce' : 'Switch to monitor'}
                    </Button>
                    <ConfirmDelete
                        open={confirming}
                        onOpenChange={setConfirming}
                        name={target}
                        verb={target === 'enforce' ? 'Enforce' : 'Monitor'}
                        title={
                            target === 'enforce'
                                ? 'Start blocking and challenging sign-ins?'
                                : 'Stop blocking and challenging sign-ins?'
                        }
                        actionLabel={
                            target === 'enforce' ? 'Switch to enforce' : 'Switch to monitor'
                        }
                        consequence={
                            target === 'enforce'
                                ? 'From the next attempt, blocks refuse sign-ins and sign-ups in this environment, and challenges ask for a second factor. If a rule is wrong, real people are locked out.'
                                : 'From the next attempt, nothing is refused or challenged — credential stuffing included. Verdicts are still recorded.'
                        }
                        confirming={processing}
                        onConfirm={() => {
                            setProcessing(true);
                            router.put(
                                mode.href,
                                { mode: target },
                                {
                                    preserveScroll: true,
                                    onFinish: () => {
                                        setProcessing(false);
                                        setConfirming(false);
                                    },
                                },
                            );
                        }}
                    />
                </section>
            )}

            <div className="mt-6">
                <LinkTabs tabs={tabs} label="Radar" />
            </div>

            {children}
        </>
    );
}
