import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { relativeTime } from '@/lib/time';
import type { PageProps, Pagination as PaginationState } from '@/types';
import {
    Avatar,
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    Dialog,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    Field,
    Input,
    PageHeader,
    Pagination,
    Panel,
    Select,
} from '@/ui';
import { access, invite, remove, role, transferOwnership } from '@routes/members';
import { resend, revoke } from '@routes/members/invitations';

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

interface PendingInvitation {
    id: string;
    email: string;
    roleLabel: string;
    /** ISO both ways — "expires in 3 days" computed on the server is wrong the moment the
     *  page sits open, and this one sits open. */
    invitedAt: string | null;
    expiresAt: string;
    expired: boolean;
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
    assignableRoles: { value: string; label: string }[];
    editor: Editor | null;
}>;

/** Which confirmation is open, and about whom. */
type Pending = { kind: 'remove' | 'transfer' | 'withdraw'; id: string; label: string } | null;

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
}: Props) {
    const [pending, setPending] = useState<Pending>(null);

    const form = useForm({
        email: '',
        name: '',
        role: assignableRoles[0]?.value ?? 'developer',
    });

    return (
        <>
            <PageHeader description="People who can administer this account, their roles, and which environments they reach." />

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
                                            You
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
                                        options={assignableRoles.map((option) => ({
                                            value: option.value,
                                            label: option.label,
                                        }))}
                                        aria-label={`Role for ${member.email}`}
                                    />

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                aria-label={`More actions for ${member.email}`}
                                            >
                                                ⋯
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            {member.scoped && (
                                                <DropdownMenuItem
                                                    onSelect={() => openEditor(member.id)}
                                                >
                                                    Manage environment access
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
                                                    Transfer ownership
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
                                                Remove
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
                                        ? 'Access to all environments'
                                        : `Access to ${member.accessCount} of ${environmentCount} environments`}
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
                    noun="administrator"
                    href={(page) => `${window.location.pathname}?page=${page}`}
                />
            </div>

            {invitations.length > 0 && (
                <div className="mt-6">
                    <Panel
                        title="Invited, not joined yet"
                        description={
                            <>
                                These links work until they expire or you withdraw them.
                                {invitationCount > invitations.length && (
                                    <>
                                        {' '}
                                        Showing the {invitations.length} most recent of{' '}
                                        {invitationCount}.
                                    </>
                                )}
                            </>
                        }
                    >
                        <div className="flex flex-col gap-2">
                            {invitations.map((invitation) => (
                                <div
                                    key={invitation.id}
                                    className="flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2"
                                    style={{ borderColor: 'var(--border)' }}
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm truncate">
                                            {invitation.email}{' '}
                                            <Badge className="ml-1">{invitation.roleLabel}</Badge>
                                        </p>
                                        <p
                                            className="text-xs truncate"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            invited{' '}
                                            {invitation.invitedAt === null
                                                ? 'recently'
                                                : relativeTime(invitation.invitedAt)}{' '}
                                            ·{' '}
                                            {invitation.expired ? (
                                                <span style={{ color: 'var(--destructive)' }}>
                                                    expired {relativeTime(invitation.expiresAt)}
                                                </span>
                                            ) : (
                                                <>expires {relativeTime(invitation.expiresAt)}</>
                                            )}
                                        </p>
                                    </div>

                                    {canManage && (
                                        <>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                aria-label={`Send the invitation to ${invitation.email} again`}
                                                onClick={() =>
                                                    router.post(
                                                        resend.url({ invitation: invitation.id }),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Send again
                                            </Button>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                style={{ color: 'var(--destructive)' }}
                                                aria-label={`Withdraw the invitation to ${invitation.email}`}
                                                onClick={() =>
                                                    setPending({
                                                        kind: 'withdraw',
                                                        id: invitation.id,
                                                        label: invitation.email,
                                                    })
                                                }
                                            >
                                                Withdraw
                                            </Button>
                                        </>
                                    )}
                                </div>
                            ))}
                        </div>
                    </Panel>
                </div>
            )}

            {canManage && (
                <div className="mt-6">
                    <Panel
                        title="Invite a teammate"
                        description="They'll get an email to set a password and join this account."
                    >
                        <form
                            className="grid sm:grid-cols-[1fr_1fr_auto_auto] gap-2 items-start"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(invite.url(), {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('email', 'name'),
                                });
                            }}
                        >
                            {/*
                                The labels are screen-reader-only: this is one row of three
                                controls whose placeholders say what they are, and a visible
                                label above each would double the height of a form that is
                                deliberately one line.
                            */}
                            <Field
                                label={<span className="sr-only">Teammate email</span>}
                                error={form.errors.email}
                            >
                                <Input
                                    name="email"
                                    type="email"
                                    autoComplete="off"
                                    placeholder="teammate@yourco.example"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                />
                            </Field>

                            <Field
                                label={<span className="sr-only">Teammate name</span>}
                                error={form.errors.name}
                            >
                                <Input
                                    name="name"
                                    autoComplete="off"
                                    placeholder="Name (optional)"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>

                            <Field
                                label={<span className="sr-only">Role</span>}
                                error={form.errors.role}
                            >
                                <Select
                                    value={form.data.role}
                                    onValueChange={(next) => form.setData('role', next)}
                                    options={assignableRoles.map((option) => ({
                                        value: option.value,
                                        label: option.label,
                                    }))}
                                    aria-label="Role"
                                />
                            </Field>

                            <Button
                                type="submit"
                                variant="primary"
                                className="shrink-0"
                                loading={form.processing}
                            >
                                Send invite
                            </Button>
                        </form>
                    </Panel>
                </div>
            )}

            <ConfirmDelete
                open={pending?.kind === 'remove'}
                onOpenChange={(open) => !open && setPending(null)}
                name={pending?.label ?? ''}
                verb="Remove"
                consequence="They lose access to this account and every environment under it immediately."
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
                verb="Hand this account to"
                consequence="They become the account owner and you are demoted to admin. Only the new owner can hand it back."
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

            {/*
                A plain dialog rather than a type-to-confirm: withdrawing an invitation
                destroys nothing a person owns, and it is undone by inviting them again.
            */}
            <Dialog
                open={pending?.kind === 'withdraw'}
                onOpenChange={(open) => !open && setPending(null)}
                title={`Withdraw the invitation to ${pending?.label ?? ''}?`}
                description="The link they were sent stops working immediately. You can invite them again afterwards."
                footer={
                    <>
                        <Button onClick={() => setPending(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const target = pending;
                                setPending(null);

                                if (target !== null) {
                                    router.delete(revoke.url({ invitation: target.id }));
                                }
                            }}
                        >
                            Withdraw
                        </Button>
                    </>
                }
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
                label="All environments (including ones added later)"
            />

            <div
                className="mt-2 space-y-1.5"
                style={all ? { opacity: 0.4, pointerEvents: 'none' } : undefined}
            >
                {searchable && (
                    <Input
                        type="search"
                        disabled={all}
                        aria-label="Search environments by name"
                        placeholder={`Search ${environmentCount} environments`}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                )}

                {editor.environments.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        No environment matches “{editor.search}”.
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
                                            · sandbox
                                        </span>
                                    )}
                                </>
                            }
                        />
                    ))
                )}

                {editor.truncated && (
                    <p className="text-xs" style={{ color: 'var(--faint)' }}>
                        Showing the first {shown}. Search to reach the rest.
                    </p>
                )}

                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so this line is the only thing that can report a search that
                    narrowed to nothing — or how much of the list is on screen.
                */}
                <output className="block text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {shown} shown · {selected.length} selected
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
                    Save access
                </Button>
                <Button size="sm" onClick={closeEditor}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}

Members.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
