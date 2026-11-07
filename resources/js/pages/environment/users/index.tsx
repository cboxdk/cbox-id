import { useCanManageEnvironment } from '@/ui/EnvironmentReadOnly';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, SimplePagination as SimplePaginationState } from '@/types';
import {
    Avatar,
    Button,
    Dialog,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    Panel,
    Pill,
    type PillTone,
    SimplePagination,
} from '@/ui';
import { UserProfile } from './profile';

interface UserRow {
    id: string;
    name: string | null;
    email: string;
    status: string;
    verified: boolean;
    href: string;
}
interface UserPreview extends UserRow {
    hasMfa: boolean;
    updateHref: string;
    memberships: { id: string; name: string; role: string; href: string }[];
}
type Props = PageProps<{
    users: UserRow[];
    pagination: SimplePaginationState;
    search: string;
    statusFilter: string;
    selectedUser: UserPreview | null;
    createHref: string;
    help: HelpContent;
}>;

function statusTone(status: string): PillTone {
    return status === 'active' ? 'success' : status === 'locked' ? 'destructive' : 'warning';
}

export default function UsersIndex({
    users,
    pagination,
    search,
    statusFilter,
    selectedUser,
    createHref,
    help,
}: Props) {
    const canManage = useCanManageEnvironment();
    const { t } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [term, setTerm] = useState(search);
    const filters = [
        { value: 'active', label: t('console.active_92340695') },
        { value: 'disabled', label: t('console.disabled_75081b59') },
        { value: 'locked', label: t('console.ux_locked') },
        { value: 'all', label: t('console.all_a52ace42') },
    ];
    const href = useCallback(
        (query: string, status = statusFilter, page = 1, selected?: string) => {
            const params = new URLSearchParams();
            if (query) params.set('q', query);
            if (status !== 'active') params.set('status', status);
            if (page > 1) params.set('page', String(page));
            if (selected) params.set('selected', selected);
            return `${window.location.pathname}${params.size ? `?${params}` : ''}`;
        },
        [statusFilter],
    );
    const label = (status: string) =>
        filters.find((filter) => filter.value === status)?.label ?? status;

    useEffect(() => {
        if (term === search) return;
        const timer = setTimeout(
            () =>
                router.get(
                    href(term),
                    {},
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            300,
        );
        return () => clearTimeout(timer);
    }, [term, search, href]);

    useEffect(() => {
        const restore = () => setTerm(new URL(window.location.href).searchParams.get('q') ?? '');
        window.addEventListener('popstate', restore);
        return () => window.removeEventListener('popstate', restore);
    }, []);

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.every_end_user_identity_in_this_environment_ca1655ff',
                )}
                actions={
                    canManage && (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                {consoleRich('console._part1_new_user_6b6e5234', {
                                    part1: <Icon name="plus" className="w-4 h-4" />,
                                })}
                            </Link>
                        </Button>
                    )
                }
            />
            <div className="cbx-list-toolbar mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={consoleUiText('console.search_by_email_or_name_683669d2')}
                    aria-label={consoleT('console.search_users_e4bb77af')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                <nav className="cbx-segmented" aria-label={t('console.users_6b0cc904')}>
                    {filters.map((filter) => (
                        <Link
                            key={filter.value}
                            href={href(term, filter.value)}
                            preserveScroll
                            preserveState
                            aria-current={statusFilter === filter.value ? 'page' : undefined}
                        >
                            {filter.label}
                        </Link>
                    ))}
                </nav>
                <output className="sr-only">
                    {consoleRich('console.results_on_page', { count: pagination.count })}
                </output>
            </div>
            <div className="mt-4 rounded-xl border border-[var(--border)] overflow-hidden">
                {users.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={consoleT('console.no_matches_2df01a03')}
                            description={consoleFinalText(
                                'console.no_users_match_part1_try_a_different_email_or_name_dc47e2b6',
                                { part1: search },
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="members"
                            equivalent="users.create"
                            title={consoleT('console.no_users_yet_eadb8912')}
                            description={consoleT(
                                'console.every_end_user_identity_in_this_environment_appears_her_480d2872',
                            )}
                            actions={
                                canManage && (
                                    <Button asChild variant="primary">
                                        <Link href={createHref}>
                                            {consoleRich('console._part1_new_user_6b6e5234', {
                                                part1: <Icon name="plus" className="w-4 h-4" />,
                                            })}
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    )
                ) : (
                    users.map((user) => (
                        <Link
                            key={user.id}
                            data-user-id={user.id}
                            href={href(search, statusFilter, pagination.currentPage, user.id)}
                            preserveState
                            preserveScroll
                            className="cbx-entity-row cbx-user-row"
                            aria-current={selectedUser?.id === user.id ? 'true' : undefined}
                        >
                            <Avatar name={user.name ?? user.email} />
                            <div className="min-w-0 flex-1">
                                <span className="block font-medium truncate">
                                    {user.name ?? user.email}
                                </span>
                                {user.name && (
                                    <span className="block mt-1 text-sm truncate text-[var(--muted-foreground)]">
                                        {user.email}
                                    </span>
                                )}
                            </div>
                            <div className="cbx-user-row-status flex flex-wrap gap-2">
                                {!user.verified && user.status === 'active' && (
                                    <Pill tone="warning">{t('console.unverified_33c8e8de')}</Pill>
                                )}
                                <Pill tone={statusTone(user.status)}>{label(user.status)}</Pill>
                            </div>

                            {!user.verified && (
                                <Pill tone="warning">
                                    {consoleT('console.unverified_33c8e8de')}
                                </Pill>
                            )}

                            <Pill tone={statusTone(user.status)}>{user.status}</Pill>

                            <Icon
                                name="chevron"
                                className="cbx-user-row-chevron w-4 h-4 shrink-0 -rotate-90 text-[var(--faint)]"
                            />
                        </Link>
                    ))
                )}
            </div>
            <div className="mt-4">
                <SimplePagination
                    pagination={pagination}
                    href={(page) => href(search, statusFilter, page)}
                />
            </div>

            <Dialog
                presentation="panel"
                open={selectedUser !== null}
                onOpenChange={(open) => {
                    if (!open)
                        router.get(
                            href(search, statusFilter, pagination.currentPage),
                            {},
                            { preserveState: true, preserveScroll: true },
                        );
                }}
                title={selectedUser?.name ?? selectedUser?.email ?? t('console.ux_user_details')}
                description={selectedUser?.email}
                footer={
                    selectedUser && (
                        <Button asChild variant="primary">
                            <Link href={selectedUser.href}>
                                {t('console.ux_open_profile')}
                                <Icon name="external" className="w-4 h-4" />
                            </Link>
                        </Button>
                    )
                }
            >
                {selectedUser && (
                    <div className="space-y-5" key={selectedUser.id}>
                        <div className="flex flex-wrap gap-2">
                            <Pill tone={statusTone(selectedUser.status)}>
                                {label(selectedUser.status)}
                            </Pill>
                            {selectedUser.hasMfa && (
                                <Pill tone="success">{t('console.mfa_enrolled_c56fc40b')}</Pill>
                            )}
                            {!selectedUser.verified && (
                                <Pill tone="warning">{t('console.unverified_33c8e8de')}</Pill>
                            )}
                        </div>
                        <UserProfile user={selectedUser} href={selectedUser.updateHref} />
                        <Panel title={t('console.organizations_2730183d')} flush>
                            {selectedUser.memberships.length ? (
                                selectedUser.memberships.map((membership) => (
                                    <Link
                                        key={membership.id}
                                        href={membership.href}
                                        className="cbx-entity-row"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium break-words">
                                                {membership.name}
                                            </p>
                                            <p className="text-xs text-[var(--muted-foreground)]">
                                                {membership.role}
                                            </p>
                                        </div>
                                        <Icon name="external" className="w-4 h-4 shrink-0" />
                                    </Link>
                                ))
                            ) : (
                                <p className="p-5 text-sm text-[var(--muted-foreground)]">
                                    {t('console.not_a_member_of_any_organization_49453904')}
                                </p>
                            )}
                        </Panel>
                        <div className="grid grid-cols-2 gap-2">
                            <Button asChild>
                                <Link href={`${selectedUser.href}?section=security`}>
                                    <Icon name="shield" className="w-4 h-4" />
                                    {t('console.security_8f6fb4eb')}
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={`${selectedUser.href}?section=support`}>
                                    {t('console.support_access_851e9a34')}
                                </Link>
                            </Button>
                        </div>
                    </div>
                )}
            </Dialog>
        </>
    );
}
UsersIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
