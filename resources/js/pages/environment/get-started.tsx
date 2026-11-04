import { useTranslator } from '@/i18n';
import { Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    ApiEquivalent,
    Button,
    CodeBlock,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
    RadioGroup,
    Spinner,
} from '@/ui';
import { type ChecklistProps, EnvironmentChecklist } from './checklist';

interface FrameworkOption {
    value: string;
    label: string;
    redirectUri: string;
    /** The app kind it becomes: `web`, or `spa` for a browser app that keeps no secret. */
    appKind: string;
    kind: string;
}

/** One copyable block: a command, or code that goes in `file`. */
interface QuickstartBlock {
    code: string;
    file: string | null;
    note: string | null;
}

/** `App\Platform\Connect\QuickstartSnippet` — block for block the framework's quickstart page. */
interface Quickstart {
    install: QuickstartBlock[];
    envFile: string;
    env: string;
    code: QuickstartBlock[];
    run: QuickstartBlock[];
}

type Props = PageProps<{
    help: HelpContent;
    checklist: ChecklistProps;
    dismissed: boolean;
    frameworks: FrameworkOption[];
    framework: string | null;
    createdApp: { id: string; name: string; clientId: string; href: string } | null;
    quickstart: Quickstart | null;
    /** The framework's whole quickstart page, sign-out included; null with no docs to link. */
    quickstartGuide: string | null;
    secretPlaceholder: string;
    signedIn: boolean;
    urls: { createApp: string; dismiss: string; restore: string; home: string };
}>;

/**
 * GET STARTED — framework, app, first sign-in, on one page.
 *
 * The page is the whole first run, so each step stays on screen once it is done: the
 * framework picked, the app created, the snippet to paste, and then the wait — which polls
 * until somebody actually signs in, because "you're set up" is only true once that happens.
 */
