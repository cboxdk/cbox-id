import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
    Pill,
    Select,
} from '@/ui';

/** `App\Http\Props\Console\ApiScopeProps` */
interface ScopeRow {
    id: string;
    key: string;
    description: string | null;
    tenantRequestable: boolean;
    updateHref: string;
    destroyHref: string;
}

/**
 * "No linked app", as a value the picker can hold: an empty string is not a value a
 * listbox option may carry, so the trigger drew its placeholder instead of the choice.
 */
const NOBODY = 'none';

type Props = PageProps<{
    api: {
        id: string;
        name: string;
        identifier: string;
        owner: string;
        environmentOwned: boolean;
        clientId: string;
    };
    scopes: ScopeRow[];
    apps: { value: string; label: string }[];
    indexHref: string;
    urls: { update: string; scopes: string; destroy: string };
}>;

export default function ApiDetail({ api, scopes, apps, indexHref, urls }: Props) {
    const [deleting, setDeleting] = useState(false);
    const [removing, setRemoving] = useState<ScopeRow | null>(null);

    const details = useForm({ name: api.name, clientId: api.clientId });
    const adding = useForm({ key: '', description: '', tenantRequestable: true });

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
                    APIs
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title" style={{ overflowWrap: 'anywhere' }}>
                        {api.name}
                    </h1>
                    <Badge>{api.owner}</Badge>
                </div>
                <div className="mt-1 flex items-start gap-2">
                    <code
                        className="mono text-sm"
                        style={{ color: 'var(--muted-foreground)', overflowWrap: 'anywhere' }}
                    >
                        {api.identifier}
                    </code>
                    <CopyButton value={api.identifier} />
                </div>
                <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                    The audience its tokens name. Fixed — every token already issued carries it.
                </p>
            </div>

            <Panel title="Details">
                <form
                    className="space-y-4"
                    style={{ maxWidth: '36rem' }}
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
                    <Field
                        label="Roles and permissions from"
                        optional
                        hint={`The app whose declared roles and permissions tokens for this API carry. Only apps owned by ${api.environmentOwned ? 'this environment' : api.owner} can be chosen.`}
                        error={details.errors.clientId}
                    >
                        <Select
                            name="clientId"
                            value={details.data.clientId === '' ? NOBODY : details.data.clientId}
                            onValueChange={(clientId) =>
                                details.setData('clientId', clientId === NOBODY ? '' : clientId)
                            }
                            options={[
                                { value: NOBODY, label: 'The app that asks' },
                                ...apps.map((app) => ({ value: app.value, label: app.label })),
                            ]}
                        />
                    </Field>
                    <Button type="submit" size="sm" variant="primary" loading={details.processing}>
                        Save changes
                    </Button>
                </form>
            </Panel>

            <Panel
                title="Scopes"
                description={
                    api.environmentOwned
                        ? 'What an app may be allowed to do at this API. Your own apps may hold any of them; an app an organization registered only the ones marked for organizations.'
                        : `What an app may be allowed to do at this API. Only ${api.owner}'s own apps may hold them.`
                }
            >
                {scopes.length === 0 ? (
                    <EmptyState
                        icon="key"
                        title="No scopes yet"
                        description="Add the scopes this API understands. Until it owns one, no token is ever audienced to it."
                    />
                ) : (
                    <ul className="space-y-2">
                        {scopes.map((scope) => (
                            <ScopeItem
                                key={scope.id}
                                scope={scope}
                                environmentOwned={api.environmentOwned}
                                onRemove={() => setRemoving(scope)}
                            />
                        ))}
                    </ul>
                )}

                <form
                    className="mt-5 pt-5 space-y-3"
                    style={{ borderTop: '1px solid var(--border)' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        adding.post(urls.scopes, {
                            preserveScroll: true,
                            onSuccess: () => adding.reset(),
                        });
                    }}
                >
                    <p className="text-sm font-medium">Add a scope</p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            label="Key"
                            hint="What apps ask for, such as invoices:read. Unique in this environment."
                            error={adding.errors.key}
                        >
                            <Input
                                name="key"
                                className="mono"
                                spellCheck={false}
                                autoCapitalize="off"
                                placeholder="invoices:read"
                                value={adding.data.key}
                                onChange={(event) => adding.setData('key', event.target.value)}
                            />
                        </Field>
                        <Field label="Description" optional error={adding.errors.description}>
                            <Input
                                name="description"
                                placeholder="Read invoices"
                                value={adding.data.description}
                                onChange={(event) =>
                                    adding.setData('description', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                    {api.environmentOwned && (
                        <Checkbox
                            checked={adding.data.tenantRequestable}
                            onCheckedChange={(checked) =>
                                adding.setData('tenantRequestable', checked)
                            }
                            label="Organizations' apps may request this"
                            hint="Apps registered by the organizations in this environment may be given it. Leave it off for a scope only your own apps should hold."
                        />
                    )}
                    <Button type="submit" size="sm" variant="primary" loading={adding.processing}>
                        Add scope
                    </Button>
                </form>
            </Panel>

            <Panel
                title="Delete API"
                description="Its scopes go with it. Apps that held them keep them as typed scopes that no longer reach this API, and tokens already issued keep working until they expire."
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    Delete API
                </Button>
            </Panel>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.key ?? ''}
                verb="Remove"
                title={`Remove the scope ${removing?.key ?? ''}?`}
                actionLabel="Remove scope"
                consequence="Apps that hold it keep it as a typed scope, which no longer reaches this API — tokens stop carrying it for this audience."
                onConfirm={() => {
                    const href = removing?.destroyHref;
                    setRemoving(null);

                    if (href !== undefined) {
                        router.delete(href, { preserveScroll: true });
                    }
                }}
            />

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={api.name}
                consequence="Tokens stop being issued for this audience. This cannot be undone."
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * One scope, editable in place: its description and — on the environment's own API —
 * whether organizations' apps may request it. The key is fixed; apps already hold it.
 */
function ScopeItem({
    scope,
    environmentOwned,
    onRemove,
}: {
    scope: ScopeRow;
    environmentOwned: boolean;
    onRemove: () => void;
}) {
    const form = useForm({
        description: scope.description ?? '',
        tenantRequestable: scope.tenantRequestable,
    });

    const changed =
        form.data.description !== (scope.description ?? '') ||
        form.data.tenantRequestable !== scope.tenantRequestable;

    return (
        <li className="rounded-lg p-3" style={{ border: '1px solid var(--border)' }}>
            <form
                className="space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(scope.updateHref, { preserveScroll: true });
                }}
            >
                <div className="flex items-center gap-2 flex-wrap">
                    <code className="mono text-sm font-medium" style={{ overflowWrap: 'anywhere' }}>
                        {scope.key}
                    </code>
                    {environmentOwned &&
                        (scope.tenantRequestable ? (
                            <Pill tone="info">Organizations' apps may request</Pill>
                        ) : (
                            <Pill>Your apps only</Pill>
                        ))}
                </div>
                <Field label="Description" optional error={form.errors.description}>
                    <Input
                        name={`description-${scope.id}`}
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                    />
                </Field>
                {environmentOwned && (
                    <Checkbox
                        checked={form.data.tenantRequestable}
                        onCheckedChange={(checked) => form.setData('tenantRequestable', checked)}
                        label="Organizations' apps may request this"
                    />
                )}
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        variant="primary"
                        disabled={!changed}
                        loading={form.processing}
                    >
                        Save
                    </Button>
                    <Button type="button" size="sm" variant="ghost" onClick={onRemove}>
                        Remove
                    </Button>
                </div>
            </form>
        </li>
    );
}

ApiDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
