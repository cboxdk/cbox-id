import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.let_the_people_who_use_your_apps_connect_the_19a2d0ed')}
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
                                        ? tc('console.enabled_92c1cdfd')
                                        : tc('console.disabled_75081b59')}
                                </Pill>
                            ) : (
                                <Pill>{tc('console.not_set_up_94b768af')}</Pill>
                            )}
                        </div>
                        <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {provider.configured
                                ? tc(
                                      'console.value0_connected_value1_value2_value3_grante_0455f24d',
                                      {
                                          value0: provider.connections,
                                          value1:
                                              provider.connections === 1 ? 'account' : 'accounts',
                                          value2: provider.grants,
                                          value3: provider.grants === 1 ? 'app' : 'apps',
                                      },
                                  )
                                : tc('console.people_cannot_connect_this_provider_yet_a24e2212')}
                        </p>
                        <div className="mt-3">
                            <Button
                                asChild
                                size="sm"
                                variant={provider.configured ? undefined : 'primary'}
                            >
                                <Link href={provider.href}>
                                    {provider.configured
                                        ? tc('console.manage_5a234448')
                                        : tc('console.set_up_4da10f1f')}
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
