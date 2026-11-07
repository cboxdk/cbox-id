import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ name: organization?.name ?? '' });
    const [closing, setClosing] = useState(false);
    const closeError = usePage<Props>().props.errors.name;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    <>
                        {consoleRich(
                            'console.what_you_are_administering_and_the_details_your_apps_ne_23bc71ef',
                            {
                                part1: environmentRecord === null && (
                                    <>
                                        {consoleRich(
                                            'console._part1_manage_your_own_security_under_part2_part3_a21c8866',
                                            {
                                                part1: ' ',
                                                part2: ' ',
                                                part3: (
                                                    <Link
                                                        href={accountHref}
                                                        className="underline"
                                                        style={{ color: 'var(--accent-strong)' }}
                                                    >
                                                        {consoleT('console.my_account_b53181a4')}
                                                    </Link>
                                                ),
                                            },
                                        )}
                                    </>
                                ),
                            },
                        )}
                    </>
                }
            />

            {environmentRecord !== null && (
                <Panel
                    title={consoleT('console.environment_9e471951')}
                    description={consoleT(
                        'console.this_environment_every_organization_below_lives_inside_afb44516',
                    )}
                >
                    <KvList>
                        <Kv label={consoleT('console.name_dcd1d522')} prose>
                            {environmentRecord.name}{' '}
                            {environmentRecord.sandbox && (
                                <Badge tone="warn">{consoleT('console.sandbox_67fc6249')}</Badge>
                            )}
                        </Kv>
                        <Kv label={consoleT('console.environment_id_9ff84b43')}>
                            {environmentRecord.id}
                        </Kv>
                    </KvList>
                </Panel>
            )}

            {organizationsHref !== null && (
                <Panel
                    title={consoleT('console.organizations_2730183d')}
                    description={consoleT(
                        'console.each_organization_s_name_handle_and_details_are_on_its_e422089c',
                    )}
                >
                    <Link href={organizationsHref} className="text-sm">
                        {consoleT('console.go_to_organizations_2afa38a3')}
                    </Link>
                </Panel>
            )}

            {organization !== null && renameHref !== null && (
                <Panel
                    title={consoleT('console.organization_d764d425')}
                    description={consoleT(
                        'console.the_organization_this_console_administers_b1b7dbad',
                    )}
                >
                    <>
                        <form
                            className="mb-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.patch(renameHref, { preserveScroll: true });
                            }}
                        >
                            <Field
                                label={consoleT('console.name_dcd1d522')}
                                error={form.errors.name}
                            >
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
                                        {consoleT('console.rename_3064d79a')}
                                    </Button>
                                </div>
                            </Field>
                        </form>

                        <KvList>
                            <Kv label={consoleT('console.slug_d15387ec')}>{organization.slug}</Kv>
                            <Kv label={consoleT('console.type_baaddf70')} prose>
                                <Badge>{organization.type}</Badge>
                            </Kv>
                            <Kv label={consoleT('console.organization_id_1f466322')}>
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
                    title={consoleT('console.setup_guide_f91058b1')}
                    description={consoleT(
                        'console.the_steps_worth_doing_for_a_new_organization_with_what_295f9381',
                    )}
                >
                    <div className="flex items-center justify-between gap-4">
                        <p className="text-sm min-w-0" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleT(
                                'console.each_step_is_measured_from_live_state_so_the_guide_alwa_e7f5c55e',
                            )}
                        </p>
                        <Button asChild variant="secondary" className="shrink-0">
                            <Link href={setupGuideHref}>
                                {consoleRich('console.open_guide_part1_6968b42e', {
                                    part1: (
                                        <Icon
                                            name="chevron"
                                            className="w-4 h-4"
                                            style={{ transform: 'rotate(-90deg)' }}
                                        />
                                    ),
                                })}
                            </Link>
                        </Button>
                    </div>
                </Panel>
            )}

            <Panel
                title={consoleT('console.login_branding_ff44c60c')}
                description={
                    organization !== null ? (
                        <>
                            {consoleRich(
                                'console.theme_this_organization_s_sign_in_page_its_team_signs_i_cceda878',
                                {
                                    part1: ' ',
                                    part2: (
                                        <a
                                            href={organization.brandedLoginHref}
                                            className="mono underline"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            {consoleRich('console._o_part1_login_41752fcf', {
                                                part1: organization.slug,
                                            })}
                                        </a>
                                    ),
                                },
                            )}
                        </>
                    ) : (
                        consoleRuntimeText(
                            'console.the_environment_s_default_sign_in_theme_inherited_by_ev_aa90ec1d',
                        )
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
                                {consoleRich('console._part1_theme_1e426f5c', {
                                    part1: appearance.preset,
                                })}
                            </p>
                            <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                {consoleT(
                                    'console.presets_colours_corners_type_edited_with_a_live_preview_ff61e898',
                                )}
                            </p>
                        </div>
                    </div>

                    <Button asChild variant="secondary" className="shrink-0">
                        <Link href={appearanceHref}>
                            {consoleRich('console.open_editor_part1_caf3e64a', {
                                part1: (
                                    <Icon
                                        name="chevron"
                                        className="w-4 h-4"
                                        style={{ transform: 'rotate(-90deg)' }}
                                    />
                                ),
                            })}
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
                    title={consoleT('console.integration_9a42d40a')}
                    description={consoleT(
                        'console.point_your_oidc_client_at_these_discovery_exposes_every_81f0fa9a',
                    )}
                >
                    <div className="space-y-3">
                        {(
                            [
                                { label: consoleText('console.issuer_39e02c46'), value: issuer },
                                {
                                    label: consoleText('console.oidc_discovery_885dc27a'),
                                    value: discovery,
                                },
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
                    title={consoleT('console.delete_organization_a70d189a')}
                    description={consoleT(
                        'console.everyone_in_it_loses_access_immediately_and_it_disappea_6b76018d',
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
                        {consoleT('console.delete_organization_a70d189a')}
                    </Button>

                    <ConfirmDelete
                        open={closing}
                        onOpenChange={setClosing}
                        name={organization.name}
                        consequence={consoleUiText(
                            'console.every_member_is_signed_out_of_it_and_refused_at_every_d_67c21f22',
                        )}
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
