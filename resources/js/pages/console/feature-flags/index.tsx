import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, EmptyState, Icon, PageHeader } from '@/ui';

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
function summary(flag: FlagRow): string {
    const parts: string[] = [];

    if (flag.organizationRules > 0) {
        parts.push(
            `${flag.organizationRules} ${flag.organizationRules === 1 ? 'organization' : 'organizations'}`,
        );
    }

    if (flag.userRules > 0) {
        parts.push(`${flag.userRules} ${flag.userRules === 1 ? 'user' : 'users'}`);
    }

    if (flag.rolloutPercentage !== null) {
        parts.push(`${flag.rolloutPercentage}% rollout`);
    }

    return parts.length === 0 ? 'No rules' : parts.join(' · ');
}

export default function FeatureFlags({ help, flags, createHref }: Props) {
    return (
        <>
            <PageHeader
                help={help}
                description="Switches your apps ask about for one person in one organization. Turn a feature on for named users, named organizations or a share of everyone, and every app reads the same answer — in the token or from the API."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New flag
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
                        title="No feature flags yet"
                        help={help}
                        description="A flag lets you ship a feature dark and turn it on for the people who should see it first, without a deploy."
                        steps={[
                            'Create a flag with the key your code will ask for — for example new-dashboard.',
                            'Turn it on for a beta organization, a few users, or a percentage of everyone.',
                            'Give your app the feature_flags scope, and read the feature_flags claim from its token.',
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
                                    <Badge>{summary(flag)}</Badge>
                                    <Badge>Default {flag.defaultValue ? 'on' : 'off'}</Badge>
                                </div>
                            </div>
                            {flag.enabled ? (
                                <Badge tone="success">On</Badge>
                            ) : (
                                <Badge tone="warn">Switched off</Badge>
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
        </>
    );
}

FeatureFlags.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
