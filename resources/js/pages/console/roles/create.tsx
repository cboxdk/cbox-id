import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
            <Breadcrumb href={indexHref} label={consoleT('console.roles_c2533705')} />

            {/*
                Wrapped rather than given a margin of its own: the heading comes from the
                controller's title through <PageHeader>, so the tab and the h1 cannot
                disagree, and the offset belongs to the back-link above it.
            */}
            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.a_bundle_of_permissions_you_assign_to_people_each_app_h_1d6967ee',
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
                        <Field label={consoleT('console.name_dcd1d522')} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder={consoleUiText('console.manager_8b2085f7')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={consoleT('console.description_526e0087')}
                            optional
                            error={form.errors.description}
                        >
                            <Input
                                name="description"
                                placeholder={consoleUiText(
                                    'console.team_leads_across_the_organization_06640f95',
                                )}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label={consoleT('console.applies_to_6687458b')}
                            hint={consoleT(
                                'console.an_app_scoped_role_is_stamped_only_into_that_app_s_toke_d903a755',
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
                                    {
                                        value: ALL_APPS,
                                        label: consoleText('console.all_apps_01bed311'),
                                    },
                                    ...apps.map((app) => ({
                                        value: app.id,
                                        label: consoleFinalText('console._part1_only_d798c565', {
                                            part1: app.name,
                                        }),
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
                                    label={consoleT(
                                        'console.define_it_for_every_organization_in_this_environment_d2e90bcf',
                                    )}
                                    hint={consoleT(
                                        'console.an_environment_wide_role_can_be_assigned_inside_any_org_dd5b485e',
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
                                hint={consoleT(
                                    'console.whose_role_it_is_or_tick_the_box_above_to_define_it_for_970fb7fe',
                                )}
                            />
                        )}
                    </div>
                </Panel>

                <Panel
                    title={consoleT('console.permissions_abccc78c')}
                    description={consoleT(
                        'console.optional_a_role_is_worth_creating_before_its_keys_exist_c9fcc75c',
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
                                title={consoleT('console.no_permissions_declared_7ed6ab7b')}
                                description={
                                    <>
                                        {consoleRich(
                                            'console.an_app_registers_its_catalog_over_the_sdk_part1_part2_y_384ea703',
                                            {
                                                part1: ' ',
                                                part2: permissionsHref !== null && (
                                                    <>
                                                        {consoleRich(
                                                            'console.you_can_also_part1_part2_part3_53f32513',
                                                            {
                                                                part1: ' ',
                                                                part2: (
                                                                    <Link
                                                                        href={permissionsHref}
                                                                        style={{
                                                                            color: 'var(--accent-strong)',
                                                                        }}
                                                                    >
                                                                        {consoleT(
                                                                            'console.add_permissions_manually_3596f1b0',
                                                                        )}
                                                                    </Link>
                                                                ),
                                                                part3: ' ',
                                                            },
                                                        )}
                                                    </>
                                                ),
                                            },
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
                                                <Badge>
                                                    {consoleT('console.all_apps_01bed311')}
                                                </Badge>
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
                        {consoleT('console.create_role_221163d6')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateRole.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
