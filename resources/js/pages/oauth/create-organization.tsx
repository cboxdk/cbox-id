import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input } from '@/ui';

type Props = PageProps<{
    client: { name: string; owner: string };
    me: { name: string; email: string | null; initial: string };
    storeHref: string;
    /** Back to the picker when the app asked for one; null when this step stands alone. */
    pickerHref: string | null;
    denyHref: string;
}>;

/**
 * CREATE AN ORGANIZATION FOR AN APP — a name, and the person becomes its owner.
 *
 * One field on purpose. Everything else about an organization (its people, its sign-in
 * rules, its look) is set up later by its owner; asking for it here would stand between
 * somebody and the app they came to use.
 */
export default function CreateOrganization({ client, me, storeHref, pickerHref, denyHref }: Props) {
    const form = useForm({ name: '' });
    const [cancelling, setCancelling] = useState(false);
    const { t, rich } = useTranslator();

    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">
                {t('oauth.create_organization.heading')}
            </h1>
            <p className="mt-1.5 text-sm" style={{ color: 'var(--muted)' }}>
                {rich('oauth.create_organization.lead', { client: <b>{client.name}</b> })}
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {t('oauth.signed_in_as', { account: me.email ?? me.name })}
            </p>

            <form
                className="mt-6 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Field label={t('oauth.create_organization.name_label')} error={form.errors.name}>
                    <Input
                        name="name"
                        scale="lg"
                        autoComplete="organization"
                        placeholder="Acme Inc."
                        maxLength={120}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    {t('oauth.create_organization.submit')}
                </Button>
            </form>

            <div className="mt-6 flex flex-col gap-2">
                {pickerHref !== null && (
                    <Button asChild className="w-full">
                        <Link href={pickerHref}>
                            {t('oauth.create_organization.choose_existing')}
                        </Link>
                    </Button>
                )}

                <Button
                    variant="ghost"
                    className="w-full"
                    loading={cancelling}
                    onClick={() => {
                        setCancelling(true);
                        router.post(denyHref, {}, { onFinish: () => setCancelling(false) });
                    }}
                >
                    {t('oauth.cancel_and_return', { client: client.name })}
                </Button>
            </div>
        </div>
    );
}

CreateOrganization.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
