import { useTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Avatar,
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    Input,
    InviteForm,
    PageHeader,
    Pagination,
    Panel,
    type PendingInvitation,
    PendingInvitations,
    type RoleOption,
    roleSelectOptions,
    Select,
} from '@/ui';
import { access, invite, remove, role, transferOwnership } from '@routes/members';

interface Administrator {
    id: string;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    isSelf: boolean;
    pending: boolean;
    status: string;
    /**
     * The row's own answer, computed once on the server rather than re-derived here: the
     * controls a page draws and the writes the server accepts must come from one rule.
     */
    manageable: boolean;
    /** Whether this role can be limited to some environments rather than all. */
    scoped: boolean;
    allEnvironments: boolean;
    accessCount: number;
}

interface Editor {
    memberId: string;
    all: boolean;
    selected: string[];
    environments: { id: string; name: string; sandbox: boolean }[];
    /** More environments exist than the panel drew; the search is how you reach them. */
    truncated: boolean;
    search: string;
}

type Props = PageProps<{
    members: Administrator[];
    pagination: PaginationState;
    invitations: PendingInvitation[];
    invitationCount: number;
    environmentCount: number;
    canManage: boolean;
    isOwner: boolean;
    assignableRoles: RoleOption[];
    editor: Editor | null;
    help: HelpContent;
}>;

/** Which confirmation is open, and about whom. */
type Pending = { kind: 'remove' | 'transfer'; id: string; label: string } | null;

/**
 * IDENTITY PLATFORM › ADMINISTRATORS — the account's own team.
 *
 * NOT the organization's roster beside it (`console/directory-members`), which is everyone
 * who can sign in to the organization. These are the people who administer the ACCOUNT: its
 * projects, its environments and its bill.
 */
export default function Members({
    members,
    pagination,
    invitations,
    invitationCount,
    environmentCount,
    canManage,
    isOwner,
    assignableRoles,
    editor,
    help,
}: Props) {
    const { t: tc } = useTranslator();

    const [pending, setPending] = useState<Pending>(null);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.the_people_who_run_this_workspace_their_buil_14a4b1eb')}
            />

            <div
                className="mt-6 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {members.map((member, index) => (
                    <div
                        key={member.id}
                        className="p-4"
                        style={
                            index < members.length - 1
                                ? { borderBottom: '1px solid var(--border)' }
                                : undefined
                        }
                    >
                        <div className="flex items-center gap-3">
                            <Avatar name={member.name} />

                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium truncate">{member.name}</span>
                                    {member.isSelf && (
                                        <span
                                            className="text-xs rounded-full px-2 py-0.5"
                                            style={{
                                                background: 'var(--accent-soft)',
                                                color: 'var(--accent-strong)',
                                            }}
                                        >
                                            {tc('console.you_08b04193')}
                                        </span>
                                    )}
                                    {member.pending && <Badge tone="warn">{member.status}</Badge>}
                                </div>
                                <p
                                    className="text-sm truncate"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {member.email}
                                </p>
                            </div>

                            {member.manageable ? (
                                <>
                                    <Select
                                        className="shrink-0"
                                        value={member.role}
                                        onValueChange={(next) => {
                                            router.patch(
                                                role.url({ member: member.id }),
                                                { role: next },
                                                { preserveScroll: true },
                                            );
                                        }}
                                        options={roleSelectOptions(assignableRoles)}
                                        aria-label={tc(
                                            'console.built_in_role_for_value0_1ecbad27',
                                            { value0: member.email },
                                        )}
                                    />

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                aria-label={tc(
                                                    'console.more_actions_for_value0_fab1edb9',
                                                    { value0: member.email },
                                                )}
                                            >
                                                ⋯
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            {member.scoped && (
                                                <DropdownMenuItem
                                                    onSelect={() => openEditor(member.id)}
                                                >
                                                    {tc(
                                                        'console.manage_environment_access_9408e7c7',
                                                    )}
                                                </DropdownMenuItem>
                                            )}
                                            {isOwner && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setPending({
                                                            kind: 'transfer',
                                                            id: member.id,
                                                            label: member.email,
                                                        })
                                                    }
                                                >
                                                    {tc('console.transfer_ownership_3667f939')}
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuItem
                                                destructive
                                                onSelect={() =>
                                                    setPending({
                                                        kind: 'remove',
                                                        id: member.id,
                                                        label: member.email,
                                                    })
                                                }
                                            >
                                                {tc('console.remove_c3812fc4')}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </>
                            ) : (
                                <Badge className="shrink-0">{member.roleLabel}</Badge>
                            )}
                        </div>

                        {member.scoped && (
                            <>
                                <div
                                    className="mt-2 ml-12 text-xs"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {member.allEnvironments
                                        ? tc('console.access_to_all_environments_dbea6c6e')
                                        : tc(
                                              'console.access_to_value0_of_value1_environments_461b766a',
                                              {
                                                  value0: member.accessCount,
                                                  value1: environmentCount,
                                              },
                                          )}
                                </div>

                                {editor?.memberId === member.id && (
                                    <EnvironmentAccess
                                        key={editor.memberId}
                                        editor={editor}
                                        environmentCount={environmentCount}
                                    />
                                )}
                            </>
                        )}
                    </div>
                ))}
            </div>

            <div className="mt-4">
                <Pagination
                    pagination={pagination}
                    noun={tc('console.administrator_4194d170')}
                    pluralNoun={tc('console.administrators_3c530c2f')}
                    href={(page) => `${window.location.pathname}?page=${page}`}
                />
            </div>

            {/*
                The same list and the same form every invite surface draws. This page keeps
                its own roles and its own accept door — an account administrator sets a
                password on a signed link — but not its own controls.
            */}
            <div className="mt-6">
                <PendingInvitations invitations={invitations} total={invitationCount} />
            </div>

            {canManage && (
                <div className="mt-6">
                    <Panel
                        title={tc('console.invite_a_teammate_3e14c447')}
                        description={tc(
                            'console.they_ll_get_an_email_to_set_a_password_and_j_ddd97c98',
                        )}
                    >
                        <InviteForm href={invite.url()} roles={assignableRoles} withName />
                    </Panel>
                </div>
            )}

            <ConfirmDelete
                open={pending?.kind === 'remove'}
                onOpenChange={(open) => !open && setPending(null)}
                name={pending?.label ?? ''}
                verb="Remove"
                consequence={tc('console.they_lose_access_to_this_workspace_and_every_b6acaaa9')}
                onConfirm={() => {
                    const target = pending;
                    setPending(null);

                    if (target !== null) {
                        router.delete(remove.url({ member: target.id }));
                    }
                }}
            />

            <ConfirmDelete
                open={pending?.kind === 'transfer'}
                onOpenChange={(open) => !open && setPending(null)}
                name={pending?.label ?? ''}
                verb={tc('console.hand_this_workspace_to_2c3b6bfe')}
                actionLabel={tc('console.transfer_ownership_3667f939')}
                consequence={tc('console.they_become_the_workspace_owner_and_you_are__892fe276')}
                onConfirm={() => {
                    const target = pending;
                    setPending(null);

                    if (target !== null) {
                        router.post(
                            transferOwnership.url({ member: target.id }),
                            {},
                            { preserveScroll: true },
                        );
                    }
                }}
            />
        </>
    );
}

