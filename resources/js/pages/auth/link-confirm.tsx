import { router } from '@inertiajs/react';
import { useState } from 'react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button } from '@/ui';
import { connect, decline } from '@routes/link';

type Props = PageProps<{
    provider: string;
    email: string | null;
    name: string | null;
}>;

/**
 * CONNECT THIS PROVIDER, OR SAY IT WAS NOT YOU.
 *
 * Both answers are buttons of equal weight, and the declining one is FIRST in the DOM on
 * a narrow screen (reversed visually) — because a person who reaches this screen without
 * having just signed in with that provider is looking at somebody else's attempt to use
 * their address, and "no" is the answer that needs to be easy to reach.
 */
export default function LinkConfirm({ provider, email }: Props) {
    const [answering, setAnswering] = useState<'connect' | 'decline' | null>(null);

    return (
        <>
            <h1 className="text-xl font-semibold tracking-tight">Connect {provider}?</h1>

            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Someone just signed in with {provider}
                {email !== null && (
                    <>
                        {' '}
                        as{' '}
                        <span className="font-medium" style={{ color: 'var(--foreground)' }}>
                            {email}
                        </span>
                    </>
                )}{' '}
                — an address that already belongs to your account.
            </p>

            <div
                className="mt-5 rounded-lg p-4 text-sm"
                style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
            >
                <p>
                    <b>If that was you</b>, connect it and you'll be able to sign in with{' '}
                    {provider} or with your password from now on.
                </p>
                <p className="mt-2.5" style={{ color: 'var(--muted-foreground)' }}>
                    <b>If it wasn't</b>, decline. Someone else tried to sign in using your email
                    address. Nothing will be added to your account, and your password still
                    works as before.
                </p>
            </div>

            <div className="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row">
                <Button
                    className="sm:flex-1"
                    loading={answering === 'decline'}
                    onClick={() => {
                        setAnswering('decline');
                        router.post(decline.url());
                    }}
                >
                    No, that wasn't me
                </Button>

                <Button
                    variant="primary"
                    className="sm:flex-1"
                    loading={answering === 'connect'}
                    onClick={() => {
                        setAnswering('connect');
                        router.post(connect.url());
                    }}
                >
                    Yes, connect {provider}
                </Button>
            </div>

            <p className="mt-5 text-xs" style={{ color: 'var(--faint)' }}>
                You can disconnect {provider} at any time from your account's security settings.
            </p>
        </>
    );
}

LinkConfirm.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
