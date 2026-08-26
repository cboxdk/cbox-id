import { useForm } from '@inertiajs/react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input, PasswordField, PasswordManagerIdentity } from '@/ui';

type Props = PageProps<{
    multiTenant: boolean;
    misconfigured: boolean;
    unmigrated: boolean;
    claimHref: string;
}>;

export default function FirstRun({ multiTenant, misconfigured, unmigrated, claimHref }: Props) {
    return (
        <div>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                Set up Cbox ID
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted)' }}>
                This deployment is empty. Claim it once, from the machine that runs it.
            </p>

            {/*
                THREE STATES, AND ONLY ONE OF THEM HAS A FORM. An un-migrated database and a
                multi-tenant deployment with no console host are both reasons the submission
                would fail — the first on a missing table, the second by installing something
                unreachable — so each is said out loud with the command that fixes it rather
                than discovered by pressing the button.
            */}
            {unmigrated ? (
                <Notice title="This deployment's database has no schema yet.">
                    Run <code>php artisan migrate --force</code> on the server (or{' '}
                    <code>php artisan cbox-id:install</code>, which migrates and installs in one
                    step), then reload this page.
                </Notice>
            ) : misconfigured ? (
                <Notice title="This deployment is configured as multi-tenant but has no account host.">
                    Set <code>CBOX_ID_CONSOLE_HOST</code> (where the console lives), or set{' '}
                    <code>CBOX_ID_MULTI_TENANT=false</code> for a single-host install — then reload
                    this page. You can also run <code>php artisan cbox-id:install</code>, which asks
                    for both and writes them for you.
                </Notice>
            ) : (
                <>
                    <Notice title="Where is the setup token?">
                        In <code>storage/app/private/cbox-id-first-run.token</code> on the server,
                        and in the application log (<code>docker logs</code> for a container). It is
                        never shown on this page.
                    </Notice>

                    <ClaimForm href={claimHref} multiTenant={multiTenant} />
                </>
            )}

            <p className="mt-6 text-xs" style={{ color: 'var(--faint)' }}>
                Prefer the command line? <code>php artisan cbox-id:install</code> does the same
                thing, and is the only path that can also choose and record the deployment shape.
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
    const form = useForm({
        token: '',
        name: '',
        email: '',
        password: '',
        environmentName: 'Production',
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
            <Field label="Setup token" error={form.errors.token}>
                <Input
                    name="token"
                    type="password"
                    autoComplete="off"
                    className="input-lg"
                    placeholder="Paste the token from the server"
                    value={form.data.token}
                    onChange={(event) => form.setData('token', event.target.value)}
                />
            </Field>

            <Field label="Your name" error={form.errors.name}>
                <Input
                    name="name"
                    className="input-lg"
                    placeholder="Root Operator"
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
            </Field>

            <Field label="Your email" error={form.errors.email}>
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
                label="Password"
                name="password"
                autoComplete="new-password"
                className="input-lg"
                policy
                placeholder="At least 12 characters"
                error={form.errors.password}
                value={form.data.password}
                onChange={(event) => form.setData('password', event.target.value)}
            />

            <Field
                label="Name your first environment"
                hint="An environment is the hard isolation boundary — its own users, keys and issuer."
                error={form.errors.environmentName}
            >
                <Input
                    name="environmentName"
                    className="input-lg"
                    placeholder="Production"
                    value={form.data.environmentName}
                    onChange={(event) => form.setData('environmentName', event.target.value)}
                />
            </Field>

            {multiTenant && (
                <Field
                    label="Organization name"
                    hint="This deployment is configured as multi-tenant, so the install also creates the first organization — the customer that owns environments and billing."
                    error={form.errors.organizationName}
                >
                    <Input
                        name="organizationName"
                        className="input-lg"
                        placeholder="Your company"
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
                Install this deployment
            </Button>
        </form>
    );
}

FirstRun.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
