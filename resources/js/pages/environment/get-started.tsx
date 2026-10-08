import { Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
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

interface Quickstart {
    install: string;
    envFile: string;
    env: string;
    codeFile: string;
    code: string;
    run: string;
}

type Props = PageProps<{
    checklist: ChecklistProps;
    dismissed: boolean;
    frameworks: FrameworkOption[];
    framework: string | null;
    createdApp: { id: string; name: string; clientId: string; href: string } | null;
    quickstart: Quickstart | null;
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
    checklist,
    dismissed,
    frameworks,
    framework,
    createdApp: app,
    quickstart,
    secretPlaceholder,
    signedIn,
    urls,
}: Props) {
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
                description="From nothing to somebody signed in: pick a framework, paste three things, run it."
                actions={
                    dismissed ? (
                        <Button size="sm" onClick={() => router.delete(urls.restore)}>
                            Show on Overview again
                        </Button>
                    ) : (
                        <Button size="sm" onClick={() => router.post(urls.dismiss)}>
                            Hide from Overview
                        </Button>
                    )
                }
            />

            <div className="mt-6 space-y-4">
                <Panel
                    title="1. What are you building?"
                    description="It decides the kind of app and its localhost redirect — the port your framework's dev server uses."
                >
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(urls.createApp, { preserveScroll: true });
                        }}
                    >
                        <RadioGroup
                            label="Framework"
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
                            label="App name"
                            hint={`Optional. Signs people back in at ${chosen?.redirectUri ?? 'localhost'}.`}
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder={`My ${chosen?.label ?? ''} app`}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <div className="flex flex-wrap items-start gap-2">
                            <Button type="submit" variant="primary" loading={form.processing}>
                                {app === null ? 'Create the app' : 'Create another app'}
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
                        title="2. Wire it up"
                        description={`${app.name} — client ID ${app.clientId}.`}
                    >
                        <div className="space-y-4">
                            <div>
                                <h3 className="text-sm font-medium">Install</h3>
                                <div className="mt-2">
                                    <CodeBlock
                                        code={quickstart.install}
                                        copyLabel="Copy install command"
                                    />
                                </div>
                            </div>

                            <div>
                                <h3 className="text-sm font-medium">
                                    <code className="mono">{quickstart.envFile}</code>
                                </h3>
                                <div className="mt-2">
                                    <CodeBlock
                                        code={env}
                                        copyLabel="Copy environment"
                                        caption={
                                            !holdsSecret ? (
                                                'A browser app holds no secret — PKCE proves the callback is the one that asked.'
                                            ) : secret !== undefined ? (
                                                'The client secret is in this block once. Copy it now — leave this page and it is gone.'
                                            ) : (
                                                <>
                                                    The secret was shown once, when the app was
                                                    created.{' '}
                                                    <Link
                                                        href={`${app.href}/secrets`}
                                                        className="underline"
                                                        style={{ color: 'var(--accent-strong)' }}
                                                    >
                                                        Rotate it
                                                    </Link>{' '}
                                                    for a new one.
                                                </>
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div>
                                <h3 className="text-sm font-medium">
                                    <code className="mono">{quickstart.codeFile}</code>
                                </h3>
                                <div className="mt-2">
                                    <CodeBlock code={quickstart.code} copyLabel="Copy code" />
                                </div>
                            </div>

                            <div>
                                <h3 className="text-sm font-medium">Run it, then sign in</h3>
                                <div className="mt-2">
                                    <CodeBlock code={quickstart.run} copyLabel="Copy run command" />
                                </div>
                            </div>
                        </div>
                    </Panel>
                )}

                {app !== null && (
                    <Panel title="3. Sign in">
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
                                        Somebody signed in to{' '}
                                        <Link
                                            href={app.href}
                                            className="underline"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            {app.name}
                                        </Link>
                                        . It works.
                                    </p>
                                </>
                            ) : (
                                <>
                                    <Spinner />
                                    <p
                                        className="text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        Waiting for your first sign-in…
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
