import { useTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    Field,
    Icon,
    Input,
    Kv,
    KvList,
    PageHeader,
    Panel,
} from '@/ui';

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
    /** The organization console's own rename; null on the environment console. */
    renameHref: string | null;
    /** The environment console's list of organizations, where each one is renamed on its own page. */
    organizationsHref: string | null;
    accountHref: string;
    setupGuideHref: string | null;
    /** Null where this host is no identity provider — the platform root of a SaaS deployment. */
    issuer: string | null;
    discovery: string | null;
    /** Present only for the OWNER of an organization that is not a customer. */
    closeOrganizationHref: string | null;
}>;

export default function Settings({
    help,
    organization,
    environmentRecord,
    appearance,
    appearanceHref,
    renameHref,
    organizationsHref,
    accountHref,
    setupGuideHref,
    issuer,
    discovery,
    closeOrganizationHref,
}: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({ name: organization?.name ?? '' });
    const [closing, setClosing] = useState(false);
    const closeError = usePage<Props>().props.errors.name;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    <>
                        {tc('console.what_you_are_administering_and_the_details_y_ef36ba75')}
                        {environmentRecord === null && (
                            <>
                                {' '}
                                {tc('console.manage_your_own_security_under_ff3b0c49')}{' '}
                                <Link
                                    href={accountHref}
                                    className="underline"
                                    style={{ color: 'var(--accent-strong)' }}
                                >
                                    {tc('console.my_account_b53181a4')}
                                </Link>
                                .
                            </>
                        )}
                    </>
                }
            />

            {environmentRecord !== null && (
                <Panel
                    title={tc('console.environment_9e471951')}
                    description={tc(
                        'console.this_environment_every_organization_below_li_afb44516',
                    )}
                >
                    <KvList>
                        <Kv label={tc('console.name_dcd1d522')} prose>
                            {environmentRecord.name}{' '}
                            {environmentRecord.sandbox && (
                                <Badge tone="warn">{tc('console.sandbox_67fc6249')}</Badge>
                            )}
                        </Kv>
                        <Kv label={tc('console.environment_id_9ff84b43')}>
                            {environmentRecord.id}
                        </Kv>
                    </KvList>
                </Panel>
            )}

            {organizationsHref !== null && (
                <Panel
                    title={tc('console.organizations_2730183d')}
                    description={tc(
                        'console.each_organization_s_name_handle_and_details__e422089c',
                    )}
                >
                    <Link href={organizationsHref} className="text-sm">
                        {tc('console.go_to_organizations_2afa38a3')}
                    </Link>
                </Panel>
            )}

            {organization !== null && renameHref !== null && (
                <Panel
                    title={tc('console.organization_d764d425')}
                    description={tc('console.the_organization_this_console_administers_b1b7dbad')}
                >
                    <>
                        <form
                            className="mb-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.patch(renameHref, { preserveScroll: true });
                            }}
                        >
                            <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
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
                                        {tc('console.rename_3064d79a')}
                                    </Button>
                                </div>
                            </Field>
                        </form>

                        <KvList>
                            <Kv label={tc('console.slug_d15387ec')}>{organization.slug}</Kv>
                            <Kv label={tc('console.type_baaddf70')} prose>
                                <Badge>{organization.type}</Badge>
                            </Kv>
                            <Kv label={tc('console.organization_id_1f466322')}>
                                {organization.id}
                            </Kv>
                        </KvList>
                    </>
                </Panel>
            )}

            {/*
                The way back to the setup guide. Dismissing it on the dashboard is meant to
                be reversible, and this is where somebody looks for the switch.
            */}
            {setupGuideHref !== null && (
                <Panel
                    title={tc('console.setup_guide_f91058b1')}
                    description={tc(
                        'console.the_steps_worth_doing_for_a_new_organization_295f9381',
                    )}
                >
                    <div className="flex items-center justify-between gap-4">
                        <p className="text-sm min-w-0" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.each_step_is_measured_from_live_state_so_the_e7f5c55e')}
                        </p>
                        <Button asChild variant="secondary" className="shrink-0">
                            <Link href={setupGuideHref}>
                                {tc('console.open_guide_befbd50c')}
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
                title={tc('console.login_branding_ff44c60c')}
                description={
                    organization !== null ? (
                        <>
                            {tc('console.theme_this_organization_s_sign_in_page_its_t_c7a98a8e')}{' '}
                            <a
                                href={organization.brandedLoginHref}
                                className="mono underline"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                {tc('console.o_691a5778')}
                                {organization.slug}
                                {tc('console.login_7e93fba0')}
                            </a>
                            .
                        </>
                    ) : (
                        tc('console.the_environment_s_default_sign_in_theme_inhe_aa90ec1d')
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
                            <p className="text-sm font-medium">
                                {appearance.preset}
                                {tc('console.theme_ac06016a')}
                            </p>
                            <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                {tc(
                                    'console.presets_colours_corners_amp_type_edited_with_3950e4a7',
                                )}
                            </p>
                        </div>
                    </div>

                    <Button asChild variant="secondary" className="shrink-0">
                        <Link href={appearanceHref}>
                            {tc('console.open_editor_550697e0')}
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
            {issuer !== null && discovery !== null && (
                <Panel
                    title={tc('console.integration_9a42d40a')}
                    description={tc(
                        'console.point_your_oidc_client_at_these_discovery_ex_81f0fa9a',
                    )}
                >
                    <div className="space-y-3">
                        {(
                            [
                                { label: tc('console.issuer_39e02c46'), value: issuer },
                                { label: tc('console.oidc_discovery_885dc27a'), value: discovery },
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
            )}

            {closeOrganizationHref !== null && organization !== null && (
                <Panel
                    title={tc('console.delete_organization_a70d189a')}
                    description={tc(
                        'console.everyone_in_it_loses_access_immediately_and__6b76018d',
                    )}
                >
                    {closeError !== undefined && (
                        <p
                            role="alert"
                            className="mb-3 text-sm"
                            style={{ color: 'var(--destructive)' }}
                        >
                            {closeError}
                        </p>
                    )}
                    <Button variant="danger" onClick={() => setClosing(true)}>
                        {tc('console.delete_organization_a70d189a')}
                    </Button>

                    <ConfirmDelete
                        open={closing}
                        onOpenChange={setClosing}
                        name={organization.name}
                        consequence="Every member is signed out of it and refused at every door, and its apps stop accepting their sign-ins. This cannot be undone from the console."
                        onConfirm={() => {
                            setClosing(false);
                            router.delete(closeOrganizationHref, {
                                data: { name: organization.name },
                            });
                        }}
                    />
                </Panel>
            )}
        </div>
    );
}

Settings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
