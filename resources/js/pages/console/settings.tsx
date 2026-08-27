import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, CopyButton, Field, Icon, Input, Kv, KvList, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    help: HelpContent;
    organization: {
        id: string;
        name: string;
        slug: string;
        type: string;
        brandedLoginHref: string;
    } | null;
    /**
     * The control plane's own record. Present on the environment plane and nowhere else.
     *
     * NOT called `environment`: the shell shares a prop by that name and a page prop of
     * the same name replaces it — see the controller.
     */
    environmentRecord: { id: string; name: string; sandbox: boolean } | null;
    appearance: {
        preset: string;
        lightBackground: string;
        lightPrimary: string;
        darkBackground: string;
        darkPrimary: string;
    };
    appearanceHref: string;
    renameHref: string;
    accountHref: string;
    setupGuideHref: string | null;
    issuer: string;
    discovery: string;
}>;

export default function Settings({
    help,
    organization,
    environmentRecord,
    appearance,
    appearanceHref,
    renameHref,
    accountHref,
    setupGuideHref,
    issuer,
    discovery,
}: Props) {
    const form = useForm({ name: organization?.name ?? '' });

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    <>
                        What you are administering, and the details your apps need to integrate.
                        {environmentRecord === null && (
                            <>
                                {' '}
                                Manage your own security under{' '}
                                <Link
                                    href={accountHref}
                                    className="underline"
                                    style={{ color: 'var(--accent-strong)' }}
                                >
                                    My account
                                </Link>
                                .
                            </>
                        )}
                    </>
                }
            />

            {environmentRecord !== null && (
                <Panel
                    title="Environment"
                    description="The identity platform you operate. Every organization below lives inside it."
                >
                    <KvList>
                        <Kv label="Name" prose>
                            {environmentRecord.name}{' '}
                            {environmentRecord.sandbox && <Badge tone="warn">Sandbox</Badge>}
                        </Kv>
                        <Kv label="Environment ID">{environmentRecord.id}</Kv>
                    </KvList>
                </Panel>
            )}

            <Panel title="Organization" description="The organization this console is acting on.">
                {organization === null ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        Choose an organization to see and rename it.
                    </p>
                ) : (
                    <>
                        {/*
                            Rename is new to the environment plane: an administrator who
                            holds every organization here could not correct a typo in one's
                            name without signing into that organization's own console.
                        */}
                        <form
                            className="mb-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.patch(renameHref, { preserveScroll: true });
                            }}
                        >
                            <Field label="Name" error={form.errors.name}>
                                <div className="flex items-center gap-2">
                                    <Input
                                        name="name"
                                        maxLength={120}
                                        style={{ flex: 1 }}
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData('name', event.target.value)
                                        }
                                    />
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        className="shrink-0"
                                        loading={form.processing}
                                    >
                                        Rename
                                    </Button>
                                </div>
                            </Field>
                        </form>

                        <KvList>
                            <Kv label="Slug">{organization.slug}</Kv>
                            <Kv label="Type" prose>
                                <Badge>{organization.type}</Badge>
                            </Kv>
                            <Kv label="Organization ID">{organization.id}</Kv>
                        </KvList>
                    </>
                )}
            </Panel>

            {/*
                The way back to the setup guide. Dismissing it on the dashboard is meant to
                be reversible, and this is where somebody looks for the switch.
            */}
            {setupGuideHref !== null && (
                <Panel
                    title="Setup guide"
                    description="The steps worth doing for a new organization, with what each one gets you."
                >
                    <div className="flex items-center justify-between gap-4">
                        <p className="text-sm min-w-0" style={{ color: 'var(--muted-foreground)' }}>
                            Each step is measured from live state, so the guide always shows where
                            this organization actually stands.
                        </p>
                        <Button asChild variant="secondary" className="shrink-0">
                            <Link href={setupGuideHref}>
                                Open guide
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4"
                                    style={{ transform: 'rotate(-90deg)' }}
                                />
                            </Link>
                        </Button>
                    </div>
                </Panel>
            )}

            <Panel
                title="Login branding"
                description={
                    organization !== null ? (
                        <>
                            Theme this organization's sign-in page. Its team signs in at{' '}
                            <a
                                href={organization.brandedLoginHref}
                                className="mono underline"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                /o/{organization.slug}/login
                            </a>
                            .
                        </>
                    ) : (
                        "The environment's default sign-in theme — inherited by every organization that has not set its own."
                    )
                }
            >
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3 min-w-0">
                        {/*
                            Four swatches rather than one: the theme is a LIGHT and a DARK
                            palette, and a single chip shows half of what somebody is about
                            to ship to their users.
                        */}
                        <span
                            className="grid grid-cols-2 grid-rows-2 w-10 h-10 rounded-lg overflow-hidden shrink-0"
                            style={{ border: '1px solid var(--border)' }}
                            aria-hidden="true"
                        >
                            <span style={{ background: appearance.lightBackground }} />
                            <span style={{ background: appearance.lightPrimary }} />
                            <span style={{ background: appearance.darkBackground }} />
                            <span style={{ background: appearance.darkPrimary }} />
                        </span>

                        <div className="min-w-0">
                            <p className="text-sm font-medium">{appearance.preset} theme</p>
                            <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                Presets, colours, corners &amp; type — edited with a live preview.
                            </p>
                        </div>
                    </div>

                    <Button asChild variant="secondary" className="shrink-0">
                        <Link href={appearanceHref}>
                            Open editor
                            <Icon
                                name="chevron"
                                className="w-4 h-4"
                                style={{ transform: 'rotate(-90deg)' }}
                            />
                        </Link>
                    </Button>
                </div>
            </Panel>

            {/*
                Integration. The environment plane's half, now on both: an organization
                administrator wiring an OIDC client needed exactly these two URLs and had
                nowhere in their own console to find them. Both are already served
                unauthenticated, so showing them here discloses nothing.
            */}
            <Panel
                title="Integration"
                description="Point your OIDC client at these. Discovery exposes every endpoint automatically."
            >
                <div className="space-y-3">
                    {(
                        [
                            { label: 'Issuer', value: issuer },
                            { label: 'OIDC discovery', value: discovery },
                        ] as const
                    ).map(({ label, value }) => (
                        <div
                            key={label}
                            className="rounded-xl border p-3"
                            style={{ borderColor: 'var(--border)' }}
                        >
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {label}
                            </p>
                            <div className="mt-1 flex items-center gap-2">
                                <code className="flex-1 min-w-0 truncate mono text-sm">
                                    {value}
                                </code>
                                <CopyButton value={value} />
                            </div>
                        </div>
                    ))}
                </div>
            </Panel>
        </div>
    );
}

Settings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
