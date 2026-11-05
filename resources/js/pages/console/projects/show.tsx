import { useTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    ConfirmDelete,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
    Select,
} from '@/ui';
import { useState } from 'react';
import {
    reactivate,
    rename,
    storeEnvironment,
    suspend,
} from '@actions/App/Http/Controllers/Console/ProjectController';

interface EnvironmentRow {
    id: string;
    name: string;
    sandbox: boolean;
    status: string;
    url: string;
    openHref: string;
}

type Props = PageProps<{
    project: {
        id: string;
        name: string;
        slug: string;
        status: string;
        suspended: boolean;
        limit: number;
    };
    environments: EnvironmentRow[];
    canManage: boolean;
    /** This member reaches a subset of the project's environments, not all of them. */
    scoped: boolean;
    remaining: number;
    indexHref: string;
}>;

export default function ProjectDetail({
    project,
    environments,
    canManage,
    scoped,
    remaining,
    indexHref,
}: Props) {
    const { t: tc } = useTranslator();

    const [suspending, setSuspending] = useState(false);

    const environmentForm = useForm({ name: '', type: 'production' });
    const nameForm = useForm({ name: project.name });

    const full = remaining <= 0;

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={tc('console.projects_04e2a972')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    {/*
                        The h1 is the project's name rather than the word "Project", and
                        the controller states the same string as the page title — so the
                        tab and the heading say which product this is.
                    */}
                    <h1 className="cbx-page-title">{project.name}</h1>
                    <Badge tone={project.suspended ? 'warn' : 'neutral'}>{project.status}</Badge>
                </div>
                <p className="mt-1 text-xs mono select-all" style={{ color: 'var(--faint)' }}>
                    {project.slug} · {project.id}
                </p>
            </div>

            <Panel
                title={tc('console.environments_07437cd6')}
                description={tc('console.each_is_an_isolated_stage_production_or_sand_c8b3283a')}
                action={
                    scoped ? undefined : (
                        <span className="text-xs shrink-0" style={{ color: 'var(--faint)' }}>
                            {environments.length}
                            {tc('console.of_a4282e4b')}
                            {project.limit}
                            {tc('console.used_f194a918')}
                        </span>
                    )
                }
            >
                <div className="space-y-2">
                    {environments.length === 0 ? (
                        <EmptyState
                            icon="layers"
                            title={tc('console.no_environments_yet_8c63cac4')}
                            description={tc(
                                'console.create_an_environment_below_to_start_issuing_008d04ea',
                            )}
                        />
                    ) : (
                        environments.map((environment) => (
                            <div
                                key={environment.id}
                                className="rounded-lg border p-4 flex items-center justify-between gap-4"
                                style={{ borderColor: 'var(--border)' }}
                            >
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium truncate">
                                            {environment.name}
                                        </span>
                                        {environment.sandbox && (
                                            <Badge tone="warn">
                                                {tc('console.sandbox_67fc6249')}
                                            </Badge>
                                        )}
                                        <Badge>{environment.status}</Badge>
                                    </div>
                                    <a
                                        href={environment.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-1 block text-sm truncate underline underline-offset-2"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        {environment.url}
                                    </a>
                                </div>

                                <Button asChild variant="primary" size="sm" className="shrink-0">
                                    {/* A signed handoff to another host — a navigation, not a page. */}
                                    <a href={environment.openHref}>
                                        {tc('console.open_ed077f3d')}
                                        <Icon name="external" className="w-3.5 h-3.5" />
                                    </a>
                                </Button>
                            </div>
                        ))
                    )}
                </div>

                {canManage && (
                    <>
                        <form
                            className="mt-4 flex items-start gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                environmentForm.post(
                                    storeEnvironment.url({ project: project.id }),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => environmentForm.reset(),
                                    },
                                );
                            }}
                        >
                            <div className="flex-1">
                                <Field
                                    label={
                                        <span className="sr-only">
                                            {tc('console.environment_name_03cb80d1')}
                                        </span>
                                    }
                                    error={environmentForm.errors.name}
                                >
                                    <Input
                                        name="name"
                                        placeholder={tc('console.staging_a8e7ac92')}
                                        disabled={full}
                                        value={environmentForm.data.name}
                                        onChange={(event) =>
                                            environmentForm.setData('name', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>

                            <Field
                                label={
                                    <span className="sr-only">
                                        {tc('console.environment_type_7c9f3eba')}
                                    </span>
                                }
                                error={environmentForm.errors.type}
                            >
                                <Select
                                    value={environmentForm.data.type}
                                    onValueChange={(type) => environmentForm.setData('type', type)}
                                    disabled={full}
                                    options={[
                                        {
                                            value: 'production',
                                            label: tc('console.production_13a7c655'),
                                        },
                                        { value: 'sandbox', label: tc('console.sandbox_67fc6249') },
                                    ]}
                                    aria-label={tc('console.environment_type_7c9f3eba')}
                                />
                            </Field>

                            <Button
                                type="submit"
                                variant="primary"
                                disabled={full}
                                loading={environmentForm.processing}
                            >
                                {tc('console.add_environment_dcbe4c44')}
                            </Button>
                        </form>

                        <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                            {full
                                ? tc(
                                      'console.this_project_has_used_every_environment_its__b891ec0b',
                                  )
                                : tc(
                                      'console.sandbox_environments_allow_localhost_urls_an_021f74f7',
                                      { value0: remaining },
                                  )}
                        </p>
                    </>
                )}
            </Panel>

            {/*
                The plan sits on the PROJECT, because the project is the billing anchor:
                one account, separately-billed products.
            */}
            <Panel title={tc('console.plan_fa8ed0bd')}>
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-sm font-medium">
                            {tc('console.early_access_321217e9')}{' '}
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                {tc('console.free_c8345f98')}
                            </span>
                        </p>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.up_to_08c05ead')}
                            {project.limit}
                            {tc('console.environments_billing_per_project_arrives_wit_463213a0')}
                        </p>
                    </div>
                    <Button size="sm" className="shrink-0" disabled style={{ opacity: 0.6 }}>
                        {tc('console.upgrade_soon_e12e55c5')}
                    </Button>
                </div>
            </Panel>

            {canManage && (
                <Panel title={tc('console.settings_74a883a0')}>
                    <form
                        className="grid sm:grid-cols-[1fr_auto] gap-2 items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            nameForm.patch(rename.url({ project: project.id }), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Field
                            label={tc('console.project_name_25498193')}
                            error={nameForm.errors.name}
                        >
                            <Input
                                name="name"
                                value={nameForm.data.name}
                                onChange={(event) => nameForm.setData('name', event.target.value)}
                            />
                        </Field>
                        <Button
                            type="submit"
                            variant="primary"
                            className="shrink-0"
                            loading={nameForm.processing}
                        >
                            {tc('console.save_1509f561')}
                        </Button>
                    </form>

                    <div
                        className="mt-5 pt-5 flex items-center justify-between gap-4"
                        style={{ borderTop: '1px solid var(--border)' }}
                    >
                        <div>
                            <p className="text-sm font-medium">
                                {project.suspended
                                    ? tc('console.reactivate_project_8c8f7a7b')
                                    : tc('console.suspend_project_9e1e10fb')}
                            </p>
                            <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                                {project.suspended
                                    ? tc(
                                          'console.bring_the_project_back_new_environments_can__2b5117fe',
                                      )
                                    : tc(
                                          'console.existing_environments_stay_live_but_no_new_o_0becaa18',
                                      )}
                            </p>
                        </div>

                        {project.suspended ? (
                            <Button
                                size="sm"
                                className="shrink-0"
                                onClick={() =>
                                    router.post(
                                        reactivate.url({ project: project.id }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {tc('console.reactivate_2dc7478f')}
                            </Button>
                        ) : (
                            <>
                                <Button
                                    size="sm"
                                    className="shrink-0"
                                    style={{ color: 'var(--destructive)' }}
                                    onClick={() => setSuspending(true)}
                                >
                                    {tc('console.suspend_4948e134')}
                                </Button>

                                <ConfirmDelete
                                    open={suspending}
                                    onOpenChange={setSuspending}
                                    name={project.name}
                                    verb="Suspend"
                                    consequence={tc(
                                        'console.existing_environments_stay_live_but_no_new_o_ed87b1df',
                                    )}
                                    onConfirm={() => {
                                        setSuspending(false);
                                        router.post(
                                            suspend.url({ project: project.id }),
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }}
                                />
                            </>
                        )}
                    </div>
                </Panel>
            )}
        </div>
    );
}

ProjectDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
