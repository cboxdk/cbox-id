import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from './Button';
import { Checkbox } from './Checkbox';
import { Field } from './Field';
import { Input } from './Input';
import { Select } from './Select';

/** `App\Http\Props\Shared\RoleOptionProps` */
export interface RoleOption {
    value: string;
    label: string;
    description: string | null;
    /** Shown as the row's current value but never offered — the owner, in a roster. */
    disabled: boolean;
}

/** `App\Http\Props\Shared\ReturnAppProps` */
export interface ReturnApp {
    clientId: string;
    name: string;
    /** Where a return address may point — `https://app.example`. */
    origins: string[];
}

/** An app or custom role an invitation can carry, granted when the invitee accepts. */
export interface InviteAccessRole {
    id: string;
    name: string;
    /** "Custom roles", or the app that declared it — the heading it is listed under. */
    group: string;
    hint?: string | null;
}

export interface InviteFormProps {
    /** Where the form posts. */
    href: string;
    roles: RoleOption[];
    /** The role the form opens on — the ordinary one, never the most powerful. */
    defaultRole?: string;
    accessRoles?: InviteAccessRole[];
    /** Apps the invitee can be sent back to afterwards. Empty hides the section. */
    apps?: ReturnApp[];
    /** Ask for a display name too (the account's own team does). */
    withName?: boolean;
    /** Called after a successful send — e.g. to close the panel the form sits in. */
    onDone?: () => void;
    onCancel?: () => void;
}

/** Stable empties for the optional lists, so a default is not a new array every render. */
const NO_ACCESS_ROLES: InviteAccessRole[] = [];
const NO_APPS: ReturnApp[] = [];

/** The roles a picker offers, each with what it means underneath. */
export function roleSelectOptions(roles: RoleOption[]) {
    return roles.map((role) => ({
        value: role.value,
        label: role.label,
        hint: role.description ?? undefined,
        disabled: role.disabled,
    }));
}

/** Access roles in the groups the picker reads in: org-wide first, then app by app. */
function grouped(roles: InviteAccessRole[]): [string, InviteAccessRole[]][] {
    const groups = new Map<string, InviteAccessRole[]>();

    for (const role of roles) {
        groups.set(role.group, [...(groups.get(role.group) ?? []), role]);
    }

    return [...groups.entries()];
}

/**
 * INVITE SOMEBODY — the one form, wherever it is drawn.
 *
 * There were four: the People page, the environment console's organization page, the
 * account's administrators page, and a fourth copy inside the first. They offered different
 * roles in different orders, only one of them explained a role, and none of them could say
 * which app the person was being invited to. This is all of them now; the page decides only
 * where it posts and which roles are on offer — the server sends those, with what each means.
 *
 * "AFTER THEY ACCEPT" is optional and folded away: most invitations land the person in the
 * console, and an app that wants them back names itself and an address on one of its own
 * registered origins. The server checks the address; the hint here only says what it will
 * accept, so a typo is not a surprise.
 */
