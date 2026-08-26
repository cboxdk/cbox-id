import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
    Select,
} from '@/ui';

interface CatalogEntry {
    id: string;
    name: string;
    description: string | null;
    /** The app that declares it, or null when it applies in every app. */
    app: string | null;
}

type Props = PageProps<{
    catalog: CatalogEntry[];
    apps: { id: string; name: string }[];
    holdsEnvironment: boolean;
    organizationChosen: boolean;
    permissionsHref: string | null;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateRole({
    catalog,
    apps,
    holdsEnvironment,
    organizationChosen,
    permissionsHref,
    indexHref,
    storeHref,
}: Props) {
    const form = useForm({
        name: '',
        description: '',
        /* Empty = every app. A role scoped to one app carries its permissions only into
           that app's tokens. */
        app: '',
        environmentWide: false,
        permissions: [] as string[],
    });

    const toggle = (id: string, checked: boolean): void => {
        form.setData(
            'permissions',
            checked
                ? [...form.data.permissions, id]
                : form.data.permissions.filter((held) => held !== id),
        );
    };

    /*
     * The organization is NOT a field, and never was on either plane — the console chrome
     * owns that choice. What the environment plane's form encoded implicitly is the one
     * thing the scope cannot: it always wrote an ENVIRONMENT-wide role, reusable by every
     * tenant. That is a different KIND of role, not a different organization, so it
     * survives as an explicit choice — offered, and accepted, only where an administrator
     * holds the environment.
     */
    const needsOrganization = !organizationChosen && !form.data.environmentWide;

    return (
        <>
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

            {/*
                Wrapped rather than given a margin of its own: the heading comes from the
                controller's title through <PageHeader>, so the tab and the h1 cannot
                disagree, and the offset belongs to the back-link above it.
            */}
            <div className="mt-2">
                <PageHeader description="A bundle of permissions you assign to people; each app honours the roles it understands." />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <div className="space-y-4">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="Manager"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field label="Description" optional error={form.errors.description}>
                            <Input
                                name="description"
                                placeholder="Team leads across the organization"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="Applies to"
                            hint="An app-scoped role is stamped only into that app's tokens."
                            error={form.errors.app}
                        >
                            <Select
                                name="app"
                                value={form.data.app}
                                onValueChange={(app) => form.setData('app', app)}
                                options={[
                                    { value: '', label: 'All apps' },
                                    ...apps.map((app) => ({
                                        value: app.id,
                                        label: `${app.name} only`,
                                    })),
                                ]}
                            />
                        </Field>

                        {holdsEnvironment && (
                            <div>
                                <Checkbox
                                    checked={form.data.environmentWide}
                                    onCheckedChange={(checked) =>
                                        form.setData('environmentWide', checked)
                                    }
                                    label="Define it for every organization in this environment"
                                    hint="An environment-wide role can be assigned inside any organization here and is not theirs to change."
                                />
                                {form.errors.environmentWide !== undefined && (
                                    <p className="field-error" role="alert">
                                        {form.errors.environmentWide}
                                    </p>
                                )}
                            </div>
                        )}

                        {needsOrganization && (
                            // Nothing is wrong with this administrator: they simply have
                            // not said which organization the role is for.
                            <output className="block text-sm" style={{ color: 'var(--warning-strong)' }}>
                                Choose an organization in the console header, or define the role for
                                the whole environment.
                            </output>
                        )}
                    </div>
                </Panel>

                <Panel
                    title="Permissions"
                    description="Optional — a role is worth creating before its keys exist, and you can compose it later."
                >
                    <div
                        className="space-y-1.5 rounded-lg border p-3"
                        style={{ borderColor: 'var(--border)', maxHeight: '18rem', overflowY: 'auto' }}
                    >
                        {catalog.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title="No permissions declared"
                                description={
                                    <>
                                        An app registers its catalog over the SDK.{' '}
                                        {permissionsHref !== null && (
                                            <>
                                                You can also{' '}
                                                <Link
                                                    href={permissionsHref}
                                                    style={{ color: 'var(--accent-strong)' }}
                                                >
                                                    add permissions manually
                                                </Link>
                                                .{' '}
                                            </>
                                        )}
                                        You can create the role now and compose it once the keys
                                        arrive.
                                    </>
                                }
                            />
                        ) : (
                            catalog.map((permission) => (
                                <Checkbox
                                    key={permission.id}
                                    checked={form.data.permissions.includes(permission.id)}
                                    onCheckedChange={(checked) => toggle(permission.id, checked)}
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
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Create role
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateRole.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
