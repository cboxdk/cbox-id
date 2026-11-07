import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.let_the_people_who_use_your_apps_connect_their_own_gith_19a2d0ed',
                )}
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
                                    {provider.enabled
                                        ? consoleText('console.enabled_92c1cdfd')
                                        : consoleText('console.disabled_75081b59')}
                                </Pill>
                            ) : (
                                <Pill>{consoleT('console.not_set_up_94b768af')}</Pill>
                            )}
                        </div>
                        <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {provider.configured
                                ? consoleFinalText('console.pipes_connection_summary', {
                                      accounts: provider.connections,
                                      apps: provider.grants,
                                  })
                                : consoleText(
                                      'console.people_cannot_connect_this_provider_yet_a24e2212',
                                  )}
                        </p>
                        <div className="mt-3">
                            <Button
                                asChild
                                size="sm"
                                variant={provider.configured ? undefined : 'primary'}
                            >
                                <Link href={provider.href}>
                                    {provider.configured
                                        ? consoleText('console.manage_5a234448')
                                        : consoleText('console.set_up_4da10f1f')}
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
