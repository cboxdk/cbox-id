import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Icon, PasswordField, Panel } from '@/ui';
import { confirm } from '@routes/sudo';
import { confirm as confirmEnvironment } from '@routes/environment/sudo';

type Props = PageProps<{
    /** Why this screen appeared, when whatever raised it said so. */
    reason: string | null;
    /**
     * Which plane's step-up this is.
     *
     * The two are SEPARATE SESSION KEYS and never satisfy each other: an environment
     * administrator acts on every organization in the environment and a tenant
     * administrator acts on one, so a step-up bought in the cheaper place must not spend
     * in the dearer. The page only needs it to know where to post.
     */
    plane: 'organization' | 'environment';
}>;

/**
 * "CONFIRM IT'S YOU."
 *
 * The reason is shown because a password prompt with no explanation is one people learn
 * to type through. Whatever raised the step-up says what is waiting on the other side.
 */
export default function Sudo({ reason, plane }: Props) {
    const form = useForm({ password: '' });

    return (
        <div className="max-w-md">
            <div className="mb-6">
                <span
                    className="grid place-items-center rounded-full mb-4"
                    style={{
                        width: '2.5rem',
                        height: '2.5rem',
                        background: 'var(--accent-soft)',
                        color: 'var(--accent-strong)',
                    }}
                >
                    <Icon name="shield" className="w-5 h-5" />
                </span>

                <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                    Confirm it's you
                </h1>
                <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {reason ?? 'This is a protected action.'} Re-enter your password to continue.
                </p>
            </div>

            <Panel>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(plane === 'environment' ? confirmEnvironment.url() : confirm.url());
                    }}
                >
                    <PasswordField
                        label="Password"
                        name="password"
                        autoComplete="current-password"
                        error={form.errors.password}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        className="w-full"
                        loading={form.processing}
                    >
                        Confirm
                    </Button>
                </form>
            </Panel>
        </div>
    );
}

Sudo.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
