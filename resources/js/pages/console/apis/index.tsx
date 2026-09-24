import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, EmptyState, Icon, PageHeader } from '@/ui';

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
    createHref: string;
}>;

export default function Apis({ help, apis, createHref }: Props) {
    return (
        <>
            <PageHeader
                help={help}
                description="The services of yours that receive access tokens, and the scopes each one owns. A registered API's scopes are yours to hand out — nobody can type one onto an app to get into your API."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New API
                        </Link>
                    </Button>
                }
            />

            <div
                className="mt-6 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {apis.length === 0 ? (
                    <EmptyState
                        icon="code"
                        title="No APIs registered yet"
                        help={help}
                        description="Until an API is registered, a scope is just text on an app, and anyone who can edit an app can give it any scope. Register the APIs your apps call so their scopes have an owner."
                        steps={[
                            'Register the API with the address its tokens should name — for example https://api.example.com.',
                            'Add the scopes it understands, and say which of them apps registered by organizations may request.',
                            'Give your apps those scopes on their Scopes tab; tokens for them name the API as their audience.',
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
                                        <Badge>Roles from {api.linkedApp}</Badge>
                                    )}
                                </div>
                            </div>
                            <Badge>
                                {api.scopeCount} {api.scopeCount === 1 ? 'scope' : 'scopes'}
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
