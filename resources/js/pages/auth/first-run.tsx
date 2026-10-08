import { useForm } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input, PasswordField, PasswordManagerIdentity } from '@/ui';

type Props = PageProps<{
    multiTenant: boolean;
    misconfigured: boolean;
    unmigrated: boolean;
    claimHref: string;
}>;

/** The command that installs from the shell — named in three of this page's sentences. */
const INSTALL = <code>php artisan cbox-id:install</code>;

export default function FirstRun({ multiTenant, misconfigured, unmigrated, claimHref }: Props) {
    const { t, rich } = useTranslator();

    return (
        <div>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {t('auth.first_run.title')}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted)' }}>
                {t('auth.first_run.lead')}
            </p>

            {/*
                THREE STATES, AND ONLY ONE OF THEM HAS A FORM. An un-migrated database and a
                multi-tenant deployment with no console host are both reasons the submission
                would fail — the first on a missing table, the second by installing something
                unreachable — so each is said out loud with the command that fixes it rather
                than discovered by pressing the button.
            */}
            {unmigrated ? (
                <Notice title={t('auth.first_run.unmigrated.title')}>
                    {rich('auth.first_run.unmigrated.body', {
                        migrate: <code>php artisan migrate --force</code>,
                        install: INSTALL,
                    })}
                </Notice>
            ) : misconfigured ? (
                <Notice title={t('auth.first_run.misconfigured.title')}>
                    {rich('auth.first_run.misconfigured.body', {
                        console_host: <code>CBOX_ID_CONSOLE_HOST</code>,
                        single_host: <code>CBOX_ID_MULTI_TENANT=false</code>,
                        install: INSTALL,
                    })}
                </Notice>
            ) : (
                <>
                    <Notice title={t('auth.first_run.token_notice.title')}>
                        {rich('auth.first_run.token_notice.body', {
                            command: <code>php artisan cbox-id:setup-token</code>,
                        })}
                    </Notice>

                    <ClaimForm href={claimHref} multiTenant={multiTenant} />
                </>
            )}

            <p className="mt-6 text-xs" style={{ color: 'var(--faint)' }}>
                {rich('auth.first_run.cli_hint', { install: INSTALL })}
            </p>
        </div>
    );
}

function Notice({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div
            className="mt-6 rounded-lg p-4 text-sm"
            style={{ background: 'var(--surface-2)', color: 'var(--muted)' }}
        >
            <p style={{ color: 'var(--text)' }}>
                <strong>{title}</strong>
            </p>
            <p className="mt-2">{children}</p>
        </div>
    );
}

function ClaimForm({ href, multiTenant }: { href: string; multiTenant: boolean }) {
    const { t } = useTranslator();
    const form = useForm({
        token: '',
        name: '',
        email: '',
        password: '',
        // In the page's language: a name the operator reads and may well keep, not a code.
        environmentName: t('auth.first_run.environment_default'),
        organizationName: '',
    });

    return (
        <form
            className="mt-7 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href);
            }}
        >
            {/*
                A PASSWORD FIELD, not a text one, and never remembered: the token is
                authority to install this deployment, and a manager that offered to save it
                would be saving a credential for a door that is about to be bricked up.
            */}
            <Field label={t('auth.first_run.token_label')} error={form.errors.token}>
                <Input
                    name="token"
                    type="password"
                    autoComplete="off"
                    className="input-lg"
                    placeholder={t('auth.first_run.token_placeholder')}
                    value={form.data.token}
                    onChange={(event) => form.setData('token', event.target.value)}
                />
            </Field>

            <Field label={t('auth.first_run.name_label')} error={form.errors.name}>
                <Input
                    name="name"
                    className="input-lg"
                    placeholder={t('auth.first_run.name_placeholder')}
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
            </Field>

            <Field label={t('auth.first_run.email_label')} error={form.errors.email}>
                <Input
                    name="email"
                    type="email"
                    autoComplete="username"
                    className="input-lg"
                    placeholder="operator@yourco.example"
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                />
            </Field>

            {/*
                So a password manager saves this credential against the address above rather
                than as a second, nameless entry — this is the account that runs the whole
                deployment, and the one nobody can reset for them.
            */}
            <PasswordManagerIdentity username={form.data.email} />

            <PasswordField
                label={t('auth.common.password')}
                name="password"
                autoComplete="new-password"
                className="input-lg"
                policy
                showLabel={t('auth.password_field.show')}
                hideLabel={t('auth.password_field.hide')}
                policyLabel={t('auth.password_field.policy', { count: 12 })}
                placeholder={t('auth.password_field.policy', { count: 12 })}
                error={form.errors.password}
                value={form.data.password}
                onChange={(event) => form.setData('password', event.target.value)}
            />

            <Field
                label={t('auth.first_run.environment_label')}
                hint={t('auth.first_run.environment_hint')}
                error={form.errors.environmentName}
            >
                <Input
                    name="environmentName"
                    className="input-lg"
                    placeholder={t('auth.first_run.environment_default')}
                    value={form.data.environmentName}
                    onChange={(event) => form.setData('environmentName', event.target.value)}
                />
            </Field>

            {multiTenant && (
                <Field
                    label={t('auth.first_run.organization_label')}
                    hint={t('auth.first_run.organization_hint')}
                    error={form.errors.organizationName}
                >
                    <Input
                        name="organizationName"
                        className="input-lg"
                        placeholder={t('auth.first_run.organization_placeholder')}
                        value={form.data.organizationName}
                        onChange={(event) => form.setData('organizationName', event.target.value)}
                    />
                </Field>
            )}

            <Button
                type="submit"
                variant="primary"
                size="lg"
                className="w-full"
                loading={form.processing}
            >
                {t('auth.first_run.submit')}
            </Button>
        </form>
    );
}

FirstRun.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
