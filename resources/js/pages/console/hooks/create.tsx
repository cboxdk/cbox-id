import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    Field,
    Input,
    OrganizationPickerField,
    PageHeader,
    Panel,
    RadioGroup,
} from '@/ui';

interface PointOption {
    value: string;
    label: string;
    description: string;
}

type Props = PageProps<{
    points: PointOption[];
    /** Whether registering for the whole environment is on offer here. */
    mayScopeEnvironmentWide: boolean;
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateHook({
    points,
    mayScopeEnvironmentWide,
    organization,
    indexHref,
    storeHref,
}: Props) {
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        point: points[0]?.value ?? '',
        url: '',
        environmentWide: false,
        organization: organization?.selected?.id ?? '',
    });

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.hooks_d412a062')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.the_signing_secret_is_shown_once_right_after_you_regist_35bf6d24',
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
                <Panel
                    title={consoleT('console.hook_point_57b75ff5')}
                    description={consoleT(
                        'console.the_moment_your_endpoint_is_called_and_what_its_answer_209c828e',
                    )}
                >
                    {/*
                        Radios rather than a select: there are six, each needs a sentence
                        to be choosable at all, and this is the one field that decides
                        which operation your endpoint gets a say in. A dropdown hides five
                        of the six behind a click and has nowhere to put the sentence.
                    */}
                    <RadioGroup
                        label={consoleT('console.hook_point_57b75ff5')}
                        value={form.data.point}
                        onValueChange={(point) => form.setData('point', point)}
                        options={points.map((point) => ({
                            value: point.value,
                            label: point.label,
                            hint: point.description,
                        }))}
                    />
                    {form.errors.point !== undefined && (
                        <p className="field-error" role="alert">
                            {form.errors.point}
                        </p>
                    )}
                </Panel>

                <Panel title={consoleT('console.endpoint_3df9726c')}>
                    <div className="space-y-4">
                        <Field
                            label={consoleT('console.endpoint_url_2578179d')}
                            hint={consoleT(
                                'console.called_synchronously_while_somebody_waits_at_the_sign_i_cf697019',
                            )}
                            error={form.errors.url}
                        >
                            <Input
                                name="url"
                                type="url"
                                className="mono"
                                placeholder="https://example.com/hooks/token"
                                value={form.data.url}
                                onChange={(event) => form.setData('url', event.target.value)}
                            />
                        </Field>

                        {mayScopeEnvironmentWide && (
                            <Checkbox
                                checked={form.data.environmentWide}
                                onCheckedChange={(checked) =>
                                    form.setData('environmentWide', checked)
                                }
                                label={consoleT('console.environment_wide_cd1204b6')}
                                hint={consoleT(
                                    'console.call_this_endpoint_for_every_organization_in_this_envir_da115b63',
                                )}
                            />
                        )}

                        {organization !== null && !form.data.environmentWide && (
                            <OrganizationPickerField
                                picker={organization}
                                error={form.errors.organization}
                                onChange={(id) => form.setData('organization', id)}
                                hint={consoleT(
                                    'console.whose_sign_ins_it_is_called_for_or_tick_environment_wid_38b9a155',
                                )}
                            />
                        )}
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.register_endpoint_1e7b84db')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateHook.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
