import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, OrganizationFilter, PageProps } from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    FilterChips,
    Icon,
    OrganizationFilterChip,
    PageHeader,
} from '@/ui';

/** `App\Http\Props\Console\ApiRowProps` */
interface ApiRow {
    id: string;
    name: string;
    identifier: string;
    owner: string;
    linkedApp: string | null;
    scopeCount: number;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    apis: ApiRow[];
    /** The Organization chip: an organization's own APIs, and the environment's it may use. */
    organizationFilter: OrganizationFilter | null;
    createHref: string;
}>;

export default function Apis({ help, apis, organizationFilter, createHref }: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.the_services_of_yours_that_receive_access_tokens_and_th_8f7e35e1',
                )}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            {consoleRich('console._part1_new_api_a405c2d8', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
                        </Link>
                    </Button>
                }
            />

            {organizationFilter !== null && (
                <div className="mt-6">
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                </div>
            )}

            <div
                className="mt-6 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {apis.length === 0 ? (
                    <EmptyState
                        icon="code"
                        equivalent="apis.create"
                        actions={
                            <Button asChild variant="primary">
                                <Link href={createHref}>
                                    {consoleRich('console._part1_new_api_a405c2d8', {
                                        part1: <Icon name="plus" className="w-4 h-4" />,
                                    })}
                                </Link>
                            </Button>
                        }
                        title={consoleT('console.no_apis_registered_yet_94c90755')}
                        help={help}
                        description={consoleT(
                            'console.until_an_api_is_registered_a_scope_is_just_text_on_an_a_db7d3254',
                        )}
                        steps={[
                            consoleRuntimeText(
                                'console.register_the_api_with_the_address_its_tokens_should_nam_ecada0cf',
                            ),
                            consoleRuntimeText(
                                'console.add_the_scopes_it_understands_and_say_which_of_them_app_29474e74',
                            ),
                            consoleRuntimeText(
                                'console.give_your_apps_those_scopes_on_their_scopes_tab_tokens_97d115cd',
                            ),
                        ]}
                    />
                ) : (
                    apis.map((api, index) => (
                        <Link
                            key={api.id}
                            href={api.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index === apis.length - 1
                                    ? undefined
                                    : { borderBottom: '1px solid var(--border)' }
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate block">{api.name}</span>
                                <p
                                    className="text-sm truncate mono"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {api.identifier}
                                </p>
                                <div className="mt-1.5 flex items-center gap-1.5 flex-wrap">
                                    <Badge>{api.owner}</Badge>
                                    {api.linkedApp !== null && (
                                        <Badge>
                                            {consoleRich('console.roles_from_part1_f33761e4', {
                                                part1: api.linkedApp,
                                            })}
                                        </Badge>
                                    )}
                                </div>
                            </div>
                            <Badge>
                                {api.scopeCount}{' '}
                                {api.scopeCount === 1
                                    ? consoleText('console.scope_5f161c91')
                                    : consoleText('console.scopes_56b3669c')}
                            </Badge>
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

Apis.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
