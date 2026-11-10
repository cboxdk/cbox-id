import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    PageHeader,
    Pagination,
    Panel,
    type PendingInvitation,
    PendingInvitations,
    type RoleOption,
} from '@/ui';
import { access, invite, remove, role, transferOwnership } from '@routes/members';

interface Administrator {
    id: string;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    roles: string[];
    roles_version: string;
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
    version: string;
    all: boolean;
    selected: string[];
    environments: { id: string; name: string; sandbox: boolean }[];
    /** More environments exist than the panel drew; the search is how you reach them. */
    truncated: boolean;
    search: string;
}

type Props = PageProps<{
    organization: string;
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
    organization,
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
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const nativeRoleNames = roleNames(assignableRoles);
    const [pending, setPending] = useState<Pending>(null);

    return (
        <>
            <PageHeader help={help} description={consoleT('console.native_access_description')} />

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
                        <div className="flex flex-wrap items-center gap-3 sm:flex-nowrap">
                            <Avatar name={member.name} />

                            <div className="min-w-40 flex-1">
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
                                            {consoleT('console.you_08b04193')}
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
                                    <NativeRoleEditor
                                        key={member.id}
                                        member={member}
                                        organization={organization}
                                        assignableRoles={assignableRoles}
                                    />

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                aria-label={consoleFinalText(
                                                    'console.more_actions_for_part1_b26d24f6',
                                                    { part1: member.email },
                                                )}
                                            >
                                                ⋯
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            {member.scoped && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        openEditor(member.id, organization)
                                                    }
                                                >
                                                    {consoleT(
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
                                                    {consoleT(
                                                        'console.transfer_ownership_3667f939',
                                                    )}
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
                                                {consoleT('console.remove_c3812fc4')}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </>
                            ) : (
                                <Badge className="shrink-0">
                                    {member.role === 'owner'
                                        ? consoleShortText('console.owner_4b1b8aa3')
                                        : member.roles
                                              .map((key) => nativeRoleNames[key] ?? key)
                                              .join(', ') || consoleT('console.native_no_access')}
                                </Badge>
                            )}
                        </div>

                        {member.scoped && (
                            <>
                                <div
                                    className="mt-2 ml-12 text-xs"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {member.allEnvironments
                                        ? consoleText('console.access_to_all_environments_dbea6c6e')
                                        : consoleFinalText(
                                              'console.access_to_part1_of_part2_environments_7c44f9ee',
                                              {
                                                  part1: member.accessCount,
                                                  part2: environmentCount,
                                              },
                                          )}
                                </div>

                                {editor?.memberId === member.id && (
                                    <EnvironmentAccess
                                        key={editor.memberId}
                                        editor={editor}
                                        organization={organization}
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
                    href={(page) =>
                        `${window.location.pathname}?organization=${encodeURIComponent(organization)}&page=${page}`
                    }
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
                        title={consoleT('console.invite_a_teammate_3e14c447')}
                        description={consoleT('console.native_invite_note')}
                    >
                        <NativeInviteForm
                            organization={organization}
                            assignableRoles={assignableRoles}
                        />
                    </Panel>
                </div>
            )}

            <ConfirmDelete
                open={pending?.kind === 'remove'}
                onOpenChange={(open) => !open && setPending(null)}
                name={pending?.label ?? ''}
                verb={consoleShortText('console.remove_c3812fc4')}
                consequence={consoleUiText('console.native_removal_note')}
                onConfirm={() => {
                    const target = pending;
                    setPending(null);

                    if (target !== null) {
                        router.delete(remove.url({ member: target.id }), {
                            data: {
                                organization,
                                roles_version: members.find((member) => member.id === target.id)
                                    ?.roles_version,
                            },
                        });
                    }
                }}
            />

            <ConfirmDelete
                open={pending?.kind === 'transfer'}
                onOpenChange={(open) => !open && setPending(null)}
                name={pending?.label ?? ''}
                verb={consoleAttributeText('console.hand_this_organization_to_1f5bb58b')}
                actionLabel={consoleUiText('console.transfer_ownership_3667f939')}
                consequence={consoleUiText(
                    'console.this_transfers_ownership_of_the_shared_organization_rol_19e09a02',
                )}
                onConfirm={() => {
                    const target = pending;
                    setPending(null);

                    if (target !== null) {
                        router.post(
                            transferOwnership.url({ member: target.id }),
                            { organization },
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
function openEditor(memberId: string, organization: string): void {
    router.get(
        window.location.pathname,
        { editing: memberId, organization },
        { only: ['editor'], preserveScroll: true, preserveState: true },
    );
}

/** Close it again, by asking for a page with no editor on it. */
function closeEditor(organization: string): void {
    router.get(
        window.location.pathname,
        { organization },
        { only: ['editor'], preserveScroll: true, preserveState: true },
    );
}

function EnvironmentAccess({
    organization,
    editor,
    environmentCount,
}: {
    organization: string;
    editor: Editor;
    environmentCount: number;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [all, setAll] = useState(editor.all);
    const [selected, setSelected] = useState(editor.selected);
    const [version, setVersion] = useState(editor.version);
    if (version !== editor.version) {
        setVersion(editor.version);
        setAll(editor.all);
        setSelected(editor.selected);
    }
    const [term, setTerm] = useState(editor.search);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (term === editor.search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                window.location.pathname,
                { editing: editor.memberId, envSearch: term, organization },
                { only: ['editor'], preserveScroll: true, preserveState: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, editor.search, editor.memberId, organization]);

    const shown = editor.environments.length;

    // The search only earns its place once there is more than one screenful.
    const searchable = useMemo(() => environmentCount > shown, [environmentCount, shown]);

    return (
        <div className="mt-3 ml-12 rounded-lg border p-3" style={{ borderColor: 'var(--border)' }}>
            <Checkbox
                checked={all}
                onCheckedChange={setAll}
                label={consoleT('console.all_environments_including_ones_added_later_cfead322')}
            />

            <div
                className="mt-2 space-y-1.5"
                style={all ? { opacity: 0.4, pointerEvents: 'none' } : undefined}
            >
                {searchable && (
                    <Input
                        type="search"
                        disabled={all}
                        aria-label={consoleT('console.search_environments_by_name_5033777d')}
                        placeholder={consoleFinalText(
                            'console.search_part1_environments_54a78318',
                            { part1: environmentCount },
                        )}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                )}

                {editor.environments.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {consoleRich('console.no_environment_matches_part1_7d06ed36', {
                            part1: editor.search,
                        })}
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
                                            {consoleRich('console._part1_sandbox_999a5fa7', {
                                                part1: ' ',
                                            })}
                                        </span>
                                    )}
                                </>
                            }
                        />
                    ))
                )}

                {editor.truncated && (
                    <p className="text-xs" style={{ color: 'var(--faint)' }}>
                        {consoleRich(
                            'console.showing_the_first_part1_search_to_reach_the_rest_9e61b9cb',
                            { part1: shown },
                        )}
                    </p>
                )}

                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so this line is the only thing that can report a search that
                    narrowed to nothing — or how much of the list is on screen.
                */}
                <output className="block text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich('console._part1_shown_part2_selected_828aa594', {
                        part1: shown,
                        part2: selected.length,
                    })}
                </output>
            </div>

            {Object.entries(errors).map(([key, value]) => (
                <p key={key} role="alert">
                    {value}
                </p>
            ))}
            <div className="mt-3 flex gap-2">
                <Button
                    variant="primary"
                    size="sm"
                    loading={saving}
                    onClick={() => {
                        setSaving(true);
                        setErrors({});
                        router.put(
                            access.url({ member: editor.memberId }),
                            {
                                all,
                                organization,
                                environmentIds: selected,
                                environment_access_version: editor.version,
                            },
                            {
                                preserveScroll: true,
                                onError: setErrors,
                                onFinish: () => setSaving(false),
                                onSuccess: () => closeEditor(organization),
                            },
                        );
                    }}
                >
                    {consoleT('console.save_access_0f586dd3')}
                </Button>
                <Button size="sm" onClick={() => closeEditor(organization)}>
                    {consoleT('console.cancel_19766ed6')}
                </Button>
            </div>
        </div>
    );
}

