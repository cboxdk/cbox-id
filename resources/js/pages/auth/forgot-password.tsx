import { Link, useForm, usePage } from '@inertiajs/react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input } from '@/ui';
import { login } from '@routes';
import { email as sendResetLink } from '@routes/password';

type Props = PageProps<Record<string, never>>;

/**
 * ASKING FOR A RESET LINK.
 *
 * The confirmation is identical whether or not the address has an account here. That is
 * the whole design of the page: "we couldn't find that address" is a free membership
 * check for anybody with a list of emails, and it is exactly what somebody preparing a
 * credential-stuffing run wants.
 */
export default function ForgotPassword(_props: Props) {
    // On the flash channel: "a link is on its way" is a step in a flow, and a person
    // pressing Back should see the form again rather than a stale confirmation.
    const { sentTo, devResetUrl } = usePage().flash;

    const form = useForm({ email: '' });

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                Reset your password
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Enter your email and we'll send a reset link.
            </p>

            {sentTo !== undefined ? (
                /* `<output>`: the outcome of the action on this page, and the element
                   the platform already maps to `role="status"`. */
                <output
                    className="mt-6 rounded-lg text-sm card block"
                    style={{ padding: '0.85rem 1rem' }}
                >
                    <p className="font-medium">Check your inbox</p>
                    <p className="mt-1" style={{ color: 'var(--muted-foreground)' }}>
                        If an account exists for <b>{sentTo}</b>, a reset link is on its way.
                    </p>

                    {/*
                        Local installs only, and the server decides that — a developer with
                        no mail transport can still walk the flow. It is a live credential
                        in a page body, so it can never be anything but a local convenience.
                    */}
                    {devResetUrl != null && (
                        <a
                            href={devResetUrl}
                            className="mt-2 inline-block underline underline-offset-2 mono"
                            style={{ color: 'var(--accent-strong)', wordBreak: 'break-all' }}
                        >
                            {devResetUrl}
                        </a>
                    )}
                </output>
            ) : (
                <form
                    className="mt-7 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(sendResetLink.url());
                    }}
                >
                    <Field label="Email" error={form.errors.email}>
                        <Input
                            name="email"
                            type="email"
                            scale="lg"
                            inputMode="email"
                            autoComplete="username"
                            autoCapitalize="none"
                            spellCheck={false}
                            placeholder="you@company.com"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                    </Field>

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        className="w-full"
                        loading={form.processing}
                    >
                        Send reset link
                    </Button>
                </form>
            )}

            <p
                className="mt-6 text-sm text-center"
                style={{ color: 'var(--muted-foreground)' }}
            >
                Remembered it?{' '}
                <Link
                    href={login.url()}
                    className="font-medium underline underline-offset-2"
                    style={{ color: 'var(--accent-strong)' }}
                >
                    Back to sign in
                </Link>
            </p>
        </>
    );
}

ForgotPassword.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
