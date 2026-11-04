import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, Field, Input, PageHeader, Panel } from '@/ui';
import { store } from '@actions/App/Http/Controllers/Console/ProjectController';

type Props = PageProps<{ indexHref: string }>;

export default function CreateProject({ indexHref }: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({ name: '' });

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.projects_04e2a972')} />

            <div className="mt-2">
                <PageHeader
                    description={tc(
                        'console.a_separate_identity_provider_product_with_it_97a4c57b',
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
                        label={tc('console.project_name_25498193')}
                        hint={tc('console.you_ll_add_its_environments_production_stagi_d74d2d40')}
                        error={form.errors.name}
                    >
                        <Input
                            name="name"
                            placeholder={tc('console.product_two_23ff9b66')}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <div className="flex items-center gap-2 mt-4">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            {tc('console.create_project_fa67e4d0')}
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                        </Button>
                    </div>
                </Panel>
            </form>
        </>
    );
}

CreateProject.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
