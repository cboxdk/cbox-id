import { Link, useForm, usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input, PasswordField, Turnstile } from '@/ui';
import { login } from '@routes';
import { register } from '@routes/signup';

type Props = PageProps<{
    /** On the platform root this mints the signer's OWN identity platform. */
    createsIdp: boolean;
    /** Empty when Turnstile is not configured, and then nothing is ever fetched from it. */
    turnstileSiteKey: string;
    /** Stamped by the server: a timestamp the client invents measures nothing. */
    renderedAt: number;
}>;

export default function Signup({ createsIdp, turnstileSiteKey, renderedAt }: Props) {
    // Set once the risk scorer has asked THIS submission to be challenged, and gone
    // again on the next page. The overwhelming majority never see a CAPTCHA at all.
    const challenged = usePage().flash.challenged === true;

    const form = useForm({
        organization: '',
        name: '',
        email: '',
        password: '',
        // A human never fills this. A value in it is evidence for the risk scorer, not an
        // error — refusing it outright would tell a bot which field to leave alone next.
        website: '',
        renderedAt,
        turnstileToken: '',
    });

    const onToken = useCallback((token: string) => form.setData('turnstileToken', token), [form]);

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {createsIdp ? 'Create your identity platform' : 'Create your organization'}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {createsIdp
                    ? 'Your own hosted IdP — SSO, users, and sign-in you fully control, live in a minute.'
                    : 'Set up Cbox ID for your team in under a minute.'}
            </p>

            <form
                className="mt-7 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(register.url());
                }}
            >
                {/* Hidden from humans, tempting to bots. Must stay empty. */}
                <div
                    aria-hidden="true"
                    style={{ position: 'absolute', left: '-9999px', top: '-9999px' }}
                >
                    <label htmlFor="website">Website</label>
                    <input
                        id="website"
                        name="website"
                        type="text"
                        tabIndex={-1}
                        autoComplete="off"
                        value={form.data.website}
                        onChange={(event) => form.setData('website', event.target.value)}
                    />
                </div>

                <Field
                    label={createsIdp ? 'Name your platform' : 'Organization name'}
                    error={form.errors.organization}
                >
                    <Input
                        name="organization"
                        scale="lg"
                        autoComplete="organization"
                        placeholder="Acme Inc."
                        value={form.data.organization}
                        onChange={(event) => form.setData('organization', event.target.value)}
                    />
                </Field>

                <div className="grid sm:grid-cols-2 gap-4">
                    <Field label="Your name" error={form.errors.name}>
                        <Input
                            name="name"
                            scale="lg"
                            autoComplete="name"
                            placeholder="Dana Reeves"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <Field label="Work email" error={form.errors.email}>
                        <Input
                            name="email"
                            scale="lg"
                            type="email"
                            inputMode="email"
                            autoComplete="username"
                            autoCapitalize="none"
                            spellCheck={false}
                            placeholder="dana@acme.com"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                    </Field>
                </div>

                <div>
                    <PasswordField
                        label="Password"
                        name="password"
                        autoComplete="new-password"
                        placeholder="At least 12 characters"
                        policy
                        error={form.errors.password}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                    <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                        Checked against known breaches.
                    </p>
                </div>

                {/*
                    THE RISK-TRIGGERED CAPTCHA. Nothing is fetched from Cloudflare until
                    the scorer has actually challenged a submission — an ordinary signup
                    contacts them zero times, which matters more here than on most sites:
                    this is the front door of an identity provider.
                */}
                {turnstileSiteKey !== '' && challenged && (
                    <Turnstile siteKey={turnstileSiteKey} onToken={onToken} />
                )}

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    {createsIdp ? 'Create identity platform' : 'Create organization'}
                </Button>
            </form>

            <p className="mt-8 text-sm text-center" style={{ color: 'var(--muted-foreground)' }}>
                Already have an account?{' '}
                <Link
                    href={login.url()}
                    className="font-medium underline underline-offset-2"
                    style={{ color: 'var(--accent-strong)' }}
                >
                    Sign in
                </Link>
            </p>
        </>
    );
}

Signup.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
