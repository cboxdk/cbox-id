import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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
        parts.push(
            `${flag.organizationRules} ${flag.organizationRules === 1 ? tc('console.organization_af3a1bb3') : tc('console.organizations_f8a665a9')}`,
        );
    }

    if (flag.userRules > 0) {
        parts.push(
            `${flag.userRules} ${flag.userRules === 1 ? tc('console.user_04f8996d') : tc('console.users_7dfb4cf6')}`,
        );
    }

    if (flag.rolloutPercentage !== null) {
        parts.push(tc('console.count_rollout_a202cfc4', { count: flag.rolloutPercentage }));
    }

    return parts.length === 0 ? tc('console.no_rules_5dfc113c') : parts.join(' · ');
}

export default function FeatureFlags({ help, flags, createHref }: Props) {
    const { t: tc } = useTranslator();

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.switches_your_apps_ask_about_for_one_person__e232d9a9')}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.new_flag_b3369217')}
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
                                    <Icon name="plus" className="w-4 h-4" />
                                    {tc('console.new_flag_b3369217')}
                                </Link>
                            </Button>
                        }
                        title={tc('console.no_feature_flags_yet_ceaa8b06')}
                        help={help}
                        description={tc(
                            'console.a_flag_lets_you_ship_a_feature_dark_and_turn_77bad22e',
                        )}
                        steps={[
                            tc('console.create_a_flag_with_the_key_your_code_will_as_958496bd'),
                            tc('console.turn_it_on_for_a_beta_organization_a_few_use_bea36395'),
                            tc('console.give_your_app_the_feature_flags_scope_and_re_a6908f11'),
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
                                    <Badge>{summary(flag, tc)}</Badge>
                                    <Badge>
                                        {tc('console.default_f12bc6ea')}
                                        {flag.defaultValue
                                            ? tc('console.on_b8d31e85')
                                            : tc('console.off_b4dc66dd')}
                                    </Badge>
                                </div>
                            </div>
                            {flag.enabled ? (
                                <Badge tone="success">{tc('console.on_13001175')}</Badge>
                            ) : (
                                <Badge tone="warn">{tc('console.switched_off_183c899d')}</Badge>
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
