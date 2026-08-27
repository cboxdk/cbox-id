import { useForm, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, PageHeader } from '@/ui';

interface ScopeRow {
    scope: string;
    label: string;
}

type Props = PageProps<{
    client: { name: string; scopes: ScopeRow[] } | null;
    me: { name: string; email: string | null; initial: string };
    urls: { lookup: string; approve: string; deny: string };
}>;

export default function Device({ client, me, urls }: Props) {
    const { deviceOutcome, deviceError } = usePage().flash;

    return (
        <div style={{ maxWidth: '28rem' }}>
            <PageHeader description="Enter the code shown on your device to link it to your account." />

            <div className="mt-8">
                {deviceOutcome === 'approved' ? (
                    <Outcome
                        icon
                        title="Device connected"
                        body="You can return to your device — it's now signed in."
                    />
                ) : deviceOutcome === 'denied' ? (
                    <Outcome body="Request denied. The device was not connected." />
                ) : client !== null ? (
                    <Consent client={client} me={me} urls={urls} />
                ) : (
                    <CodeForm href={urls.lookup} error={deviceError} />
                )}
            </div>
        </div>
    );
}

function Outcome({ icon = false, title, body }: { icon?: boolean; title?: string; body: string }) {
    return (
        <output
            className="card p-5 flex items-start gap-3"
            style={
                icon
                    ? { borderColor: 'color-mix(in srgb, var(--success) 30%, transparent)' }
                    : { color: 'var(--muted)' }
            }
        >
            {icon && (
                <Icon
                    name="check"
                    className="w-5 h-5 mt-0.5"
                    style={{ color: 'var(--success-strong)' }}
                />
            )}
            <div>
                {title !== undefined && <p className="font-medium">{title}</p>}
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    {body}
                </p>
            </div>
        </output>
    );
}

/** Step 2: what is being authorized, before it is authorized. */
function Consent({
    client,
    me,
    urls,
}: {
    client: NonNullable<Props['client']>;
    me: Props['me'];
    urls: Props['urls'];
}) {
    const approve = useForm({});
    const deny = useForm({});
    const { errors } = usePage().props;
    const error = typeof errors.userCode === 'string' ? errors.userCode : null;

    return (
        <div className="card p-5">
            <div className="flex items-center gap-3">
                <span
                    className="grid place-items-center rounded-full"
                    style={{
                        width: '2.25rem',
                        height: '2.25rem',
                        background: 'var(--accent-soft)',
                        color: 'var(--accent-strong)',
                    }}
                >
                    <Icon name="shield" className="w-5 h-5" />
                </span>
                <div className="min-w-0">
                    <p className="font-medium truncate">{client.name}</p>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        wants to connect to your account
                    </p>
                </div>
            </div>

            {/*
                WHICH ACCOUNT. A person may hold several, and the one being connected is
                whichever this browser is signed in as — not necessarily the one they were
                thinking of when they picked up the remote.
            */}
            <div
                className="mt-5 flex items-center gap-3 rounded-lg px-3 py-2.5"
                style={{ background: 'var(--accent-soft)' }}
            >
                <span
                    aria-hidden="true"
                    className="grid place-items-center rounded-full text-sm font-semibold"
                    style={{
                        width: '2rem',
                        height: '2rem',
                        background: 'var(--surface)',
                        color: 'var(--accent-strong)',
                    }}
                >
                    {me.initial}
                </span>
                <div className="min-w-0">
                    <p className="text-sm font-medium truncate">{me.name}</p>
                    <p className="text-xs truncate" style={{ color: 'var(--muted)' }}>
                        {me.email}
                    </p>
                </div>
            </div>

            {client.scopes.length > 0 && (
                <>
                    <p className="cbx-page-eyebrow mt-6">This will allow {client.name} to</p>
                    <ul className="mt-2.5 space-y-2">
                        {client.scopes.map((row) => (
                            <li key={row.scope} className="flex items-center gap-2.5 text-sm">
                                <Icon
                                    name="check"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--success-strong)' }}
                                />
                                <span>{row.label}</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {error !== null && (
                <p className="field-error mt-4" role="alert">
                    {error}
                </p>
            )}

            {/*
                DENY FIRST, and not for symmetry: somebody who does not recognise this
                request is the person this screen most has to serve, and the safe answer
                should not be the one they have to look for.
            */}
            <div className="mt-7 flex gap-2.5">
                <Button
                    size="lg"
                    className="flex-1"
                    loading={deny.processing}
                    onClick={() => deny.post(urls.deny)}
                >
                    Deny
                </Button>
                <Button
                    variant="primary"
                    size="lg"
                    className="flex-1"
                    loading={approve.processing}
                    onClick={() => approve.post(urls.approve)}
                >
                    Approve
                </Button>
            </div>

            <p className="mt-5 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Only approve if you just started signing in on a device you own. If you don&rsquo;t
                recognize this request, deny it.
            </p>
        </div>
    );
}

/** Step 1: the code shown on the device. */
function CodeForm({ href, error }: { href: string; error?: string }) {
    const form = useForm({ userCode: '' });

    return (
        <form
            className="card p-5 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href);
            }}
        >
            {error !== undefined && (
                <div
                    role="alert"
                    className="rounded-lg px-3.5 py-2.5 text-sm"
                    style={{ background: 'var(--danger-soft)', color: 'var(--danger-strong)' }}
                >
                    {error}
                </div>
            )}

            <Field label="Device code" error={form.errors.userCode}>
                {/*
                    autocomplete="off", NOT "one-time-code".

                    `one-time-code` means "a code delivered out of band TO THIS DEVICE" — an
                    SMS or authenticator OTP — and it is the signal Safari, iOS and every
                    password manager use to offer the last such code they saw. A
                    device-authorization user_code travels the OTHER way: it is shown on
                    another device's screen and typed in here. Asking for OTP autofill
                    silently REPLACED the prefilled code with an unrelated six-digit one, so
                    the form submitted a code the user never saw and the page answered "that
                    code is invalid or has expired" — pointing at the device, which was
                    blameless.
                */}
                <Input
                    name="userCode"
                    autoComplete="off"
                    data-1p-ignore
                    data-lpignore="true"
                    data-form-type="other"
                    autoCapitalize="characters"
                    spellCheck={false}
                    className="input-lg mono text-center tracking-[0.3em]"
                    placeholder="XXXX-XXXX"
                    value={form.data.userCode}
                    onChange={(event) => form.setData('userCode', event.target.value)}
                />
            </Field>

            <Button
                type="submit"
                variant="primary"
                size="lg"
                className="w-full"
                loading={form.processing}
            >
                Continue
            </Button>
            <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                You&rsquo;ll see which app is asking before anything is connected.
            </p>
        </form>
    );
}

Device.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