Members.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;

function roleNames(roles: RoleOption[]): Record<string, string> {
    return Object.fromEntries(
        roles.filter((option) => !option.disabled).map((option) => [option.value, option.label]),
    );
}

function NativeRoleEditor({
    organization,
    member,
    assignableRoles,
}: {
    organization: string;
    member: Administrator;
    assignableRoles: RoleOption[];
}) {
    const nativeRoleNames = roleNames(assignableRoles);
    const { t: consoleT } = useConsoleTranslator();
    const [selected, setSelected] = useState(member.roles);
    const [version, setVersion] = useState(member.roles_version);
    if (version !== member.roles_version) {
        setVersion(member.roles_version);
        setSelected(member.roles);
    }
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    return (
        <details className="min-w-0 max-w-full rounded border p-2">
            <summary className="cursor-pointer">
                {member.roles.map((key) => nativeRoleNames[key] ?? key).join(', ') ||
                    consoleT('console.native_no_access')}
            </summary>
            <fieldset className="flex flex-wrap gap-3 py-3" disabled={saving}>
                <legend className="sr-only">
                    {consoleT('console.native_product_roles')} — {member.email}
                </legend>
                {Object.entries(nativeRoleNames).map(([key, label]) => (
                    <label key={key} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={selected.includes(key)}
                            onChange={(event) =>
                                setSelected(
                                    event.target.checked
                                        ? [...selected, key]
                                        : selected.filter((value) => value !== key),
                                )
                            }
                        />
                        {label}
                    </label>
                ))}
            </fieldset>
            <Button
                size="sm"
                disabled={saving || selected.length === 0}
                onClick={() => {
                    setSaving(true);
                    setErrors({});
                    router.patch(
                        role.url({ member: member.id }),
                        { organization, roles: selected, roles_version: member.roles_version },
                        {
                            preserveScroll: true,
                            onError: setErrors,
                            onFinish: () => setSaving(false),
                        },
                    );
                }}
            >
                {consoleT('console.save_1509f561')}
            </Button>
            {Object.entries(errors).map(([key, value]) => (
                <p key={key} role="alert">
                    {value}
                </p>
            ))}
        </details>
    );
}

