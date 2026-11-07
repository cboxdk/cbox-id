import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, EmptyState, Icon, PageHeader, AccessModelGuide } from '@/ui';

/** One row of `FeatureFlagController::index()`. */
interface FlagRow {
    id: string;
    key: string;
    description: string | null;
    enabled: boolean;
    defaultValue: boolean;
    userRules: number;
    organizationRules: number;
    rolloutPercentage: number | null;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    flags: FlagRow[];
    createHref: string;
}>;

/** "2 organizations · 1 user · 25% rollout", or what the flag does with no rules. */
function summary(flag: FlagRow, tc: Translator['t']): string {
    const parts: string[] = [];

    if (flag.organizationRules > 0) {
        parts.push(tc('console.flag_organization_rules_count', { count: flag.organizationRules }));
    }

    if (flag.userRules > 0) {
        parts.push(tc('console.flag_user_rules_count', { count: flag.userRules }));
    }

    if (flag.rolloutPercentage !== null) {
        parts.push(tc('console.count_rollout_a202cfc4', { count: flag.rolloutPercentage }));
    }

    return parts.length === 0 ? tc('console.no_rules_5dfc113c') : parts.join(' · ');
}

export default function FeatureFlags({ help, flags, createHref }: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.switches_your_apps_ask_about_for_one_person_in_one_orga_e232d9a9',
                )}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            {consoleRich('console._part1_new_flag_85bcb848', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
                        </Link>
                    </Button>
                }
            />

            <div
                className="mt-6 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {flags.length === 0 ? (
                    <EmptyState
                        icon="switch"
                        equivalent="feature_flags.create"
                        actions={
                            <Button asChild variant="primary">
                                <Link href={createHref}>
                                    {consoleRich('console._part1_new_flag_85bcb848', {
                                        part1: <Icon name="plus" className="w-4 h-4" />,
                                    })}
                                </Link>
                            </Button>
                        }
                        title={consoleT('console.no_feature_flags_yet_ceaa8b06')}
                        help={help}
                        description={consoleT(
                            'console.a_flag_lets_you_ship_a_feature_dark_and_turn_it_on_for_77bad22e',
                        )}
                        steps={[
                            consoleRuntimeText(
                                'console.create_a_flag_with_the_key_your_code_will_ask_for_for_e_958496bd',
                            ),
                            consoleRuntimeText(
                                'console.turn_it_on_for_a_beta_organization_a_few_users_or_a_per_bea36395',
                            ),
                            consoleRuntimeText(
                                'console.give_your_app_the_feature_flags_scope_and_read_the_feat_a6908f11',
                            ),
                        ]}
                    />
                ) : (
                    flags.map((flag, index) => (
                        <Link
                            key={flag.id}
                            href={flag.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index === flags.length - 1
                                    ? undefined
                                    : { borderBottom: '1px solid var(--border)' }
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <code className="mono font-medium truncate block">{flag.key}</code>
                                {flag.description !== null && (
                                    <p
                                        className="text-sm truncate"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {flag.description}
                                    </p>
                                )}
                                <div className="mt-1.5 flex items-center gap-1.5 flex-wrap">
                                    <Badge>{summary(flag, consoleT)}</Badge>
                                    <Badge>
                                        {consoleRich('console.default_part1_a0a77763', {
                                            part1: flag.defaultValue
                                                ? consoleText('console.on_b8d31e85')
                                                : consoleText('console.off_b4dc66dd'),
                                        })}
                                    </Badge>
                                </div>
                            </div>
                            {flag.enabled ? (
                                <Badge tone="success">{consoleT('console.on_13001175')}</Badge>
                            ) : (
                                <Badge tone="warn">
                                    {consoleT('console.switched_off_183c899d')}
                                </Badge>
                            )}
                            <Icon
                                name="chevron"
                                className="w-4 h-4 shrink-0"
                                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                            />
                        </Link>
                    ))
                )}
            </div>
            <div className="mt-8">
                <AccessModelGuide current="feature-flags" />
            </div>
        </>
    );
}

FeatureFlags.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
