import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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

const INTELLIGENCE = (tc: Translator['t']): Record<RadarMode['intelligence'], string> => ({
    none: tc('console.no_ip_intelligence_configured_country_networ_8ebfbb9f'),
    maxmind: tc('console.ip_intelligence_maxmind_database_files_on_th_d7e6172c'),
    ipinfo: tc('console.ip_intelligence_ipinfo_a64fcb37'),
});

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
    const { t: tc } = useTranslator();

    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const target = mode?.mode === 'enforce' ? 'monitor' : 'enforce';

    return (
        <>
            <PageHeader help={help} description={description} actions={actions} />

            {mode !== undefined && (
                <section
                    className="mt-5 card p-4 flex flex-wrap items-center gap-3"
                    aria-label={tc('console.radar_mode_9c13cfa3')}
                >
                    <Pill tone={mode.mode === 'enforce' ? 'success' : 'warning'}>
                        {mode.mode === 'enforce'
                            ? tc('console.enforcing_1ca5672a')
                            : tc('console.monitoring_1dc83f60')}
                    </Pill>
                    <p className="text-sm flex-1 min-w-[16rem]" style={{ color: 'var(--muted)' }}>
                        {mode.mode === 'enforce'
                            ? tc('console.blocks_refuse_sign_ins_and_sign_ups_challeng_6a4ee9dc')
                            : tc('console.every_verdict_is_recorded_and_none_is_acted__1b797986')}
                        {mode.inherited &&
                            tc(
                                'console.following_this_deployment_s_default_until_yo_588a3298',
                            )}{' '}
                        <span style={{ color: 'var(--faint)' }}>
                            {INTELLIGENCE(tc)[mode.intelligence]}
                        </span>
                    </p>
                    <Button
                        variant={target === 'enforce' ? 'primary' : 'danger'}
                        onClick={() => setConfirming(true)}
                    >
                        <Icon name="shield" className="w-4 h-4" />
                        {target === 'enforce'
                            ? tc('console.switch_to_enforce_199a1586')
                            : tc('console.switch_to_monitor_d9ddd684')}
                    </Button>
                    <ConfirmDelete
                        open={confirming}
                        onOpenChange={setConfirming}
                        name={target}
                        verb={target === 'enforce' ? 'Enforce' : 'Monitor'}
                        title={
                            target === 'enforce'
                                ? tc('console.start_blocking_and_challenging_sign_ins_d9132199')
                                : tc('console.stop_blocking_and_challenging_sign_ins_40067216')
                        }
                        actionLabel={
                            target === 'enforce'
                                ? tc('console.switch_to_enforce_199a1586')
                                : tc('console.switch_to_monitor_d9ddd684')
                        }
                        consequence={
                            target === 'enforce'
                                ? tc(
                                      'console.from_the_next_attempt_blocks_refuse_sign_ins_e89996c3',
                                  )
                                : tc(
                                      'console.from_the_next_attempt_nothing_is_refused_or__0dcf7713',
                                  )
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
                <LinkTabs tabs={tabs} label={tc('console.radar_524f8327')} />
            </div>

            {children}
        </>
    );
}
