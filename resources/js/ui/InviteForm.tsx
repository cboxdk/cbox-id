import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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

    const builtInRole = (
        <Field
            label={consoleT('console.built_in_role_9f680a16')}
            hint={consoleT('console.exactly_one_it_decides_what_they_may_administer_f417665e')}
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
    );
    const app = apps.find((candidate) => candidate.clientId === form.data.client_id);

    return (
        <form
            className="space-y-4"
            aria-label={consoleT('console.invite_someone_9b7639e8')}
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
            <Field label={consoleT('console.email_address_f2488fd4')} error={form.errors.email}>
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
                <Field label={consoleT('console.name_dcd1d522')} optional error={form.errors.name}>
                    <Input
                        name="name"
                        autoComplete="off"
                        placeholder={consoleUiText('console.how_they_appear_on_the_list_da4d8376')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            )}

            {/*
                ONE ROLES CONTROL. The built-in role and the app and custom roles were two
                fields with two names ("Role", "Access roles"), and people read them as two
                unrelated settings. They are one answer — what this person may do — in two
                parts: exactly one built-in role, and any number of the others. With no app
                or custom roles to offer, the built-in role is the whole answer, and a
                "Roles" heading over a single field would only repeat it.
            */}
            {accessRoles.length > 0 ? (
                <fieldset className="space-y-3">
                    <legend className="label">{consoleT('console.roles_c2533705')}</legend>
                    {builtInRole}
                    <Field
                        label={consoleT('console.app_and_custom_roles_ecb0207e')}
                        hint={consoleT(
                            'console.any_number_granted_the_moment_they_accept_0cc4bd0c',
                        )}
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
                </fieldset>
            ) : (
                builtInRole
            )}

            {apps.length > 0 &&
                (returning ||
                form.errors.client_id !== undefined ||
                form.errors.return_to !== undefined ? (
                    <fieldset
                        className="rounded-lg p-3 space-y-3"
                        style={{ border: '1px solid var(--border)' }}
                    >
                        <legend className="label px-1">
                            {consoleT('console.after_they_accept_ddda6664')}
                        </legend>

                        <Field
                            label={consoleT('console.send_them_to_d21b2a72')}
                            hint={consoleT(
                                'console.where_they_land_once_they_have_joined_b493a7da',
                            )}
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
                                    {
                                        value: '__console',
                                        label: consoleText('console.this_console_32a05921'),
                                    },
                                    ...apps.map((candidate) => ({
                                        value: candidate.clientId,
                                        label: candidate.name,
                                    })),
                                ]}
                            />
                        </Field>

                        {app !== undefined && (
                            <Field
                                label={consoleT('console.return_address_6659c2a3')}
                                optional
                                hint={consoleFinalText(
                                    'console.a_page_on_part1_leave_empty_to_land_in_the_console_with_1e303bfc',
                                    { part1: app.origins.join(' or '), part2: app.name },
                                )}
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
                        {consoleT('console.send_them_to_an_app_afterwards_7f7ac529')}
                    </Button>
                ))}

            <div className="flex items-center gap-2">
                <Button type="submit" variant="primary" loading={form.processing}>
                    {consoleT('console.send_invitation_dba4038b')}
                </Button>
                {onCancel !== undefined && (
                    <Button type="button" onClick={onCancel}>
                        {consoleT('console.cancel_19766ed6')}
                    </Button>
                )}
            </div>
        </form>
    );
}
