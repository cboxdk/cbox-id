import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Button,
    Dialog,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Pill,
    Select,
    Table,
    Td,
    Th,
} from '@/ui';

interface OrganizationRow {
    id: string;
    name: string;
    slug: string;
    type: string;
    status: string;
    active: boolean;
    parentId: string | null;
    depth: number;
    members: number;
    href: string;
    toggleHref: string;
    reparentHref: string;
}

interface OrganizationOption {
    id: string;
    name: string;
}

type Props = PageProps<{
    organizations: OrganizationRow[];
    all: OrganizationOption[];
    search: string;
    types: { value: string; label: string }[];
    storeHref: string;
}>;

/*
 * Radix reserves the empty string: an item with `value=""` throws, because that is the
 * value the primitive uses to clear a selection. So "no parent" travels as a sentinel and
 * is translated back to an empty field on the way to the server, which reads `''` as null.
 */
const TOP_LEVEL = '__top_level__';

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

function plural(count: number, noun: string): string {
    return `${count} ${count === 1 ? noun : `${noun}s`}`;
}

export default function Organizations({
    organizations,
    all,
    search,
    types,
    storeHref,
}: Props) {
    const [term, setTerm] = useState(search);
    const [creating, setCreating] = useState(false);
    const [confirming, setConfirming] = useState<OrganizationRow | null>(null);

    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    return (
        <>
            <PageHeader
                description="Every tenant in the target environment — the management tree of resellers, customers and sub-units."
                actions={
                    <Button
                        variant="primary"
                        aria-expanded={creating}
                        onClick={() => setCreating((open) => !open)}
                    >
                        <Icon name="plus" className="w-4 h-4" />
                        New organization
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name or slug"
                    aria-label="Search organizations"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the tree is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that reports a search that
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {plural(organizations.length, 'organization')} found.
                </output>
            </div>

            {creating && (
                <CreateOrganization
                    href={storeHref}
                    types={types}
                    parents={all}
                    onDone={() => setCreating(false)}
                />
            )}

            {organizations.length === 0 ? (
                <div className="mt-8">
                    {search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={`No organizations match “${search}”`}
                            description="Try part of the tenant's name, or its slug."
                        />
                    ) : (
                        <EmptyState
                            icon="layers"
                            title="No organizations in this environment"
                            description="A tenant is where users, sign-in methods and roles live. Create one above, or bootstrap the plane with its first organization and admin from the Environments screen."
                        />
                    )}
                </div>
            ) : (
                <div className="cbx-panel overflow-hidden mt-8">
                    <div className="overflow-x-auto">
                        <Table caption="Tenants in the targeted environment, as the management tree.">
                            <thead>
                                <tr>
                                    <Th>Organization</Th>
                                    <Th>Type</Th>
                                    <Th className="text-right">Members</Th>
                                    <Th>Parent</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {organizations.map((organization) => (
                                    <tr key={organization.id}>
                                        <Td>
                                            <div
                                                className="flex items-center"
                                                style={{
                                                    paddingLeft: `${organization.depth * 1.25}rem`,
                                                }}
                                            >
                                                {organization.depth > 0 && (
                                                    <span
                                                        aria-hidden="true"
                                                        style={{
                                                            color: 'var(--faint)',
                                                            marginRight: '.4rem',
                                                        }}
                                                    >
                                                        └
                                                    </span>
                                                )}
                                                <div className="min-w-0">
                                                    <p className="font-semibold">
                                                        {organization.name}
                                                        {!organization.active && (
                                                            <Pill
                                                                tone="destructive"
                                                                className="align-middle ml-1"
                                                            >
                                                                Suspended
                                                            </Pill>
                                                        )}
                                                    </p>
                                                    <p
                                                        className="text-xs mono"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        {organization.slug}
                                                    </p>
                                                </div>
                                            </div>
                                        </Td>

                                        <Td
                                            className="capitalize whitespace-nowrap"
                                            style={{ color: 'var(--muted)' }}
                                        >
                                            {organization.type}
                                        </Td>
                                        <Td className="text-right tabular-nums">
                                            {organization.members}
                                        </Td>

                                        <Td>
                                            <ParentPicker
                                                organization={organization}
                                                parents={all}
                                            />
                                        </Td>

                                        <Td className="text-right whitespace-nowrap">
                                            <Button asChild size="sm">
                                                <Link
                                                    href={organization.href}
                                                    aria-label={`View ${organization.name}`}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                            {/*
                                                Suspending a live tenant sat 8px right of
                                                "View" with no dialog, no undo and no toast.
                                                Reversible here, so a plain dialog rather
                                                than the type-to-confirm one.
                                            */}
                                            <Button
                                                size="sm"
                                                className="ml-2"
                                                variant={
                                                    organization.active ? 'danger' : 'primary'
                                                }
                                                // NAMED FOR THE ROW. Twenty buttons all
                                                // announced as "Suspend" tell somebody
                                                // navigating by control which action they
                                                // are on and nothing about whom it is
                                                // about — on the one control here that
                                                // signs a tenant's people out.
                                                aria-label={`${organization.active ? 'Suspend' : 'Reactivate'} ${organization.name}`}
                                                onClick={() => setConfirming(organization)}
                                            >
                                                {organization.active
                                                    ? 'Suspend'
                                                    : 'Reactivate'}
                                            </Button>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                </div>
            )}

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={
                    confirming === null
                        ? ''
                        : `${confirming.active ? 'Suspend' : 'Reactivate'} ${confirming.name}?`
                }
                description={
                    confirming === null
                        ? ''
                        : confirming.active
                          ? `Its ${plural(confirming.members, 'member')} can no longer sign in to this tenant, and any app relying on it stops authenticating them. Sub-organizations are not suspended with it. You can reactivate it here.`
                          : 'Its members can sign in again immediately.'
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>Cancel</Button>
                        <Button
                            variant={confirming?.active === true ? 'danger' : 'primary'}
                            onClick={() => {
                                const organization = confirming;
                                setConfirming(null);

                                if (organization !== null) {
                                    router.post(
                                        organization.toggleHref,
                                        {},
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            {confirming?.active === true ? 'Suspend' : 'Reactivate'}
                        </Button>
                    </>
                }
            />
        </>
    );
}

/**
 * Moving a tenant under another rewrites its subtree, so the control posts the change
 * rather than editing a field: there is nothing to save afterwards, and a select that
 * looked like a draft would leave an operator wondering whether it had taken.
 */
function ParentPicker({
    organization,
    parents,
}: {
    organization: OrganizationRow;
    parents: OrganizationOption[];
}) {
    return (
        <Select
            aria-label={`Parent organization for ${organization.name}`}
            value={organization.parentId ?? TOP_LEVEL}
            onValueChange={(parentId) =>
                router.post(
                    organization.reparentHref,
                    { parentId: parentId === TOP_LEVEL ? '' : parentId },
                    { preserveScroll: true },
                )
            }
            options={[
                { value: TOP_LEVEL, label: '— Top level —' },
                ...parents
                    .filter((parent) => parent.id !== organization.id)
                    .map((parent) => ({ value: parent.id, label: parent.name })),
            ]}
        />
    );
}

function CreateOrganization({
    href,
    types,
    parents,
    onDone,
}: {
    href: string;
    types: { value: string; label: string }[];
    parents: OrganizationOption[];
    onDone: () => void;
}) {
    const form = useForm({
        name: '',
        type: types[0]?.value ?? 'customer',
        parentId: '',
    });
    const name = useRef<HTMLInputElement>(null);

    useEffect(() => {
        name.current?.focus();
    }, []);

    return (
        <form
            className="card p-4 mb-5 mt-8 flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
            }}
        >
            <div className="flex-1" style={{ minWidth: '12rem' }}>
                <Field label="Name" error={form.errors.name}>
                    <Input
                        ref={name}
                        name="name"
                        placeholder="Acme Inc"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <Field label="Type" error={form.errors.type}>
                <Select
                    value={form.data.type}
                    onValueChange={(type) => form.setData('type', type)}
                    options={types.map((type) => ({ value: type.value, label: type.label }))}
                />
            </Field>

            <div style={{ minWidth: '12rem' }}>
                <Field label="Parent (optional)" error={form.errors.parentId}>
                    <Select
                        value={form.data.parentId === '' ? TOP_LEVEL : form.data.parentId}
                        onValueChange={(parentId) =>
                            form.setData('parentId', parentId === TOP_LEVEL ? '' : parentId)
                        }
                        options={[
                            { value: TOP_LEVEL, label: '— Top level —' },
                            ...parents.map((parent) => ({
                                value: parent.id,
                                label: parent.name,
                            })),
                        ]}
                    />
                </Field>
            </div>

            <Button type="submit" variant="primary" loading={form.processing}>
                Create
            </Button>
            <Button type="button" onClick={onDone}>
                Cancel
            </Button>
        </form>
    );
}

Organizations.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
