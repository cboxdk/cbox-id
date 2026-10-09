import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, PageHeader, Pill, ProviderMark } from '@/ui';

interface ProviderRow {
    key: string;
    name: string;
    configured: boolean;
    enabled: boolean;
    connections: number;
    grants: number;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    providers: ProviderRow[];
}>;

/**
 * Developers › Pipes — every provider people can connect an account at, configured or not.
 *
 * One card per catalogue entry rather than a list of what exists: the question on arrival
 * is usually "can my users connect Salesforce?", and the answer should be on screen
 * whether or not anybody has set it up yet.
 */
export default function PipesIndex({ help, providers }: Props) {
    return (
        <>
            <PageHeader
                help={help}
                description="Let the people who use your apps connect their own GitHub, Google, Slack or Salesforce account, so your apps can call those APIs on their behalf. Their tokens are stored encrypted, refreshed here, and leased only to the apps you grant."
            />

            <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {providers.map((provider) => (
                    <li
                        key={provider.key}
                        className="rounded-xl border p-4"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2.5">
                                <ProviderMark provider={provider.key} size={22} />
                                <span className="font-medium">{provider.name}</span>
                            </div>
                            {provider.configured ? (
                                <Pill tone={provider.enabled ? 'success' : 'neutral'}>
                                    {provider.enabled ? 'Enabled' : 'Disabled'}
                                </Pill>
                            ) : (
                                <Pill>Not set up</Pill>
                            )}
                        </div>
                        <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {provider.configured
                                ? `${provider.connections} connected ${provider.connections === 1 ? 'account' : 'accounts'} · ${provider.grants} ${provider.grants === 1 ? 'app' : 'apps'} granted`
                                : 'People cannot connect this provider yet.'}
                        </p>
                        <div className="mt-3">
                            <Button
                                asChild
                                size="sm"
                                variant={provider.configured ? undefined : 'primary'}
                            >
                                <Link href={provider.href}>
                                    {provider.configured ? 'Manage' : 'Set up'}
                                </Link>
                            </Button>
                        </div>
                    </li>
                ))}
            </ul>
        </>
    );
}

PipesIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
