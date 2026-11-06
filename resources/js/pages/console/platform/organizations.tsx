import { useConsoleCount } from '@/i18n/count';
import { useTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
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
    help: HelpContent;
    limitNotice: string | null;
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

export default function Organizations({
    organizations,
    all,
    search,
    types,
    storeHref,
    help,
    limitNotice,
}: Props) {
    const { t: tc } = useTranslator();
    const plural = useConsoleCount();

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
            {limitNotice && (
                <output className="block mb-4 rounded border border-[var(--border)] p-3 text-sm">
                    {limitNotice}
                </output>
            )}
            <PageHeader
                help={help}
                description={tc('console.every_organization_in_the_target_environment_9b4126ae')}
                actions={
                    <Button
                        variant="primary"
                        aria-expanded={creating}
                        onClick={() => setCreating((open) => !open)}
                    >
                        <Icon name="plus" className="w-4 h-4" />
                        {tc('console.new_organization_62e34b70')}
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={tc('console.search_by_name_or_slug_bfd8464a')}
                    aria-label={tc('console.search_organizations_c1b2693a')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the tree is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that reports a search that
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {plural(organizations.length, 'organization')}
                    {tc('console.found_599e25a3')}
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
                            title={tc('console.no_organizations_match_value0_41ff5b88', {
                                value0: search,
                            })}
                            description={tc(
                                'console.try_part_of_the_organization_s_name_or_its_s_0ff762f7',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="layers"
                            title={tc('console.no_organizations_in_this_environment_870bbd6b')}
                            description={tc(
                                'console.an_organization_is_where_users_sign_in_metho_ae379847',
                            )}
                        />
                    )}
                </div>
            ) : (
                <div className="cbx-panel overflow-hidden mt-8">
                    <div className="overflow-x-auto">
                        <Table
                            caption={tc(
                                'console.organizations_in_the_targeted_environment_as_2f2361fb',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{tc('console.organization_d764d425')}</Th>
                                    <Th>{tc('console.type_baaddf70')}</Th>
                                    <Th className="text-right">{tc('console.members_1044a4c0')}</Th>
                                    <Th>{tc('console.parent_5f7953f7')}</Th>
                                    <Th>
                                        <span className="sr-only">
                                            {tc('console.actions_ff8059dc')}
                                        </span>
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
                                                                {tc('console.suspended_e392a389')}
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
                                                    aria-label={tc('console.view_value0_0749adea', {
                                                        value0: organization.name,
                                                    })}
                                                >
                                                    {tc('console.view_dcc839a4')}
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
                                                variant={organization.active ? 'danger' : 'primary'}
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
                                                    ? tc('console.suspend_4948e134')
                                                    : tc('console.reactivate_2dc7478f')}
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
                          ? tc('console.its_value0_can_no_longer_sign_in_to_this_org_b832d3fa', {
                                value0: plural(confirming.members, 'member'),
                            })
                          : tc('console.its_members_can_sign_in_again_immediately_bd3004a5')
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
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
                            {confirming?.active === true
                                ? tc('console.suspend_4948e134')
                                : tc('console.reactivate_2dc7478f')}
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
    const { t: tc } = useTranslator();

    return (
        <Select
            aria-label={tc('console.parent_organization_for_value0_b84d7872', {
                value0: organization.name,
            })}
            value={organization.parentId ?? TOP_LEVEL}
            onValueChange={(parentId) =>
                router.post(
                    organization.reparentHref,
                    { parentId: parentId === TOP_LEVEL ? '' : parentId },
                    { preserveScroll: true },
                )
            }
            options={[
                { value: TOP_LEVEL, label: tc('console.top_level_cf731b6e') },
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
    const { t: tc } = useTranslator();

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
                <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                    <Input
                        ref={name}
                        name="name"
                        placeholder={tc('console.acme_inc_42cf7a26')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <Field label={tc('console.type_baaddf70')} error={form.errors.type}>
                <Select
                    value={form.data.type}
                    onValueChange={(type) => form.setData('type', type)}
                    options={types.map((type) => ({ value: type.value, label: type.label }))}
                />
            </Field>

            <div style={{ minWidth: '12rem' }}>
                <Field label={tc('console.parent_optional_dec78571')} error={form.errors.parentId}>
                    <Select
                        value={form.data.parentId === '' ? TOP_LEVEL : form.data.parentId}
                        onValueChange={(parentId) =>
                            form.setData('parentId', parentId === TOP_LEVEL ? '' : parentId)
                        }
                        options={[
                            { value: TOP_LEVEL, label: tc('console.top_level_cf731b6e') },
                            ...parents.map((parent) => ({
                                value: parent.id,
                                label: parent.name,
                            })),
                        ]}
                    />
                </Field>
            </div>

            <Button type="submit" variant="primary" loading={form.processing}>
                {tc('console.create_4759498a')}
            </Button>
            <Button type="button" onClick={onDone}>
                {tc('console.cancel_19766ed6')}
            </Button>
        </form>
    );
}

Organizations.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
