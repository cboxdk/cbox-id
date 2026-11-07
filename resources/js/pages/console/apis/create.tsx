import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Field,
    Input,
    OrganizationField,
    PageHeader,
    Panel,
    RadioGroup,
    Select,
} from '@/ui';

interface Option {
    value: string;
    label: string;
}

/**
 * "No linked app", as a value the picker can hold: an empty string is not a value a
 * listbox option may carry, so the trigger drew its placeholder instead of the choice.
 */
const NOBODY = 'none';

type Props = PageProps<{
    /** `environment`, and the organization named on the form (`?organization=`) when there is one. */
    owners: Option[];
    /** The apps an API with each owner may take its roles from, keyed by owner. */
    apps: Record<string, Option[]>;
    /** "For which organization?" — locked when the form was opened from an organization's page. */
    organization: OrganizationPicker | null;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateApi({ owners, apps, organization, indexHref, storeHref }: Props) {
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        name: '',
        identifier: '',
        owner:
            organization?.locked === true && organization.selected !== null
                ? organization.selected.id
                : 'environment',
        clientId: '',
    });

    const linkable = apps[form.data.owner] ?? [];

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.apis_8d266d3e')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.a_service_of_yours_that_receives_access_tokens_you_add_d93c8407',
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
                                placeholder={consoleUiText('console.invoicing_api_6c18e057')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={consoleT('console.identifier_9b10587f')}
                            hint={consoleT(
                                'console.the_address_tokens_for_this_api_name_as_their_audience_cdea85aa',
                            )}
                            error={form.errors.identifier}
                        >
                            <Input
                                name="identifier"
                                type="url"
                                className="mono"
                                spellCheck={false}
                                placeholder="https://api.example.com"
                                value={form.data.identifier}
                                onChange={(event) => form.setData('identifier', event.target.value)}
                            />
                        </Field>

                        <div>
                            <RadioGroup
                                label={consoleT('console.owner_4b1b8aa3')}
                                name="owner"
                                value={form.data.owner}
                                onValueChange={(owner) => {
                                    form.setData((data) => ({ ...data, owner, clientId: '' }));
                                }}
                                options={owners.map((owner) => ({
                                    value: owner.value,
                                    label: owner.label,
                                    hint:
                                        owner.value === 'environment'
                                            ? consoleAttributeText(
                                                  'console.your_own_api_you_choose_scope_by_scope_which_of_its_sco_6f77f332',
                                              )
                                            : consoleAttributeText(
                                                  'console.that_organization_s_api_only_its_own_apps_may_be_given_9dd9b797',
                                              ),
                                }))}
                            />
                            {form.errors.owner !== undefined && (
                                <p className="field-error" role="alert">
                                    {form.errors.owner}
                                </p>
                            )}
                            {organization !== null && !organization.locked && (
                                // The organization is named here, and the page reloads with it
                                // so the apps it can be linked to are that organization's.
                                <Field
                                    label={consoleT('console.for_which_organization_aaca2bbd')}
                                    className="mt-4"
                                    hint={consoleT(
                                        'console.to_register_an_api_for_one_organization_find_it_here_it_48b04329',
                                    )}
                                >
                                    <OrganizationField
                                        lookupHref={organization.lookupHref}
                                        value={organization.selected}
                                        allowsEnvironment
                                        onChange={(chosen) =>
                                            router.get(
                                                window.location.pathname,
                                                chosen === null ? {} : { organization: chosen.id },
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                    replace: true,
                                                    onSuccess: () =>
                                                        form.setData((data) => ({
                                                            ...data,
                                                            owner: chosen?.id ?? 'environment',
                                                            clientId: '',
                                                        })),
                                                },
                                            )
                                        }
                                    />
                                </Field>
                            )}
                        </div>

                        <Field
                            label={consoleT('console.roles_and_permissions_from_484a0cfa')}
                            optional
                            hint={consoleT(
                                'console.the_app_whose_declared_roles_and_permissions_tokens_for_a1169778',
                            )}
                            error={form.errors.clientId}
                        >
                            <Select
                                name="clientId"
                                value={form.data.clientId === '' ? NOBODY : form.data.clientId}
                                onValueChange={(clientId) =>
                                    form.setData('clientId', clientId === NOBODY ? '' : clientId)
                                }
                                options={[
                                    {
                                        value: NOBODY,
                                        label: consoleText('console.the_app_that_asks_4a500e90'),
                                    },
                                    ...linkable.map((app) => ({
                                        value: app.value,
                                        label: app.label,
                                    })),
                                ]}
                            />
                        </Field>
                    </div>
                </Panel>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.register_api_e3c6bc27')}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateApi.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
