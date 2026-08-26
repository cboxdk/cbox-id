import { Link, router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, ConfirmDelete, EmptyState, Field, Icon, Input, Panel, Select } from '@/ui';
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
    const [suspending, setSuspending] = useState(false);

    const environmentForm = useForm({ name: '', type: 'production' });
    const nameForm = useForm({ name: project.name });

    const full = remaining <= 0;

    return (
        <div className="space-y-6">
            <div>
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
                title="Environments"
                description={
                    'Each is an isolated stage — production or sandbox — with its own users, keys and sign-in. Name one "Staging" for a pre-production stage.'
                }
                action={
                    scoped ? undefined : (
                        <span className="text-xs shrink-0" style={{ color: 'var(--faint)' }}>
                            {environments.length} of {project.limit} used
                        </span>
                    )
                }
            >
                <div className="space-y-2">
                    {environments.length === 0 ? (
                        <EmptyState
                            icon="layers"
                            title="No environments yet"
                            description="Create an environment below to start issuing keys and sign-ins."
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
                                        {environment.sandbox && <Badge tone="warn">Sandbox</Badge>}
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
                                        Open
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
                                    label={<span className="sr-only">Environment name</span>}
                                    error={environmentForm.errors.name}
                                >
                                    <Input
                                        name="name"
                                        placeholder="Staging"
                                        disabled={full}
                                        value={environmentForm.data.name}
                                        onChange={(event) =>
                                            environmentForm.setData('name', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>

                            <Field
                                label={<span className="sr-only">Environment type</span>}
                                error={environmentForm.errors.type}
                            >
                                <Select
                                    value={environmentForm.data.type}
                                    onValueChange={(type) => environmentForm.setData('type', type)}
                                    disabled={full}
                                    options={[
                                        { value: 'production', label: 'Production' },
                                        { value: 'sandbox', label: 'Sandbox' },
                                    ]}
                                    aria-label="Environment type"
                                />
                            </Field>

                            <Button
                                type="submit"
                                variant="primary"
                                disabled={full}
                                loading={environmentForm.processing}
                            >
                                Add environment
                            </Button>
                        </form>

                        <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                            {full
                                ? 'This project has used every environment its plan allows. Upgrade its plan to add more.'
                                : `Sandbox environments allow localhost URLs and never send real email. ${remaining} remaining on this project's plan.`}
                        </p>
                    </>
                )}
            </Panel>

            {/*
                The plan sits on the PROJECT, because the project is the billing anchor:
                one account, separately-billed products.
            */}
            <Panel title="Plan">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-sm font-medium">
                            Early access{' '}
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                — free
                            </span>
                        </p>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            Up to {project.limit} environments. Billing per project arrives with
                            general availability.
                        </p>
                    </div>
                    <Button size="sm" className="shrink-0" disabled style={{ opacity: 0.6 }}>
                        Upgrade (soon)
                    </Button>
                </div>
            </Panel>

            {canManage && (
                <Panel title="Settings">
                    <form
                        className="grid sm:grid-cols-[1fr_auto] gap-2 items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            nameForm.patch(rename.url({ project: project.id }), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Field label="Project name" error={nameForm.errors.name}>
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
                            Save
                        </Button>
                    </form>

                    <div
                        className="mt-5 pt-5 flex items-center justify-between gap-4"
                        style={{ borderTop: '1px solid var(--border)' }}
                    >
                        <div>
                            <p className="text-sm font-medium">
                                {project.suspended ? 'Reactivate project' : 'Suspend project'}
                            </p>
                            <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                                {project.suspended
                                    ? 'Bring the project back — new environments can be added again.'
                                    : 'Existing environments stay live, but no new ones can be added until reactivated.'}
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
                                Reactivate
                            </Button>
                        ) : (
                            <>
                                <Button
                                    size="sm"
                                    className="shrink-0"
                                    style={{ color: 'var(--destructive)' }}
                                    onClick={() => setSuspending(true)}
                                >
                                    Suspend
                                </Button>

                                <ConfirmDelete
                                    open={suspending}
                                    onOpenChange={setSuspending}
                                    name={project.name}
                                    verb="Suspend"
                                    consequence="Existing environments stay live, but no new ones can be added until it is reactivated."
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
