import { useTranslator as useConsoleTranslator } from '@/i18n';
import { store as storeEnvironment } from '@routes/orgs/projects/environments';
import { router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { UnreachableConsole } from './UnreachableConsole';
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
import { reactivate, rename, suspend } from '@routes/orgs/projects';

interface EnvironmentRow {
    id: string;
    name: string;
    sandbox: boolean;
    status: string;
    url: string;
    /** Null when the console has no address: the row says why instead. */
    openHref: string | null;
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
    billingHref: string | null;
    /** The guide on projects, offered where an environment's console has no address. */
    guideHref: string | null;
}>;

export default function ProjectDetail({
    project,
    environments,
    canManage,
    scoped,
    remaining,
    indexHref,
    billingHref,
    guideHref,
}: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [suspending, setSuspending] = useState(false);

    const environmentForm = useForm({ name: '', type: 'production' });
    const nameForm = useForm({ name: project.name });

    const full = remaining <= 0;

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.projects_04e2a972')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    {/*
                        The h1 is the project's name rather than the word "Project", and
                        the controller states the same string as the page title — so the
                        tab and the heading say which product this is.
                    */}
                    <h1 className="cbx-page-title break-all">{project.name}</h1>
                    <Badge tone={project.suspended ? 'warn' : 'neutral'}>{project.status}</Badge>
                </div>
                <p
                    className="mt-1 text-xs mono select-all break-all"
                    style={{ color: 'var(--faint)' }}
                >
                    {project.slug} · {project.id}
                </p>
            </div>

            <Panel
                title={consoleT('console.environments_07437cd6')}
                description={consoleText(
                    'console.each_is_an_isolated_stage_production_or_sandbox_with_it_c8b3283a',
                )}
                action={
                    scoped ? undefined : (
                        <span className="text-xs shrink-0" style={{ color: 'var(--faint)' }}>
                            {consoleRich('console._part1_of_part2_used_33343377', {
                                part1: environments.length,
                                part2: project.limit,
                            })}
                        </span>
                    )
                }
            >
                <div className="space-y-2">
                    {environments.length === 0 ? (
                        <EmptyState
                            icon="layers"
                            title={consoleT('console.no_environments_yet_8c63cac4')}
                            description={consoleT(
                                'console.create_an_environment_below_to_start_issuing_keys_and_s_008d04ea',
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
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium truncate">
                                            {environment.name}
                                        </span>
                                        {environment.sandbox && (
                                            <Badge tone="warn">
                                                {consoleT('console.sandbox_67fc6249')}
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

                                {environment.openHref === null ? (
                                    <UnreachableConsole
                                        name={environment.name}
                                        guideHref={guideHref}
                                    />
                                ) : (
                                    <Button
                                        asChild
                                        variant="primary"
                                        size="sm"
                                        className="shrink-0"
                                    >
                                        {/* A signed handoff to another host — a navigation, not a page. */}
                                        <a href={environment.openHref}>
                                            {consoleRich('console.open_part1_10c0f8b9', {
                                                part1: (
                                                    <Icon name="external" className="w-3.5 h-3.5" />
                                                ),
                                            })}
                                        </a>
                                    </Button>
                                )}
                            </div>
                        ))
                    )}
                </div>

                {canManage && (
                    <>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end"
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
                            <div className="min-w-0">
                                <Field
                                    label={
                                        <span className="sr-only">
                                            {consoleT('console.environment_name_03cb80d1')}
                                        </span>
                                    }
                                    error={environmentForm.errors.name}
                                >
                                    <Input
                                        name="name"
                                        placeholder={consoleUiText('console.staging_a8e7ac92')}
                                        disabled={full || project.suspended}
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
                                        {consoleT('console.environment_type_7c9f3eba')}
                                    </span>
                                }
                                error={environmentForm.errors.type}
                            >
                                <Select
                                    value={environmentForm.data.type}
                                    onValueChange={(type) => environmentForm.setData('type', type)}
                                    disabled={full || project.suspended}
                                    options={[
                                        {
                                            value: 'production',
                                            label: consoleText('console.production_13a7c655'),
                                        },
                                        {
                                            value: 'sandbox',
                                            label: consoleText('console.sandbox_67fc6249'),
                                        },
                                    ]}
                                    aria-label={consoleT('console.environment_type_7c9f3eba')}
                                />
                            </Field>

                            <Button
                                type="submit"
                                variant="primary"
                                disabled={full || project.suspended}
                                loading={environmentForm.processing}
                            >
                                {consoleT('console.add_environment_dcbe4c44')}
                            </Button>
                        </form>

                        <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                            {project.suspended
                                ? consoleText(
                                      'console.existing_environments_stay_live_but_no_new_o_0becaa18',
                                  )
                                : full
                                  ? consoleText(
                                        'console.this_project_has_used_every_environment_its_plan_allows_b891ec0b',
                                    )
                                  : consoleFinalText(
                                        'console.sandbox_environments_allow_localhost_urls_and_never_sen_299fef2d',
                                        { part1: remaining },
                                    )}
                        </p>
                    </>
                )}
            </Panel>

            <Panel title={consoleT('billing.allowance')}>
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-sm font-medium">{project.limit}</p>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleT('billing.allowance_description')}
                        </p>
                    </div>
                    {billingHref && (
                        <Button size="sm" asChild>
                            <a href={billingHref}>{consoleT('console.billing_3ac8bbca')}</a>
                        </Button>
                    )}
                </div>
            </Panel>

            {canManage && (
                <Panel title={consoleT('console.settings_74a883a0')}>
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
                            label={consoleT('console.project_name_25498193')}
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
                            {consoleT('console.save_1509f561')}
                        </Button>
                    </form>

                    <div
                        className="mt-5 pt-5 flex items-center justify-between gap-4"
                        style={{ borderTop: '1px solid var(--border)' }}
                    >
                        <div>
                            <p className="text-sm font-medium">
                                {project.suspended
                                    ? consoleText('console.reactivate_project_8c8f7a7b')
                                    : consoleText('console.suspend_project_9e1e10fb')}
                            </p>
                            <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                                {project.suspended
                                    ? consoleText(
                                          'console.bring_the_project_back_new_environments_can_be_added_ag_2b5117fe',
                                      )
                                    : consoleText(
                                          'console.existing_environments_stay_live_but_no_new_ones_can_be_0becaa18',
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
                                {consoleT('console.reactivate_2dc7478f')}
                            </Button>
                        ) : (
                            <>
                                <Button
                                    size="sm"
                                    className="shrink-0"
                                    style={{ color: 'var(--destructive)' }}
                                    onClick={() => setSuspending(true)}
                                >
                                    {consoleT('console.suspend_4948e134')}
                                </Button>

                                <ConfirmDelete
                                    open={suspending}
                                    onOpenChange={setSuspending}
                                    name={project.name}
                                    verb={consoleShortText('console.suspend_4948e134')}
                                    consequence={consoleUiText(
                                        'console.existing_environments_stay_live_but_no_new_ones_can_be_ed87b1df',
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
