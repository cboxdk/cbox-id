import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Checkbox, Field, Icon, Input, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    indexHref: string;
    storeHref: string;
}>;

export default function CreateUser({ indexHref, storeHref }: Props) {
    const form = useForm({
        email: '',
        name: '',
        // On, because a user who cannot sign in is not a user.
        sendLink: true,
    });

    return (
        <>
            <Link
                href={indexHref}
                className="text-sm inline-flex items-center gap-1"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon
                    name="chevron"
                    className="w-3.5 h-3.5"
                    style={{ transform: 'rotate(90deg)' }}
                />
                Users
            </Link>

            <div className="mt-2">
                <PageHeader description="No password is set here — they get a sign-in link by email and choose how they sign in." />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <div className="space-y-4">
                        <Field label="Email" error={form.errors.email}>
                            <Input
                                name="email"
                                type="email"
                                autoComplete="off"
                                placeholder="user@example.com"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                        </Field>

                        <Field label="Name" optional error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="Full name"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Checkbox
                            checked={form.data.sendLink}
                            onCheckedChange={(checked) => form.setData('sendLink', checked)}
                            label="Email them a sign-in link"
                            hint="Valid once, and short-lived. Turn this off only if you are creating the account ahead of time and will send the link yourself."
                        />
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Create user
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateUser.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
