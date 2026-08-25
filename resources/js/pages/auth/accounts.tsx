import { Link, router } from '@inertiajs/react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Avatar, Icon } from '@/ui';
import { add, switchMethod } from '@routes/accounts';

interface Account {
    subject_id: string;
    name: string;
    email: string | null;
    organization_id: string | null;
    active: boolean;
}

type Props = PageProps<{ accounts: Account[] }>;

/**
 * WHICH OF THE IDENTITIES ON THIS BROWSER YOU ARE ACTING AS.
 *
 * A list of buttons rather than links, because choosing one is a POST that moves the
 * session — and a GET that changes who you are is a GET any image tag on any page can
 * make.
 */
export default function Accounts({ accounts }: Props) {
    return (
        <div className="w-full max-w-sm mx-auto">
            <h1 className="font-semibold tracking-tight text-2xl">Choose an account</h1>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Signed in on this device. Pick one, or add another.
            </p>

            <ul className="mt-6 flex flex-col gap-2">
                {accounts.map((account) => (
                    <li key={account.subject_id}>
                        <button
                            type="button"
                            onClick={() =>
                                router.post(switchMethod.url(), { subject: account.subject_id })
                            }
                            className="w-full flex items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition"
                            style={{
                                borderColor: 'var(--border)',
                                background: 'var(--card)',
                            }}
                        >
                            <Avatar name={account.name} />

                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">{account.name}</span>
                                {account.email !== null && (
                                    <span
                                        className="block truncate text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {account.email}
                                    </span>
                                )}
                            </span>

                            {account.active && (
                                <span
                                    className="text-xs font-medium"
                                    style={{ color: 'var(--accent-strong)' }}
                                >
                                    Active
                                </span>
                            )}
                        </button>
                    </li>
                ))}
            </ul>

            <Link
                href={add.url()}
                className="mt-3 w-full flex items-center justify-center gap-2 rounded-xl border border-dashed px-3.5 py-3 text-sm font-medium transition"
                style={{ borderColor: 'var(--border)' }}
            >
                <Icon name="plus" className="w-4 h-4" /> Add another account
            </Link>
        </div>
    );
}

Accounts.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
