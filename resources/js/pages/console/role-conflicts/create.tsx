import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    EmptyState,
    Field,
    Input,
    OrganizationPickerField,
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
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateRoleConflict({
    roles,
    roleSearch,
    pickerLimit,
    holdsEnvironment,
    organization,
    indexHref,
    storeHref,
}: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        name: '',
        description: '',
        roles: [] as string[],
        environmentWide: false,
        organization: organization?.selected?.id ?? '',
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

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.role_conflicts_1f4cf2a5')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.name_two_or_more_roles_that_must_never_sit_with_the_sam_34e75840',
                    )}
                />
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
                            label={consoleT('console.rule_name_7c9de4e8')}
                            hint={consoleT(
                                'console.name_the_conflict_the_way_an_auditor_would_raise_paymen_f77c6fe4',
                            )}
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder={consoleRuntimeText(
                                    'console.raise_payment_vs_approve_payment_7a4d69f3',
                                )}
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
                                placeholder={consoleRuntimeText(
                                    'console.nobody_may_both_raise_and_approve_the_same_payment_37b3c7fc',
                                )}
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
                                    label={consoleT(
                                        'console.apply_it_to_every_organization_in_this_environment_d0de9d65',
                                    )}
                                    hint={consoleT(
                                        'console.an_environment_wide_rule_binds_every_organization_here_1ad966cf',
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
                                    'console.whose_rule_it_is_or_tick_the_box_above_to_apply_it_to_e_82370728',
                                )}
                            />
                        )}
                    </div>
                </Panel>

                <Panel
                    title={consoleT('console.conflicting_roles_60210f0e')}
                    description={consoleT(
                        'console.at_least_two_a_set_of_one_conflicts_with_nothing_and_wo_e7fb65cd',
                    )}
                >
                    <div className="space-y-3">
                        <Input
                            type="search"
                            aria-label={consoleT('console.search_roles_848b9c5d')}
                            placeholder={consoleUiText('console.search_roles_by_name_77a26efe')}
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                        />
                        <output className="sr-only">
                            {consoleRich('console.results_on_page', { count: roles.length })}
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
                                            ? consoleText(
                                                  'console.no_roles_to_combine_yet_e1a3c1bd',
                                              )
                                            : consoleFinalText(
                                                  'console.no_role_matches_part1_6f3c43ce',
                                                  { part1: term },
                                              )
                                    }
                                    description={
                                        term === ''
                                            ? consoleText(
                                                  'console.a_conflict_is_written_over_roles_so_there_is_nothing_to_1ee9ecc5',
                                              )
                                            : consoleText(
                                                  'console.try_a_different_search_roles_already_ticked_stay_in_thi_82c5a227',
                                              )
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
                                {consoleRich(
                                    'console.showing_the_first_part1_search_to_reach_the_rest_anythi_57d8cba9',
                                    { part1: pickerLimit },
                                )}
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
                        {consoleT('console.define_rule_37b96dbf')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateRoleConflict.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
