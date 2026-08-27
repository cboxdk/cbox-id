import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, PageHeader, Panel } from '@/ui';
import { store } from '@actions/App/Http/Controllers/Console/ProjectController';

type Props = PageProps<{ indexHref: string }>;

export default function CreateProject({ indexHref }: Props) {
    const form = useForm({ name: '' });

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
                Projects
            </Link>

            <div className="mt-2">
                <PageHeader description="A separate identity-provider product with its own environments and plan — billed independently of your other projects." />
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
                        label="Project name"
                        hint="You'll add its environments (production, staging, sandbox) next."
                        error={form.errors.name}
                    >
                        <Input
                            name="name"
                            placeholder="Product Two"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <div className="flex items-center gap-2 mt-4">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            Create project
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>Cancel</Link>
                        </Button>
                    </div>
                </Panel>
            </form>
        </>
    );
}

CreateProject.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