function NativeInviteForm({
    assignableRoles,
    organization,
}: {
    assignableRoles: RoleOption[];
    organization: string;
}) {
    const nativeRoleNames = roleNames(assignableRoles);
    const { t: consoleT } = useConsoleTranslator();
    const [email, setEmail] = useState('');
    const [selected, setSelected] = useState<string[]>(
        assignableRoles.some((option) => option.value === 'viewer' && !option.disabled)
            ? ['viewer']
            : [],
    );
    const [sending, setSending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                setSending(true);
                setErrors({});
                router.post(
                    invite.url(),
                    { organization, email, roles: selected },
                    {
                        preserveScroll: true,
                        onError: setErrors,
                        onSuccess: () => setEmail(''),
                        onFinish: () => setSending(false),
                    },
                );
            }}
        >
            <label className="block space-y-2">
                <span>{consoleT('console.email_address_f2488fd4')}</span>
                <Input
                    type="email"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    required
                />
            </label>
            <fieldset className="flex flex-wrap gap-3" disabled={sending}>
                <legend className="mb-2">{consoleT('console.native_product_roles')}</legend>
                {Object.entries(nativeRoleNames).map(([key, label]) => (
                    <label key={key} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={selected.includes(key)}
                            onChange={(event) =>
                                setSelected(
                                    event.target.checked
                                        ? [...selected, key]
                                        : selected.filter((value) => value !== key),
                                )
                            }
                        />
                        {label}
                    </label>
                ))}
            </fieldset>
            {Object.entries(errors).map(([key, value]) => (
                <p key={key} role="alert">
                    {value}
                </p>
            ))}
            <Button type="submit" disabled={sending || selected.length === 0}>
                {consoleT(
                    sending ? 'console.sending_b8ed5279' : 'console.send_invitation_dba4038b',
                )}
            </Button>
        </form>
    );
}
