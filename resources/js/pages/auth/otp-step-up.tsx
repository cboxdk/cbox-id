import { router, useForm, usePage } from '@inertiajs/react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input } from '@/ui';
import { logout } from '@routes';
import { resend, verify } from '@routes/login/step-up';

type Props = PageProps<{ maskedEmail: string }>;

/**
 * A ONE-TIME CODE, EMAILED, because this sign-in looked unusual.
 *
 * The address is masked. The person already knows their own; the point of showing it is
 * to say which inbox to look in, and an unmasked address on a page reachable without a
 * session is one an onlooker over a shoulder learns too.
 */
export default function OtpStepUp({ maskedEmail }: Props) {
    const resentMessage = usePage().flash.resent;

    const form = useForm({ code: '' });

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                Additional verification
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                This sign-in looked unusual, so we emailed a one-time code to{' '}
                <b>{maskedEmail}</b>. Enter it to continue.
            </p>

            {resentMessage !== undefined && (
                <output
                    className="mt-5 rounded-lg px-3.5 py-2.5 text-sm inline-flex items-center gap-2"
                    style={{ background: 'var(--success-soft)', color: 'var(--success-strong)' }}
                >
                    <Icon name="check" className="w-4 h-4" /> {resentMessage}
                </output>
            )}

            <form
                className="mt-7 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(verify.url());
                }}
            >
                <Field label="Verification code" error={form.errors.code}>
                    <Input
                        name="code"
                        scale="lg"
                        className="mono"
                        style={{ letterSpacing: '0.5em', textAlign: 'center' }}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        placeholder="000000"
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    Verify
                </Button>
            </form>

            <button
                type="button"
                onClick={() => router.post(resend.url(), {}, { preserveScroll: true })}
                className="mt-4 text-sm underline underline-offset-2"
                style={{ color: 'var(--accent-strong)' }}
            >
                Didn't get it? Resend code
            </button>

            <div className="mt-6">
                <button
                    type="button"
                    onClick={() => router.post(logout.url())}
                    className="text-sm underline underline-offset-2"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    Cancel and sign out
                </button>
            </div>
        </>
    );
}

OtpStepUp.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
