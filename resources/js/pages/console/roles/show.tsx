import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    EmptyState,
    Field,
    Icon,
    Input,
    Kv,
    KvList,
    Panel,
} from '@/ui';

interface CatalogEntry {
    id: string;
    name: string;
    description: string | null;
    app: string | null;
}

interface Holder {
    /** Stable across redraws: the holder and the scope they hold it in. */
    id: string;
    name: string;
    email: string;
    /** The organization the grant is scoped to, or null for every one of them. */
    scope: string | null;
}

type Props = PageProps<{
    role: {
        id: string;
        name: string;
        description: string | null;
        app: string | null;
    };
    readOnly: boolean;
    declaredByApp: boolean;
    catalog: CatalogEntry[];
    granted: string[];
    holders: Holder[];
    indexHref: string;
    urls: {
        update: string;
        permissions: string;
        destroy: string;
    };
}>;

export default function RoleDetail({
    role,
    readOnly,
    declaredByApp,
    catalog,
    granted,
    holders,
    indexHref,
    urls,
}: Props) {
    const [confirming, setConfirming] = useState(false);

    const details = useForm({
        name: role.name,
        description: role.description ?? '',
    });

    return (
        <div className="space-y-6">
            <div>
                <Link
                    href={indexHref}
                    className="text-sm inline-flex items-center gap-1"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-3.5 h-3.5"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Roles
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{role.name}</h1>
                    {/*
                        WHY it is read-only is two different sentences, and the badge is
                        where they are told apart: the app that declares this role owns it,
                        or the environment does.
                    */}
                    {declaredByApp ? (
                        <Badge>Managed by the app</Badge>
                    ) : (
                        readOnly && <Badge>Managed for the environment</Badge>
                    )}
                    {role.app !== null ? (
                        <Badge>
                            <Icon name="clients" className="w-3 h-3" />
                            {role.app} only
                        </Badge>
                    ) : (
                        <Badge>All apps</Badge>
                    )}
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {role.id}
                </p>
            </div>

            <Panel
                title="Details"
                description={
                    readOnly
                        ? declaredByApp
                            ? "This role is declared by an application, which is its source of truth — it can't be edited here."
                            : 'This role belongs to the environment and applies across every organization in it — it applies here but is not yours to change.'
                        : undefined
                }
            >
                {readOnly ? (
                    <KvList>
                        <Kv label="Name">{role.name}</Kv>
                        <Kv label="Description">{role.description ?? '—'}</Kv>
                    </KvList>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            details.patch(urls.update, { preserveScroll: true });
                        }}
                    >
                        <Field label="Name" error={details.errors.name}>
                            <Input
                                name="name"
                                value={details.data.name}
                                onChange={(event) => details.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field label="Description" optional error={details.errors.description}>
                            <Input
                                name="description"
                                value={details.data.description}
                                onChange={(event) =>
                                    details.setData('description', event.target.value)
                                }
                            />
                        </Field>
                        <Button type="submit" variant="primary" loading={details.processing}>
                            Save changes
                        </Button>
                    </form>
                )}
            </Panel>

            <Permissions
                readOnly={readOnly}
                declaredByApp={declaredByApp}
                catalog={catalog}
                granted={granted}
                href={urls.permissions}
            />

            {/*
                WHO HOLDS IT. Nothing in the console answered this: an access review
                enumerates one organization's grants, and an environment-wide grant belongs
                to none — so the only way to learn who held a role was to open every user
                page in turn. A role whose holders you cannot see is a role you cannot
                govern.
            */}
            <Panel
                title="Who holds this"
                description={
                    holders.length === 0
                        ? undefined
                        : `${holders.length} ${holders.length === 1 ? 'grant' : 'grants'}. A grant made everywhere applies in every organization, including to people who belong to none.`
                }
                flush={holders.length > 0}
            >
                {holders.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        Nobody yet. Grant it on a person's page — inside one organization, or
                        everywhere in this environment.
                    </p>
                ) : (
                    <div>
                        {holders.map((holder, index) => (
                            <div
                                key={holder.id}
                                className="flex items-center gap-3 flex-wrap px-4 py-2.5"
                                style={
                                    index === holders.length - 1
                                        ? undefined
                                        : { borderBottom: '1px solid var(--border)' }
                                }
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium truncate">{holder.name}</p>
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {holder.email}
                                    </p>
                                </div>
                                {holder.scope === null ? (
                                    // What "Everywhere" means is in the panel description
                                    // above, not a tooltip on each row.
                                    <Badge>Everywhere</Badge>
                                ) : (
                                    <Badge>{holder.scope}</Badge>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

            {!readOnly && (
                <Panel
                    title="Delete role"
                    description="Everyone currently assigned this role loses the access it grants."
                >
                    <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                        Delete role
                    </Button>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={role.name}
                consequence="Everyone currently assigned this role loses the access it grants. This cannot be undone."
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * What this role is allowed to do.
 *
 * Each tick writes on its own — there is no Save — so the checkbox is disabled for the
 * round-trip: without that, a second click before the first lands toggles it straight
 * back, and the two requests race to decide which state the role ends in. The server takes
 * an explicit grant/revoke rather than a toggle for the same reason.
 */
function Permissions({
    readOnly,
    declaredByApp,
    catalog,
    granted,
    href,
}: {
    readOnly: boolean;
    declaredByApp: boolean;
    catalog: CatalogEntry[];
    granted: string[];
    href: string;
}) {
    const [pending, setPending] = useState<string | null>(null);

    const set = (permission: string, isGranted: boolean): void => {
        setPending(permission);

        router.post(
            href,
            { permission, granted: isGranted },
            { preserveScroll: true, onFinish: () => setPending(null) },
        );
    };

    return (
        <Panel
            title="Permissions"
            description={
                readOnly
                    ? declaredByApp
                        ? 'What this role is allowed to do. Set by the application.'
                        : 'What this role is allowed to do. Set for the whole environment.'
                    : 'What this role is allowed to do. Tick a permission to grant it; untick to revoke.'
            }
        >
            {readOnly ? (
                catalog.length === 0 ? (
                    <EmptyState
                        icon="key"
                        title="No permissions"
                        description={
                            declaredByApp
                                ? 'This role grants no permissions. The application that declares it controls what it can do.'
                                : 'This role grants no permissions you can see.'
                        }
                    />
                ) : (
                    <div className="flex flex-wrap gap-1.5">
                        {catalog.map((permission) => (
                            <Badge key={permission.id} className="mono">
                                {permission.name}
                            </Badge>
                        ))}
                    </div>
                )
            ) : (
                <>
                    <div
                        className="space-y-1.5 rounded-lg border p-3"
                        style={{
                            borderColor: 'var(--border)',
                            maxHeight: '24rem',
                            overflowY: 'auto',
                        }}
                    >
                        {catalog.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title="No permissions declared"
                                description="An app registers its catalog over the SDK; the keys it declares appear here."
                            />
                        ) : (
                            catalog.map((permission) => (
                                <Checkbox
                                    key={permission.id}
                                    checked={granted.includes(permission.id)}
                                    disabled={pending === permission.id}
                                    onCheckedChange={(checked) => set(permission.id, checked)}
                                    label={
                                        <span className="flex items-center gap-2 flex-wrap">
                                            <span className="text-sm mono">{permission.name}</span>
                                            {permission.app !== null ? (
                                                <Badge tone="info">{permission.app}</Badge>
                                            ) : (
                                                <Badge>All apps</Badge>
                                            )}
                                        </span>
                                    }
                                    hint={permission.description ?? undefined}
                                />
                            ))
                        )}
                    </div>

                    {/* Each tick writes immediately; SC 4.1.3 needs that reported. */}
                    <output aria-live="polite" className="sr-only">
                        {granted.length} of {catalog.length} permissions granted.
                    </output>
                </>
            )}
        </Panel>
    );
}

RoleDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