export function InviteForm({
    href,
    roles,
    defaultRole = 'member',
    accessRoles = NO_ACCESS_ROLES,
    apps = NO_APPS,
    withName = false,
    onDone,
    onCancel,
}: InviteFormProps) {
    const offered = roles.filter((role) => !role.disabled);
    const initialRole = offered.some((role) => role.value === defaultRole)
        ? defaultRole
        : (offered[0]?.value ?? '');

    const form = useForm({
        email: '',
        name: '',
        role: initialRole,
        accessRoles: [] as string[],
        client_id: '',
        return_to: '',
    });

    const [returning, setReturning] = useState(false);
    const app = apps.find((candidate) => candidate.clientId === form.data.client_id);

    return (
        <form
            className="space-y-4"
            aria-label="Invite someone"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    // An empty app sends nothing, so the server is not asked about one.
                    client_id: data.client_id === '' ? null : data.client_id,
                    return_to:
                        data.client_id === '' || data.return_to === '' ? null : data.return_to,
                    ...(withName ? {} : { name: undefined }),
                }));
                form.post(href, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        setReturning(false);
                        onDone?.();
                    },
                });
            }}
        >
            <Field label="Email address" error={form.errors.email}>
                <Input
                    name="email"
                    type="email"
                    autoComplete="off"
                    placeholder="teammate@company.com"
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                />
            </Field>

            {withName && (
                <Field label="Name" optional error={form.errors.name}>
                    <Input
                        name="name"
                        autoComplete="off"
                        placeholder="How they appear on the list"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            )}

            {/*
                ONE ROLES CONTROL. The built-in role and the app and custom roles were two
                fields with two names ("Role", "Access roles"), and people read them as two
                unrelated settings. They are one answer — what this person may do — in two
                parts: exactly one built-in role, and any number of the others.
            */}
            <fieldset className="space-y-3">
                <legend className="label">Roles</legend>

                <Field
                    label="Built-in role"
                    hint="Exactly one. It decides what they may administer."
                    error={form.errors.role}
                    className="max-w-sm"
                >
                    <Select
                        name="role"
                        value={form.data.role === '' ? undefined : form.data.role}
                        onValueChange={(role) => form.setData('role', role)}
                        options={roleSelectOptions(offered)}
                    />
                </Field>

                {accessRoles.length > 0 && (
                    <Field
                        label="App and custom roles"
                        hint="Any number, granted the moment they accept."
                        optional
                        error={form.errors.accessRoles}
                    >
                        <div className="space-y-2">
                            {grouped(accessRoles).map(([group, inGroup]) => (
                                <div key={group}>
                                    <p
                                        className="text-xs font-semibold uppercase mb-1.5"
                                        style={{
                                            color: 'var(--muted-foreground)',
                                            letterSpacing: '0.05em',
                                        }}
                                    >
                                        {group}
                                    </p>
                                    <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                                        {inGroup.map((role) => (
                                            <Checkbox
                                                key={role.id}
                                                checked={form.data.accessRoles.includes(role.id)}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'accessRoles',
                                                        checked
                                                            ? [...form.data.accessRoles, role.id]
                                                            : form.data.accessRoles.filter(
                                                                  (id) => id !== role.id,
                                                              ),
                                                    )
                                                }
                                                label={role.name}
                                                hint={role.hint ?? undefined}
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Field>
                )}
            </fieldset>

            {apps.length > 0 &&
                (returning ||
                form.errors.client_id !== undefined ||
                form.errors.return_to !== undefined ? (
                    <fieldset
                        className="rounded-lg p-3 space-y-3"
                        style={{ border: '1px solid var(--border)' }}
                    >
                        <legend className="label px-1">After they accept</legend>

                        <Field
                            label="Send them to"
                            hint="Where they land once they have joined."
                            error={form.errors.client_id}
                        >
                            <Select
                                name="client_id"
                                value={
                                    form.data.client_id === '' ? '__console' : form.data.client_id
                                }
                                onValueChange={(clientId) =>
                                    form.setData({
                                        ...form.data,
                                        client_id: clientId === '__console' ? '' : clientId,
                                        return_to: '',
                                    })
                                }
                                options={[
                                    { value: '__console', label: 'This console' },
                                    ...apps.map((candidate) => ({
                                        value: candidate.clientId,
                                        label: candidate.name,
                                    })),
                                ]}
                            />
                        </Field>

                        {app !== undefined && (
                            <Field
                                label="Return address"
                                optional
                                hint={`A page on ${app.origins.join(' or ')}. Leave empty to land in the console, with ${app.name} named on the invitation.`}
                                error={form.errors.return_to}
                            >
                                <Input
                                    name="return_to"
                                    type="url"
                                    autoComplete="off"
                                    placeholder={`${app.origins[0] ?? 'https://app.example'}/welcome`}
                                    value={form.data.return_to}
                                    onChange={(event) =>
                                        form.setData('return_to', event.target.value)
                                    }
                                />
                            </Field>
                        )}
                    </fieldset>
                ) : (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setReturning(true)}
                    >
                        Send them to an app afterwards…
                    </Button>
                ))}

            <div className="flex items-center gap-2">
                <Button type="submit" variant="primary" loading={form.processing}>
                    Send invitation
                </Button>
                {onCancel !== undefined && (
                    <Button type="button" onClick={onCancel}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}
