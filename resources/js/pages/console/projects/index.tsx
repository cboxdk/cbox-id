import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, Field, Icon, Input, PageHeader, Select } from '@/ui';
import {
    resendVerification,
    storeEnvironment,
} from '@actions/App/Http/Controllers/Console/ProjectController';

interface EnvironmentRow {
    id: string;
    name: string;
    type: string;
    host: string;
    openHref: string;
}

interface ProjectRow {
    id: string;
    name: string;
    status: string;
    limit: number;
    used: number;
    atLimit: boolean;
    settingsHref: string;
    environments: EnvironmentRow[];
}

type Props = PageProps<{
    projects: ProjectRow[];
    canManage: boolean;
    createHref: string;
    awaitingVerification: boolean;
    verificationEmail: string;
    verificationSender: string;
}>;

export default function Projects({
    projects,
    canManage,
    createHref,
    awaitingVerification,
    verificationEmail,
    verificationSender,
}: Props) {
    // The answer to the resend click, on the flash channel: it belongs to that click and
    // to this render, and it is emphatically not the console's generic success toast.
    const resendNotice = usePage().flash.resendNotice;

    const [creatingIn, setCreatingIn] = useState<string | null>(null);
    const [resending, setResending] = useState(false);

    return (
        <>
            <PageHeader
                description="Each project is a separate IdP product — its own environments, sign-in, and plan."
                actions={
                    canManage ? (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                <Icon name="plus" className="w-4 h-4" />
                                New project
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            {/*
                No live region on the banner: it is present from the first render, and a
                live region only announces what CHANGES — one that ships with its text is
                read by the page's own reading order rather than announced on top of it.
            */}
            {awaitingVerification && (
                <div
                    className="mt-6 rounded-xl border p-4"
                    style={{ borderColor: 'var(--border)' }}
                >
                    <p className="font-medium">Confirm your email to finish</p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        We sent a link to <span className="mono">{verificationEmail}</span>. Your
                        first environment — your live IdP, with its own sign-in, users and signing
                        keys — is created the moment you open it, and you land straight back here.
                        The link stays valid for 24 hours.
                    </p>
                    <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <Button
                            variant="secondary"
                            size="sm"
                            className="shrink-0"
                            loading={resending}
                            onClick={() => {
                                router.post(
                                    resendVerification.url(),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setResending(true),
                                        onFinish: () => setResending(false),
                                    },
                                );
                            }}
                        >
                            Send the link again
                        </Button>
                        {verificationSender !== '' && (
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                Nothing in your inbox? It comes from{' '}
                                <span className="mono">{verificationSender}</span> — check spam too.
                            </span>
                        )}
                    </div>
                </div>
            )}

            {/*
                Outside the banner on purpose: a resend that lands just as the environment
                is released (or after) makes the banner disappear, and the answer to a
                click must not disappear with it.

                MOUNTED WHILE EMPTY, and an `<output>` rather than a `<p role="status">`:
                it is the element the platform already maps to that role, and a live
                region inserted carrying its text registers as a new subtree rather than
                an update — NVDA and VoiceOver then stay silent about the one sentence
                somebody pressed a button to hear.
            */}
            <output
                className={resendNotice === undefined ? undefined : 'mt-3 block text-sm'}
                style={{ color: 'var(--muted-foreground)' }}
                data-resend-notice
            >
                {resendNotice ?? ''}
            </output>

            <div className="mt-6 space-y-4">
                {projects.length === 0 ? (
                    <div
                        className="rounded-xl border p-8 text-center"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <p className="font-medium">No projects yet</p>
                        <p
                            className="mx-auto mt-1 max-w-md text-sm"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            A <strong>project</strong> is one product's IdP. It holds isolated{' '}
                            <strong>environments</strong> — production and sandbox — each with its
                            own users, keys and sign-in, and is billed on its own plan.
                        </p>
                        {canManage && (
                            <Button asChild variant="primary" size="sm" className="mt-4">
                                <Link href={createHref}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    Create your first project
                                </Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    projects.map((project) => (
                        <ProjectCard
                            key={project.id}
                            project={project}
                            canManage={canManage}
                            creating={creatingIn === project.id}
                            onStartCreate={() => setCreatingIn(project.id)}
                            onCancelCreate={() => setCreatingIn(null)}
                        />
                    ))
                )}
            </div>
        </>
    );
}

function ProjectCard({
    project,
    canManage,
    creating,
    onStartCreate,
    onCancelCreate,
}: {
    project: ProjectRow;
    canManage: boolean;
    creating: boolean;
    onStartCreate: () => void;
    onCancelCreate: () => void;
}) {
    return (
        <section className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
            {/* The project's own identity and controls only — environment work is on the rows. */}
            <header className="flex items-center gap-3 px-5 py-4">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h2 className="font-medium truncate">{project.name}</h2>
                        {project.status !== 'active' && <Badge tone="warn">{project.status}</Badge>}
                    </div>
                    <p className="mt-0.5 text-xs" style={{ color: 'var(--faint)' }}>
                        <span className="tabular-nums">
                            {project.used} of {project.limit}
                        </span>{' '}
                        {project.limit === 1 ? 'environment' : 'environments'}
                    </p>
                </div>

                {canManage && (
                    <Button
                        asChild
                        size="sm"
                        className="shrink-0"
                        aria-label={`Project settings for ${project.name}`}
                    >
                        <Link href={project.settingsHref} title="Project settings">
                            <Icon name="settings" className="w-4 h-4" />
                        </Link>
                    </Button>
                )}
            </header>

            {/* Environments — what people actually come here to open. */}
            <ul className="border-t" style={{ borderColor: 'var(--border)' }}>
                {project.environments.length === 0 ? (
                    <li className="px-5 py-3 text-sm" style={{ color: 'var(--faint)' }}>
                        No environments you can reach in this project.
                    </li>
                ) : (
                    project.environments.map((environment) => (
                        <li key={environment.id} className="flex items-center gap-3 px-5 py-3">
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="text-sm truncate">{environment.name}</span>
                                    <Badge>{environment.type}</Badge>
                                </div>
                                <p
                                    className="mt-0.5 text-xs mono truncate"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {environment.host}
                                </p>
                            </div>

                            <Button asChild variant="secondary" size="sm" className="shrink-0">
                                {/*
                                    A plain anchor: the handoff answers with a signed
                                    redirect to another host, which is a navigation and not
                                    a page this client can mount.
                                */}
                                <a href={environment.openHref}>Open</a>
                            </Button>
                        </li>
                    ))
                )}
            </ul>

            {canManage && (
                <div className="border-t px-5 py-3" style={{ borderColor: 'var(--border)' }}>
                    {creating ? (
                        <NewEnvironmentForm project={project} onCancel={onCancelCreate} />
                    ) : project.atLimit ? (
                        <p className="text-xs" style={{ color: 'var(--faint)' }}>
                            This project is at its environment limit. Upgrade its plan to add more.
                        </p>
                    ) : (
                        <Button size="sm" onClick={onStartCreate}>
                            <Icon name="plus" className="w-4 h-4" />
                            New environment
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

/** Add an environment without leaving the launchpad. */
function NewEnvironmentForm({ project, onCancel }: { project: ProjectRow; onCancel: () => void }) {
    const form = useForm({ name: '', type: 'production' });

    return (
        <form
            className="flex flex-wrap items-start gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storeEnvironment.url({ project: project.id }), {
                    preserveScroll: true,
                    onSuccess: onCancel,
                });
            }}
        >
            <div className="min-w-[12rem] flex-1">
                <Field
                    label={<span className="sr-only">Environment name</span>}
                    error={form.errors.name}
                >
                    <Input
                        name="name"
                        placeholder="Staging"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <Field
                label={<span className="sr-only">Environment type</span>}
                error={form.errors.type}
            >
                <Select
                    value={form.data.type}
                    onValueChange={(type) => form.setData('type', type)}
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
                size="sm"
                className="shrink-0"
                loading={form.processing}
            >
                Create
            </Button>
            <Button type="button" size="sm" className="shrink-0" onClick={onCancel}>
                Cancel
            </Button>
        </form>
    );
}

Projects.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
