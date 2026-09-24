import { Link, useForm, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import {
    Badge,
    Button,
    CopyButton,
    Dialog,
    DialogClose,
    Field,
    Icon,
    Input,
    type LinkTab,
    LinkTabs,
    Select,
    Textarea,
} from '@/ui';

/** `App\Http\Props\Console\AppHeaderProps` */
export interface AppHeaderData {
    id: string;
    name: string;
    clientId: string;
    confidential: boolean;
    firstParty: boolean;
    kindLabel: string;
    tabs: LinkTab[];
    indexHref: string;
    /** Null for somebody who may only look at the app. */
    blueprintHref: string | null;
    /** Null outside the environment console. */
    copy: {
        href: string;
        targets: { id: string; name: string; kind: string }[];
        redirectUris: string;
        /** Why this app cannot be copied, when it cannot. */
        unavailable: string | null;
    } | null;
}

/**
 * WHAT EVERY TAB OF AN APP'S PAGE DRAWS FIRST: the way back to the list, the app's name,
 * the two ways to take it somewhere else, the tabs — and whichever credential was just
 * revealed, because a secret minted on one tab must be shown on the page it lands on.
 */
export function AppFrame({
    app,
    issuer,
    children,
}: {
    app: AppHeaderData;
    /** The environment's issuer, when the page knows it — shown beside a revealed secret. */
    issuer?: string;
    children: ReactNode;
}) {
    // The plaintext, on the flash channel and nowhere else: page props are written into
    // the browser's history entry, where a live credential outlives the page that showed
    // it and is retrievable by pressing Back.
    const flash = usePage().flash;
    const [copying, setCopying] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Link
                    href={app.indexHref}
                    className="text-sm inline-flex items-center gap-1"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-3.5 h-3.5"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Apps
                </Link>
                <div className="mt-2 flex items-start justify-between gap-3 flex-wrap">
                    <div style={{ minWidth: 0 }}>
                        <div className="flex items-center gap-3 flex-wrap">
                            <h1 className="cbx-page-title" style={{ overflowWrap: 'anywhere' }}>
                                {app.name}
                            </h1>
                            {app.firstParty && (
                                <span
                                    className="text-xs rounded-full px-2 py-0.5"
                                    style={{
                                        background: 'var(--accent-soft)',
                                        color: 'var(--accent-strong)',
                                    }}
                                >
                                    First-party
                                </span>
                            )}
                            <Badge>{app.confidential ? 'Confidential' : 'Public'}</Badge>
                        </div>
                        <p
                            className="mt-1 text-sm mono"
                            style={{ color: 'var(--faint)', overflowWrap: 'anywhere' }}
                        >
                            {app.clientId}
                        </p>
                    </div>

                    {(app.blueprintHref !== null || app.copy !== null) && (
                        <div className="flex flex-wrap gap-2">
                            {app.blueprintHref !== null && (
                                // A plain link, not an Inertia visit: the answer is a file.
                                <Button asChild size="sm">
                                    <a href={app.blueprintHref} download>
                                        <Icon name="download" className="w-4 h-4" />
                                        Download blueprint
                                    </a>
                                </Button>
                            )}
                            {app.copy !== null && (
                                <Button size="sm" onClick={() => setCopying(true)}>
                                    <Icon name="copy" className="w-4 h-4" />
                                    Copy to another environment
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {flash.revealedSecret !== undefined && (
                <RevealedSecret
                    secret={flash.revealedSecret}
                    issuer={issuer}
                    clientId={app.clientId}
                />
            )}

            {flash.copiedApp !== undefined && <CopiedApp copied={flash.copiedApp} />}

            <LinkTabs tabs={app.tabs} label="App pages" />

            {children}

            {app.copy !== null && (
                <CopyDialog
                    open={copying}
                    onOpenChange={setCopying}
                    name={app.name}
                    copy={app.copy}
                />
            )}
        </div>
    );
}

/**
 * The dialog behind "Copy to another environment".
 *
 * The redirect URIs are in it, prefilled, because they are the part of an app that is
 * almost always different in the other environment — and a copy that silently kept
 * staging's callback would send production's sign-ins to staging.
 */
function CopyDialog({
    open,
    onOpenChange,
    name,
    copy,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    name: string;
    copy: NonNullable<AppHeaderData['copy']>;
}) {
    const form = useForm({
        environment: copy.targets[0]?.id ?? '',
        name,
        redirectUris: copy.redirectUris,
    });

    const submit = (): void => {
        form.post(copy.href, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Copy to another environment"
            description="Registers this app again in another environment of this project, from its blueprint — with a client ID and secret of its own. Nothing here changes."
            footer={
                copy.unavailable === null ? (
                    <>
                        <DialogClose asChild>
                            <Button>Cancel</Button>
                        </DialogClose>
                        <Button variant="primary" loading={form.processing} onClick={submit}>
                            Copy app
                        </Button>
                    </>
                ) : (
                    <DialogClose asChild>
                        <Button>Close</Button>
                    </DialogClose>
                )
            }
        >
            {copy.unavailable !== null ? (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {copy.unavailable}
                </p>
            ) : (
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <Field label="Environment" error={form.errors.environment}>
                        <Select
                            value={form.data.environment}
                            onValueChange={(value) => form.setData('environment', value)}
                            options={copy.targets.map((target) => ({
                                value: target.id,
                                label: target.name,
                                hint: target.kind,
                            }))}
                        />
                    </Field>
                    <Field label="Name there" error={form.errors.name}>
                        <Input
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>
                    <Field
                        label="Redirect URIs there"
                        hint="One per line. The other environment's own addresses — what is here now is this environment's."
                        error={form.errors.redirectUris}
                    >
                        <Textarea
                            name="redirectUris"
                            rows={3}
                            className="mono"
                            spellCheck={false}
                            value={form.data.redirectUris}
                            onChange={(event) => form.setData('redirectUris', event.target.value)}
                        />
                    </Field>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        Scopes, sign-out URIs, token settings and the roles manifest URL are copied
                        as they are. Secrets, keys and the people who use the app are not: those
                        belong to the environment they were created in.
                    </p>
                </form>
            )}
        </Dialog>
    );
}

/**
 * The one moment the plaintext exists.
 *
 * IT BRINGS ITSELF INTO VIEW. Rotation is triggered from further down a page and reveals
 * the new secret at the TOP of it, so the only feedback in the viewport was a toast in the
 * opposite corner. Focus moves too, so a screen reader lands on the heading rather than
 * being scrolled silently.
 */
function RevealedSecret({
    secret,
    issuer,
    clientId,
}: {
    secret: string;
    issuer?: string;
    clientId: string;
}) {
    const card = useRef<HTMLDivElement>(null);

    useEffect(() => {
        card.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        card.current?.focus({ preventScroll: true });
    }, []);

    return (
        <div
            ref={card}
            tabIndex={-1}
            className="rounded-xl border p-5"
            style={{
                borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)',
                background: 'var(--warning-soft)',
            }}
        >
            <p className="text-sm font-semibold" style={{ color: 'var(--warning-strong)' }}>
                Copy your client secret now
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Only a hash is stored, so it won't be shown again. If you lose it, rotate the secret
                to get a new one.
            </p>

            {/*
                A COPY BUTTON ON EVERY VALUE. Each of these is going into a config file or
                an environment variable, and one of them is shown exactly once — so "select
                it carefully with the mouse" is the wrong ask, and a mis-selected secret is
                unrecoverable rather than merely annoying.
            */}
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {issuer !== undefined && <CopyableValue label="Issuer" value={issuer} boxed />}
                <CopyableValue label="Client ID" value={clientId} boxed />
                <div className="sm:col-span-2">
                    <CopyableValue
                        label="Client secret — copy it now, it won't be shown again"
                        value={secret}
                        boxed
                        emphatic
                    />
                </div>
            </div>
        </div>
    );
}

/** The app a copy just registered in another environment, and its credentials — once. */
function CopiedApp({
    copied,
}: {
    copied: {
        environment: string;
        name: string;
        issuer: string;
        clientId: string;
        secret: string | null;
    };
}) {
    const card = useRef<HTMLDivElement>(null);

    useEffect(() => {
        card.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        card.current?.focus({ preventScroll: true });
    }, []);

    return (
        <div
            ref={card}
            tabIndex={-1}
            className="rounded-xl border p-5"
            style={{
                borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)',
                background: 'var(--warning-soft)',
            }}
        >
            <p className="text-sm font-semibold" style={{ color: 'var(--warning-strong)' }}>
                "{copied.name}" now exists in {copied.environment}
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                These are its own credentials, for that environment's configuration.
                {copied.secret !== null
                    ? ' Copy the secret now — only a hash is stored, so it won’t be shown again.'
                    : ''}
            </p>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <CopyableValue label="Issuer" value={copied.issuer} boxed />
                <CopyableValue label="Client ID" value={copied.clientId} boxed />
                {copied.secret !== null && (
                    <div className="sm:col-span-2">
                        <CopyableValue
                            label="Client secret — copy it now, it won't be shown again"
                            value={copied.secret}
                            boxed
                            emphatic
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

export function CopyableValue({
    label,
    value,
    hint,
    boxed = false,
    emphatic = false,
}: {
    label: ReactNode;
    value: string;
    hint?: ReactNode;
    boxed?: boolean;
    emphatic?: boolean;
}) {
    return (
        <div
            className={boxed ? 'rounded-lg p-3' : undefined}
            style={
                boxed
                    ? { background: 'var(--surface-2)', border: '1px solid var(--border)' }
                    : undefined
            }
        >
            <p
                className={boxed ? 'text-xs' : 'label'}
                style={
                    emphatic
                        ? { color: 'var(--warning-strong)', fontWeight: 600 }
                        : boxed
                          ? { color: 'var(--muted-foreground)' }
                          : undefined
                }
            >
                {label}
            </p>
            <div className="mt-1 flex items-start gap-2">
                <code className="mono text-sm break-all select-all flex-1">{value}</code>
                <CopyButton
                    value={value}
                    variant={emphatic ? 'primary' : 'ghost'}
                    label={emphatic ? 'Copy secret' : 'Copy'}
                />
            </div>
            {hint !== undefined && (
                <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                    {hint}
                </p>
            )}
        </div>
    );
}