export default function GetStarted({
    help,
    checklist,
    dismissed,
    frameworks,
    framework,
    createdApp: app,
    quickstart,
    quickstartGuide,
    secretPlaceholder,
    signedIn,
    urls,
}: Props) {
    const { t: tc } = useTranslator();

    const flash = usePage().flash;
    const form = useForm({ framework: framework ?? frameworks[0]?.value ?? 'nextjs', name: '' });
    const chosen = frameworks.find((option) => option.value === form.data.framework);

    // Asked again every few seconds until a token is issued to a person for this app — a
    // partial reload of the one prop that changes, not the page.
    const poll = usePoll(3000, { only: ['signedIn', 'checklist'] }, { autoStart: false });
    // A ref, because the hook hands back a fresh object each render and the effect below
    // should run when the WAIT changes, not on every poll's answer.
    const poller = useRef(poll);
    const waiting = app !== null && !signedIn;

    useEffect(() => {
        poller.current = poll;
    });

    useEffect(() => {
        if (waiting) {
            poller.current.start();
        } else {
            poller.current.stop();
        }

        return () => poller.current.stop();
    }, [waiting]);

    // The secret exists once, on the flash channel of the response that created the app —
    // put into the block here, never sent back as a prop.
    const secret = flash.revealedSecret;
    const env =
        quickstart === null
            ? ''
            : secret !== undefined
              ? quickstart.env.replace(secretPlaceholder, secret)
              : quickstart.env;
    const holdsSecret = quickstart?.env.includes(secretPlaceholder) === true;

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.from_nothing_to_somebody_signed_in_pick_a_fr_93a5a3cb')}
                actions={
                    dismissed ? (
                        <Button size="sm" onClick={() => router.delete(urls.restore)}>
                            {tc('console.show_on_overview_again_7dc29bf7')}
                        </Button>
                    ) : (
                        <Button size="sm" onClick={() => router.post(urls.dismiss)}>
                            {tc('console.hide_from_overview_d715de16')}
                        </Button>
                    )
                }
            />

            <div className="mt-6 space-y-4">
                <Panel
                    title={tc('console.1_what_are_you_building_61edf71c')}
                    description={tc(
                        'console.it_decides_the_kind_of_app_and_its_localhost_0f18e4ab',
                    )}
                >
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(urls.createApp, { preserveScroll: true });
                        }}
                    >
                        <RadioGroup
                            label={tc('console.framework_192eb452')}
                            name="framework"
                            value={form.data.framework}
                            onValueChange={(value) => form.setData('framework', value)}
                            className="grid gap-2 sm:grid-cols-3"
                            options={frameworks.map((option) => ({
                                value: option.value,
                                label: option.label,
                                hint: option.kind,
                            }))}
                        />
                        {form.errors.framework !== undefined && (
                            <p className="text-sm" style={{ color: 'var(--destructive)' }}>
                                {form.errors.framework}
                            </p>
                        )}

                        <Field
                            label={tc('console.app_name_e6ad3996')}
                            hint={tc('console.optional_signs_people_back_in_at_value0_1a281c15', {
                                value0: chosen?.redirectUri ?? 'localhost',
                            })}
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder={tc('console.my_value0_app_5be6f7c5', {
                                    value0: chosen?.label ?? '',
                                })}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <div className="flex flex-wrap items-start gap-2">
                            <Button type="submit" variant="primary" loading={form.processing}>
                                {app === null
                                    ? tc('console.create_the_app_169cba28')
                                    : tc('console.create_another_app_c82e820b')}
                            </Button>
                            <ApiEquivalent
                                action="apps.create"
                                values={{
                                    name: form.data.name || `My ${chosen?.label ?? ''} app`,
                                    type: chosen?.appKind ?? 'web',
                                    redirect_uris: chosen === undefined ? [] : [chosen.redirectUri],
                                }}
                            />
                        </div>
                    </form>
                </Panel>

                {app !== null && quickstart !== null && (
                    <Panel
                        title={tc('console.2_wire_it_up_015fee56')}
                        description={tc('console.value0_client_id_value1_8df38a62', {
                            value0: app.name,
                            value1: app.clientId,
                        })}
                    >
                        <div className="space-y-4">
                            <QuickstartBlocks
                                title={tc('console.install_569ca49f')}
                                blocks={quickstart.install}
                                copyLabel={tc('console.copy_install_command_d8222d32')}
                            />

                            <div>
                                <h3 className="text-sm font-medium">
                                    <code className="mono">{quickstart.envFile}</code>
                                </h3>
                                <div className="mt-2">
                                    <CodeBlock
                                        code={env}
                                        copyLabel={tc('console.copy_environment_7cf9c703')}
                                        caption={
                                            !holdsSecret ? (
                                                tc(
                                                    'console.a_browser_app_holds_no_secret_pkce_proves_th_d7de5db8',
                                                )
                                            ) : secret !== undefined ? (
                                                tc(
                                                    'console.the_client_secret_is_in_this_block_once_copy_b4bc2f5a',
                                                )
                                            ) : (
                                                <>
                                                    {tc(
                                                        'console.the_secret_was_shown_once_when_the_app_was_c_8ad44d40',
                                                    )}{' '}
                                                    <Link
                                                        href={`${app.href}/secrets`}
                                                        className="underline"
                                                        style={{ color: 'var(--accent-strong)' }}
                                                    >
                                                        {tc('console.rotate_it_0186f051')}
                                                    </Link>{' '}
                                                    {tc('console.for_a_new_one_83725c4b')}
                                                </>
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <QuickstartBlocks
                                title={tc('console.code_340f4630')}
                                blocks={quickstart.code}
                                copyLabel={tc('console.copy_code_49a0053f')}
                            />

                            <QuickstartBlocks
                                title={tc('console.run_it_then_sign_in_7fe05c2d')}
                                blocks={quickstart.run}
                                copyLabel={tc('console.copy_run_command_2b33dad0')}
                            />

                            {quickstartGuide !== null && (
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {tc(
                                        'console.the_same_code_step_by_step_with_sign_out_9ff1e4ca',
                                    )}{' '}
                                    <a
                                        href={quickstartGuide}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        {tc('console.the_full_quickstart_dd59dd4d')}
                                    </a>
                                    .
                                </p>
                            )}
                        </div>
                    </Panel>
                )}

                {app !== null && (
                    <Panel title={tc('console.3_sign_in_f3e9ef96')}>
                        {/* Announced: the wait ends on its own, with no focus change. */}
                        <output aria-live="polite" className="flex items-center gap-3">
                            {signedIn ? (
                                <>
                                    <span
                                        className="grid place-items-center rounded-full shrink-0"
                                        style={{
                                            width: '1.75rem',
                                            height: '1.75rem',
                                            background: 'var(--success-soft)',
                                            color: 'var(--success-strong)',
                                        }}
                                        aria-hidden="true"
                                    >
                                        <Icon name="check" className="w-4 h-4" />
                                    </span>
                                    <p className="text-sm">
                                        {tc('console.somebody_signed_in_to_b6ff4be1')}{' '}
                                        <Link
                                            href={app.href}
                                            className="underline"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            {app.name}
                                        </Link>
                                        {tc('console.it_works_42621b4c')}
                                    </p>
                                </>
                            ) : (
                                <>
                                    <Spinner />
                                    <p
                                        className="text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {tc('console.waiting_for_your_first_sign_in_f35b71a6')}
                                    </p>
                                </>
                            )}
                        </output>
                    </Panel>
                )}

                <EnvironmentChecklist checklist={checklist} />
            </div>
        </>
    );
}

GetStarted.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;

/**
 * A titled run of copyable blocks — the commands to install, the files to write, the
 * command to run — each under the file it goes in, in the quickstart page's order.
 */
function QuickstartBlocks({
    title,
    blocks,
    copyLabel,
}: {
    title: string;
    blocks: QuickstartBlock[];
    copyLabel: string;
}) {
    return (
        <div>
            <h3 className="text-sm font-medium">{title}</h3>
            <div className="mt-2 space-y-3">
                {blocks.map((block) => (
                    // No two blocks of one list are the same text.
                    <div key={block.code}>
                        {block.file !== null && (
                            <p className="text-xs mb-1">
                                <code className="mono">{block.file}</code>
                            </p>
                        )}
                        <CodeBlock
                            code={block.code}
                            copyLabel={copyLabel}
                            caption={block.note ?? undefined}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
}