/**
 * Open the environment-access editor for one member.
 *
 * A PARTIAL RELOAD rather than local state: which environments exist, and which of them
 * this person reaches, are the server's answers — and the list is searchable and capped, so
 * it cannot be shipped in full with every roster page.
 */
function openEditor(memberId: string): void {
    router.get(
        window.location.pathname,
        { editing: memberId },
        { only: ['editor'], preserveScroll: true, preserveState: true },
    );
}

/** Close it again, by asking for a page with no editor on it. */
function closeEditor(): void {
    router.get(
        window.location.pathname,
        {},
        { only: ['editor'], preserveScroll: true, preserveState: true },
    );
}

function EnvironmentAccess({
    editor,
    environmentCount,
}: {
    editor: Editor;
    environmentCount: number;
}) {
    const { t: tc } = useTranslator();

    const [all, setAll] = useState(editor.all);
    const [selected, setSelected] = useState(editor.selected);
    const [term, setTerm] = useState(editor.search);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (term === editor.search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                window.location.pathname,
                { editing: editor.memberId, envSearch: term },
                { only: ['editor'], preserveScroll: true, preserveState: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, editor.search, editor.memberId]);

    const shown = editor.environments.length;

    // The search only earns its place once there is more than one screenful.
    const searchable = useMemo(() => environmentCount > shown, [environmentCount, shown]);

    return (
        <div className="mt-3 ml-12 rounded-lg border p-3" style={{ borderColor: 'var(--border)' }}>
            <Checkbox
                checked={all}
                onCheckedChange={setAll}
                label={tc('console.all_environments_including_ones_added_later_cfead322')}
            />

            <div
                className="mt-2 space-y-1.5"
                style={all ? { opacity: 0.4, pointerEvents: 'none' } : undefined}
            >
                {searchable && (
                    <Input
                        type="search"
                        disabled={all}
                        aria-label={tc('console.search_environments_by_name_5033777d')}
                        placeholder={tc('console.search_value0_environments_4661970e', {
                            value0: environmentCount,
                        })}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                )}

                {editor.environments.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {tc('console.no_environment_matches_e95a8816')}
                        {editor.search}”.
                    </p>
                ) : (
                    editor.environments.map((environment) => (
                        <Checkbox
                            key={environment.id}
                            disabled={all}
                            checked={selected.includes(environment.id)}
                            onCheckedChange={(checked) =>
                                setSelected((current) =>
                                    checked
                                        ? [...current, environment.id]
                                        : current.filter((id) => id !== environment.id),
                                )
                            }
                            label={
                                <>
                                    {environment.name}
                                    {environment.sandbox && (
                                        <span style={{ color: 'var(--warning-strong)' }}>
                                            {' '}
                                            {tc('console.sandbox_21718253')}
                                        </span>
                                    )}
                                </>
                            }
                        />
                    ))
                )}

                {editor.truncated && (
                    <p className="text-xs" style={{ color: 'var(--faint)' }}>
                        {tc('console.showing_the_first_92eb9ae1')}
                        {shown}
                        {tc('console.search_to_reach_the_rest_598edbc6')}
                    </p>
                )}

                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so this line is the only thing that can report a search that
                    narrowed to nothing — or how much of the list is on screen.
                */}
                <output className="block text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {shown}
                    {tc('console.shown_f70dce7d')}
                    {selected.length}
                    {tc('console.selected_97a2e881')}
                </output>
            </div>

            <div className="mt-3 flex gap-2">
                <Button
                    variant="primary"
                    size="sm"
                    loading={saving}
                    onClick={() => {
                        setSaving(true);
                        router.put(
                            access.url({ member: editor.memberId }),
                            { all, environmentIds: selected },
                            {
                                preserveScroll: true,
                                onFinish: () => setSaving(false),
                                onSuccess: closeEditor,
                            },
                        );
                    }}
                >
                    {tc('console.save_access_0f586dd3')}
                </Button>
                <Button size="sm" onClick={closeEditor}>
                    {tc('console.cancel_19766ed6')}
                </Button>
            </div>
        </div>
    );
}

Members.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
