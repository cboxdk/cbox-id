import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { ApiEquivalent, Breadcrumb, Button, Checkbox, Field, Input, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    indexHref: string;
    storeHref: string;
}>;

export default function CreateUser({ indexHref, storeHref }: Props) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        email: '',
        name: '',
        // On, because a user who cannot sign in is not a user.
        sendLink: true,
    });

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.users_6b0cc904')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.no_password_is_set_here_they_get_a_sign_in_link_by_emai_5e488804',
                    )}
                />
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
                        <Field label={consoleT('console.email_969ccbd3')} error={form.errors.email}>
                            <Input
                                name="email"
                                type="email"
                                autoComplete="off"
                                placeholder="user@example.com"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={consoleT('console.full_name_f13a64ba')}
                            optional
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder={consoleUiText('console.full_name_f13a64ba')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Checkbox
                            checked={form.data.sendLink}
                            onCheckedChange={(checked) => form.setData('sendLink', checked)}
                            label={consoleT('console.email_them_a_sign_in_link_39a8ca7c')}
                            hint={consoleT(
                                'console.valid_once_and_short_lived_turn_this_off_only_if_you_ar_521114e3',
                            )}
                        />
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.create_user_f06da128')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>

                <ApiEquivalent
                    action="users.create"
                    values={{
                        email: form.data.email,
                        name: form.data.name,
                        send_sign_in_link: form.data.sendLink,
                    }}
                />
            </form>
        </>
    );
}

CreateUser.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
