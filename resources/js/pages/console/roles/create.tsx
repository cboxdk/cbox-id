import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    EmptyState,
    Field,
    Input,
    OrganizationPickerField,
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

/**
 * "All apps", as a value the picker can hold. Radix reserves the empty string (it is how
 * the primitive clears a selection), so an item carrying `''` never shows as chosen: the
 * trigger drew "Select…" over the default. The form keeps `''`, which the server reads as
 * "every app"; only the picker sees the sentinel.
 */
const ALL_APPS = '__all_apps__';

type Props = PageProps<{
    catalog: CatalogEntry[];
    apps: { id: string; name: string }[];
    holdsEnvironment: boolean;
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    permissionsHref: string | null;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateRole({
    catalog,
    apps,
    holdsEnvironment,
    organization,
    permissionsHref,
    indexHref,
    storeHref,
}: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({
        name: '',
        description: '',
        /* Empty = every app. A role scoped to one app carries its permissions only into
           that app's tokens. */
        app: '',
        environmentWide: false,
        organization: organization?.selected?.id ?? '',
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

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.roles_c2533705')} />

            {/*
                Wrapped rather than given a margin of its own: the heading comes from the
                controller's title through <PageHeader>, so the tab and the h1 cannot
                disagree, and the offset belongs to the back-link above it.
            */}
            <div className="mt-2">
                <PageHeader
                    description={tc(
                        'console.a_bundle_of_permissions_you_assign_to_people_1d6967ee',
                    )}
                />
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
                        <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder={tc('console.manager_8b2085f7')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={tc('console.description_526e0087')}
                            optional
                            error={form.errors.description}
                        >
                            <Input
                                name="description"
                                placeholder={tc(
                                    'console.team_leads_across_the_organization_06640f95',
                                )}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label={tc('console.applies_to_6687458b')}
                            hint={tc(
                                'console.an_app_scoped_role_is_stamped_only_into_that_d903a755',
                            )}
                            error={form.errors.app}
                        >
                            <Select
                                name="app"
                                value={form.data.app === '' ? ALL_APPS : form.data.app}
                                onValueChange={(app) =>
                                    form.setData('app', app === ALL_APPS ? '' : app)
                                }
                                options={[
                                    { value: ALL_APPS, label: tc('console.all_apps_01bed311') },
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
                                    label={tc(
                                        'console.define_it_for_every_organization_in_this_env_d2e90bcf',
                                    )}
                                    hint={tc(
                                        'console.an_environment_wide_role_can_be_assigned_ins_dd5b485e',
                                    )}
                                />
                                {form.errors.environmentWide !== undefined && (
                                    <p className="field-error" role="alert">
                                        {form.errors.environmentWide}
                                    </p>
                                )}
                            </div>
                        )}

                        {organization !== null && !form.data.environmentWide && (
                            <OrganizationPickerField
                                picker={organization}
                                error={form.errors.organization}
                                onChange={(id) => form.setData('organization', id)}
                                hint={tc(
                                    'console.whose_role_it_is_or_tick_the_box_above_to_de_970fb7fe',
                                )}
                            />
                        )}
                    </div>
                </Panel>

                <Panel
                    title={tc('console.permissions_abccc78c')}
                    description={tc(
                        'console.optional_a_role_is_worth_creating_before_its_c9fcc75c',
                    )}
                >
                    <div
                        className="space-y-1.5 rounded-lg border p-3"
                        style={{
                            borderColor: 'var(--border)',
                            maxHeight: '18rem',
                            overflowY: 'auto',
                        }}
                    >
                        {catalog.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title={tc('console.no_permissions_declared_7ed6ab7b')}
                                description={
                                    <>
                                        {tc(
                                            'console.an_app_registers_its_catalog_over_the_sdk_91cbb084',
                                        )}{' '}
                                        {permissionsHref !== null && (
                                            <>
                                                {tc('console.you_can_also_b91b5d94')}{' '}
                                                <Link
                                                    href={permissionsHref}
                                                    style={{ color: 'var(--accent-strong)' }}
                                                >
                                                    {tc(
                                                        'console.add_permissions_manually_3596f1b0',
                                                    )}
                                                </Link>
                                                .{' '}
                                            </>
                                        )}
                                        {tc(
                                            'console.you_can_create_the_role_now_and_compose_it_o_37c595c9',
                                        )}
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
                                                <Badge>{tc('console.all_apps_01bed311')}</Badge>
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
                        {tc('console.create_role_221163d6')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateRole.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
