import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, Field, Icon, Input, PageHeader, Select } from '@/ui';
import { resend as resendVerification } from '@routes/projects/verification';
import { store as storeEnvironment } from '@routes/projects/environments';

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
    help: HelpContent;
    billingHref: string | null;
    manageBillingLabel: string;
}>;

export default function Projects({
    projects,
    canManage,
    createHref,
    awaitingVerification,
    verificationEmail,
    verificationSender,
    help,
    billingHref,
    manageBillingLabel,
}: Props) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // The answer to the resend click, on the flash channel: it belongs to that click and
    // to this render, and it is emphatically not the console's generic success toast.
    const resendNotice = usePage().flash.resendNotice;

    const [creatingIn, setCreatingIn] = useState<string | null>(null);
    const [resending, setResending] = useState(false);

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.each_project_is_a_separate_idp_product_its_own_environm_a43fd658',
                )}
                actions={
                    canManage ? (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                {consoleRich('console._part1_new_project_4dad9d0e', {
                                    part1: <Icon name="plus" className="w-4 h-4" />,
                                })}
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
                    <p className="font-medium">
                        {consoleT('console.confirm_your_email_to_finish_7ea7f091')}
                    </p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console.we_sent_a_link_to_part1_your_first_environment_your_liv_9786541d',
                            { part1: <span className="mono">{verificationEmail}</span> },
                        )}
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
                            {consoleT('console.send_the_link_again_744497a4')}
                        </Button>
                        {verificationSender !== '' && (
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                {consoleRich(
                                    'console.nothing_in_your_inbox_it_comes_from_part1_part2_check_s_9f4e6c12',
                                    {
                                        part1: ' ',
                                        part2: <span className="mono">{verificationSender}</span>,
                                    },
                                )}
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

            {!awaitingVerification && billingHref && projects.some((p) => p.limit === 0) && (
                <div className="mt-4">
                    <Button asChild>
                        <Link href={billingHref}>{manageBillingLabel}</Link>
                    </Button>
                </div>
            )}
            <div className="mt-6 space-y-4">
                {projects.length === 0 ? (
                    <div
                        className="rounded-xl border p-8 text-center"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <p className="font-medium">
                            {consoleT('console.no_projects_yet_f83c8065')}
                        </p>
                        <p
                            className="mx-auto mt-1 max-w-md text-sm"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {consoleRich(
                                'console.a_part1_is_one_product_s_idp_it_holds_isolated_part2_pa_c2f6b974',
                                {
                                    part1: <strong>{consoleT('console.project_244210e4')}</strong>,
                                    part2: ' ',
                                    part3: (
                                        <strong>{consoleT('console.environments_d5f6d542')}</strong>
                                    ),
                                },
                            )}
                        </p>
                        {canManage && (
                            <Button asChild variant="primary" size="sm" className="mt-4">
                                <Link href={createHref}>
                                    {consoleRich(
                                        'console._part1_create_your_first_project_ad34009d',
                                        { part1: <Icon name="plus" className="w-4 h-4" /> },
                                    )}
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                            {consoleRich('console._part1_of_part2_934043a7', {
                                part1: project.used,
                                part2: project.limit,
                            })}
                        </span>{' '}
                        {project.limit === 1
                            ? consoleText('console.environment_ba528516')
                            : consoleText('console.environments_d5f6d542')}
                    </p>
                </div>

                {canManage && (
                    <Button
                        asChild
                        size="sm"
                        className="shrink-0"
                        aria-label={consoleFinalText(
                            'console.project_settings_for_part1_98d6dd2b',
                            { part1: project.name },
                        )}
                    >
                        <Link
                            href={project.settingsHref}
                            title={consoleT('console.project_settings_fccd73a6')}
                        >
                            <Icon name="settings" className="w-4 h-4" />
                        </Link>
                    </Button>
                )}
            </header>

            {/* Environments — what people actually come here to open. */}
            <ul className="border-t" style={{ borderColor: 'var(--border)' }}>
                {project.environments.length === 0 ? (
                    <li className="px-5 py-3 text-sm" style={{ color: 'var(--faint)' }}>
                        {consoleT('console.no_environments_you_can_reach_in_this_project_a11ab500')}
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
                                <a
                                    href={environment.openHref}
                                    aria-label={consoleFinalText(
                                        'console.open_the_part1_console_3bbc1cbc',
                                        { part1: environment.name },
                                    )}
                                >
                                    {consoleT('console.open_console_f4063d1b')}
                                </a>
                            </Button>
                        </li>
                    ))
                )}
            </ul>

            {canManage && (
                <div className="border-t px-5 py-3" style={{ borderColor: 'var(--border)' }}>
                    {project.status !== 'active' ? (
                        <p className="text-xs" style={{ color: 'var(--faint)' }}>
                            {tc('console.existing_environments_stay_live_but_no_new_o_0becaa18')}
                        </p>
                    ) : creating ? (
                        <NewEnvironmentForm project={project} onCancel={onCancelCreate} />
                    ) : project.atLimit ? (
                        <p className="text-xs" style={{ color: 'var(--faint)' }}>
                            {consoleT(
                                'console.this_project_is_at_its_environment_limit_upgrade_its_pl_c7856d59',
                            )}
                        </p>
                    ) : (
                        <Button size="sm" onClick={onStartCreate}>
                            {consoleRich('console._part1_new_environment_3f6f97e1', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

/** Add an environment without leaving the launchpad. */
function NewEnvironmentForm({ project, onCancel }: { project: ProjectRow; onCancel: () => void }) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                    label={
                        <span className="sr-only">
                            {consoleT('console.environment_name_03cb80d1')}
                        </span>
                    }
                    error={form.errors.name}
                >
                    <Input
                        name="name"
                        placeholder={consoleUiText('console.staging_a8e7ac92')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <Field
                label={
                    <span className="sr-only">{consoleT('console.environment_type_7c9f3eba')}</span>
                }
                error={form.errors.type}
            >
                <Select
                    value={form.data.type}
                    onValueChange={(type) => form.setData('type', type)}
                    options={[
                        { value: 'production', label: consoleText('console.production_13a7c655') },
                        { value: 'sandbox', label: consoleText('console.sandbox_67fc6249') },
                    ]}
                    aria-label={consoleT('console.environment_type_7c9f3eba')}
                />
            </Field>

            <Button
                type="submit"
                variant="primary"
                size="sm"
                className="shrink-0"
                loading={form.processing}
            >
                {consoleT('console.create_4759498a')}
            </Button>
            <Button type="button" size="sm" className="shrink-0" onClick={onCancel}>
                {consoleT('console.cancel_19766ed6')}
            </Button>
        </form>
    );
}

Projects.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
