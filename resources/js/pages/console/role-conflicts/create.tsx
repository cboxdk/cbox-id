import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Button,
    Checkbox,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
} from '@/ui';

interface RoleOption {
    id: string;
    name: string;
}

type Props = PageProps<{
    roles: RoleOption[];
    roleSearch: string;
    pickerLimit: number;
    holdsEnvironment: boolean;
    organizationChosen: boolean;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateRoleConflict({
    roles,
    roleSearch,
    pickerLimit,
    holdsEnvironment,
    organizationChosen,
    indexHref,
    storeHref,
}: Props) {
    const form = useForm({
        name: '',
        description: '',
        roles: [] as string[],
        environmentWide: false,
    });

    const [term, setTerm] = useState(roleSearch);

    /*
     * THE PICKER IS SERVER-SIDE, because the catalogue is not bounded by anyone on this
     * page — an environment can hold thousands of roles, and shipping all of them to draw
     * a checkbox list is the cost this page used to pay on every keystroke.
     *
     * The chosen ids travel with the search so the server can keep them in the result:
     * otherwise typing into the filter hides a ticked box, and the person submits a
     * selection they can no longer see.
     */
    useEffect(() => {
        if (term === roleSearch) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                window.location.pathname,
                { roleSearch: term, roles: form.data.roles },
                { preserveState: true, preserveScroll: true, replace: true, only: ['roles'] },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, roleSearch, form.data.roles]);

    const toggleRole = (id: string, checked: boolean): void => {
        form.setData(
            'roles',
            checked ? [...form.data.roles, id] : form.data.roles.filter((held) => held !== id),
        );
    };

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
                Role conflicts
            </Link>

            <div className="mt-2">
                <PageHeader description="Name two or more roles that must never sit with the same person. New grants that would break the rule are blocked from the moment you save it." />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '40rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <div className="space-y-4">
                        <Field
                            label="Rule name"
                            hint="Name the conflict the way an auditor would — “Raise payment vs. approve payment”."
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder="Raise payment vs. approve payment"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field label="Description" optional error={form.errors.description}>
                            <Input
                                name="description"
                                placeholder="Nobody may both raise and approve the same payment."
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>

                        {holdsEnvironment && (
                            <div>
                                <Checkbox
                                    checked={form.data.environmentWide}
                                    onCheckedChange={(checked) =>
                                        form.setData('environmentWide', checked)
                                    }
                                    label="Apply it to every organization in this environment"
                                    hint="An environment-wide rule binds every tenant here, and is not theirs to switch off."
                                />
                                {form.errors.environmentWide !== undefined && (
                                    <p className="field-error" role="alert">
                                        {form.errors.environmentWide}
                                    </p>
                                )}
                            </div>
                        )}

                        {needsOrganization && (
                            <output
                                className="block text-sm"
                                style={{ color: 'var(--warning-strong)' }}
                            >
                                Choose an organization in the console header, or write the rule for
                                the whole environment.
                            </output>
                        )}
                    </div>
                </Panel>

                <Panel
                    title="Conflicting roles"
                    description="At least two — a set of one conflicts with nothing and would block no grant at all."
                >
                    <div className="space-y-3">
                        <Input
                            type="search"
                            aria-label="Search roles"
                            placeholder="Search roles by name"
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                        />
                        <output className="sr-only">
                            {roles.length} {roles.length === 1 ? 'role' : 'roles'} shown.
                        </output>

                        <div
                            className="space-y-1.5 rounded-lg border p-3"
                            style={{
                                borderColor: 'var(--border)',
                                maxHeight: '18rem',
                                overflowY: 'auto',
                            }}
                        >
                            {roles.length === 0 ? (
                                <EmptyState
                                    icon="shield"
                                    title={
                                        term === ''
                                            ? 'No roles to combine yet'
                                            : `No role matches “${term}”`
                                    }
                                    description={
                                        term === ''
                                            ? 'A conflict is written over roles, so there is nothing to write one about yet. Define the roles first.'
                                            : 'Try a different search — roles already ticked stay in this list whatever you type.'
                                    }
                                />
                            ) : (
                                roles.map((role) => (
                                    <Checkbox
                                        key={role.id}
                                        checked={form.data.roles.includes(role.id)}
                                        onCheckedChange={(checked) => toggleRole(role.id, checked)}
                                        label={role.name}
                                    />
                                ))
                            )}
                        </div>

                        {roles.length === pickerLimit && (
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                Showing the first {pickerLimit}. Search to reach the rest — anything
                                you have already ticked stays on the list.
                            </p>
                        )}

                        {form.errors.roles !== undefined && (
                            <p className="field-error" role="alert">
                                {form.errors.roles}
                            </p>
                        )}
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Define rule
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateRoleConflict.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
