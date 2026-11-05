import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, Checkbox, Field, Input, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    indexHref: string;
    storeHref: string;
}>;

export default function CreateFeatureFlag({ indexHref, storeHref }: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({ key: '', description: '', defaultValue: false });

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.feature_flags_20a2e59b')} />

            <div className="mt-2">
                <PageHeader
                    description={tc(
                        'console.a_switch_your_apps_ask_about_you_choose_who__feaf9a9e',
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
                        <Field
                            label={tc('console.key_99a52df3')}
                            hint={tc(
                                'console.what_your_code_asks_for_and_what_the_feature_440d8ca1',
                            )}
                            error={form.errors.key}
                        >
                            <Input
                                name="key"
                                className="mono"
                                spellCheck={false}
                                autoCapitalize="off"
                                placeholder={tc('console.new_dashboard_13332e4d')}
                                value={form.data.key}
                                onChange={(event) => form.setData('key', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={tc('console.description_526e0087')}
                            optional
                            error={form.errors.description}
                        >
                            <Input
                                name="description"
                                placeholder={tc('console.the_redesigned_dashboard_3d00842e')}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>

                        <Checkbox
                            checked={form.data.defaultValue}
                            onCheckedChange={(checked) => form.setData('defaultValue', checked)}
                            label={tc('console.on_by_default_e1589716')}
                            hint={tc(
                                'console.who_gets_the_flag_when_no_rule_names_them_le_b44a0c73',
                            )}
                        />
                    </div>
                </Panel>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.create_flag_f85a66d4')}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateFeatureFlag.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
