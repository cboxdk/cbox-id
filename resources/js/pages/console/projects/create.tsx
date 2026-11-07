import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, Field, Input, PageHeader, Panel } from '@/ui';
import { store } from '@routes/projects';

type Props = PageProps<{ indexHref: string }>;

export default function CreateProject({ indexHref }: Props) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ name: '' });

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.projects_04e2a972')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.a_separate_identity_provider_product_with_its_own_envir_97a4c57b',
                    )}
                />
            </div>

            <form
                className="mt-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(store.url());
                }}
            >
                <Panel>
                    <Field
                        label={consoleT('console.project_name_25498193')}
                        hint={consoleT(
                            'console.you_ll_add_its_environments_production_staging_sandbox_d74d2d40',
                        )}
                        error={form.errors.name}
                    >
                        <Input
                            name="name"
                            placeholder={consoleUiText('console.product_two_23ff9b66')}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <div className="flex items-center gap-2 mt-4">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            {consoleT('console.create_project_fa67e4d0')}
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                        </Button>
                    </div>
                </Panel>
            </form>
        </>
    );
}

CreateProject.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
