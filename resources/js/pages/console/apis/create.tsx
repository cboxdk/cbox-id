import { Link, router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Button,
    Field,
    Icon,
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

            <div className="mt-2">
                <PageHeader description="A service of yours that receives access tokens. You add the scopes it owns on the next page." />
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
                                placeholder="Invoicing API"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label="Identifier"
                            hint="The address tokens for this API name as their audience, and what your API checks for. It cannot be changed later — every token already issued carries it."
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
                                label="Owner"
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
                                            ? 'Your own API. You choose, scope by scope, which of its scopes apps registered by organizations may request.'
                                            : 'That organization’s API. Only its own apps may be given its scopes.',
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
                                    label="For which organization?"
                                    className="mt-4"
                                    hint="To register an API for one organization, find it here — it is then offered as an owner above."
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
                            label="Roles and permissions from"
                            optional
                            hint="The app whose declared roles and permissions tokens for this API carry. Leave it empty and each token carries the roles of the app that asked for it."
                            error={form.errors.clientId}
                        >
                            <Select
                                name="clientId"
                                value={form.data.clientId === '' ? NOBODY : form.data.clientId}
                                onValueChange={(clientId) =>
                                    form.setData('clientId', clientId === NOBODY ? '' : clientId)
                                }
                                options={[
                                    { value: NOBODY, label: 'The app that asks' },
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
                        Register API
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateApi.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
