import { useTranslator as usePublicTranslator } from '@/i18n';
import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, OrganizationFilter, OrganizationPicker, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    Field,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    OrganizationPickerField,
    PageHeader,
    Panel,
    Pill,
    ProviderMark,
    RadioGroup,
    Textarea,
} from '@/ui';

/** A provider set up for the environment or for one organization, with its controls. */
interface ProviderRow {
    id: string;
    name: string;
    provider: string | null;
    protocol: string;
    enabled: boolean;
    /** The REAL redirect URI — it contains the provider's id. */
    callbackUri: string;
    /** Extra scopes, beyond the ones sign-in needs. */
    scopes: string[];
    /** Whose page offers it — named on the environment view's list of organizations' own. */
    organization: string | null;
    /** An organization's own that stands in for the environment's on its page. */
    replacesEnvironment: boolean;
    editHref: string;
    enableHref: string;
    disableHref: string;
    removeHref: string;
}

/** One button on one organization's sign-in page, and where it comes from. */
interface PageRow {
    id: string;
    name: string;
    provider: string | null;
    protocol: string;
    enabled: boolean;
    callbackUri: string;
    source: 'environment' | 'organization';
    /**
     * `offered` — on the page; `hidden` — the environment's, turned off for this page;
     * `replaced` — the environment's, with the organization's own in its place; `off` —
     * turned off by its owner.
     */
    state: 'offered' | 'hidden' | 'replaced' | 'off';
    /** Turn the environment's off for this page, or back on. Null where it would change nothing. */
    inheritHref: string | null;
    // An organization's own row carries the full controls as well.
    scopes?: string[];
    replacesEnvironment?: boolean;
    editHref?: string;
    enableHref?: string;
    disableHref?: string;
    removeHref?: string;
}

/** An organization that turned one of the environment's providers off for its page. */
interface OptOutRow {
    organizationId: string;
    organization: string;
    provider: string;
    name: string;
    inheritHref: string;
}

interface CatalogueEntry {
    key: string;
    name: string;
    protocol: string;
    href: string;
}

interface Parameter {
    key: string;
    label: string;
    help: string;
    example: string;
    /** A PEM key is four lines, not a word — and is never shown back. */
    multiline: boolean;
}

interface Template {
    key: string;
    name: string;
    protocol: string;
    documentationUrl: string | null;
    setupSteps: string[];
    parameters: Parameter[];
    /** Apple issues no client secret — it mints a signed assertion from a key instead. */
    mintsItsOwnSecret: boolean;
}

interface SetupTemplate extends Template {
    /** The id reserved for the provider, so its redirect URI is real before it is saved. */
    reservedId: string;
    redirectUri: string;
}

interface Editing extends Template {
    id: string;
    /** Null for the environment's own. */
    organization: string | null;
    redirectUri: string;
    clientId: string;
    /** What is on file for each provider value; empty for key material, which is never shown. */
    values: Record<string, string>;
    scopes: string;
    updateHref: string;
    fromOrganization: boolean;
}

type Props = PageProps<{
    /** The environment's providers and every organization's, or what one organization's page shows. */
    view: 'environment' | 'organization';
    organizationName: string | null;
    environmentProviders: ProviderRow[];
    organizationProviders: ProviderRow[];
    optOuts: OptOutRow[];
    page: PageRow[];
    available: CatalogueEntry[];
    template: SetupTemplate | null;
    editing: Editing | null;
    /** The environment-wide list's Organization chip; null on the organization console. */
    organizationFilter: OrganizationFilter | null;
    /** "Who is it for?" on the setup form — the environment console only. */
    organization: OrganizationPicker | null;
    /** Back to the environment's providers, from one organization's view on the environment console. */
    environmentHref: string | null;
    /** The organization's Authentication policy, on the organization view; null otherwise. */
    policyHref: string | null;
    /**
     * A single-tenant install's organization console, whose administrators are the
     * environment's: the environment's providers are managed here too, and the setup form
     * asks whether a provider is for every sign-in page or only this organization's.
     */
    ownerChoice: boolean;
    indexHref: string;
    storeHref: string;
    help: HelpContent;
    /** Whose sign-in these buttons are on — null on an organization console. */
    audience: { kind: 'platform' | 'environment'; host: string; consoleHost: string | null } | null;
}>;

export default function SocialProviders({
    view,
    organizationName,
    environmentProviders,
    organizationProviders,
    optOuts,
    page,
    available,
    template,
    editing,
    organizationFilter,
    organization,
    environmentHref,
    policyHref,
    ownerChoice,
    indexHref,
    storeHref,
    help,
    audience,
}: Props) {
    const { t: tc } = usePublicTranslator();
    const { t: consoleLastText } = useConsoleTranslator();
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleNestedText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [removing, setRemoving] = useState<{ name: string; href: string } | null>(null);
    const [turningOff, setTurningOff] = useState<{
        name: string;
        href: string;
        everywhere: boolean;
    } | null>(null);

    const controls: RowControls = {
        onRemove: (row) => setRemoving({ name: row.name, href: row.removeHref }),
        onTurnOff: (row, everywhere) =>
            setTurningOff({ name: row.name, href: row.disableHref, everywhere }),
    };

    return (
        <>
            <PageHeader
                help={help}
                description={
                    view === 'environment'
                        ? consoleText(
                              'console.let_people_sign_in_with_an_account_they_already_have_se_de3c77a6',
                          )
                        : consoleFinalText(
                              'console.what_part1_s_sign_in_page_offers_providers_from_the_env_efc01f8e',
                              {
                                  part1:
                                      organizationName ??
                                      consoleNestedText('console.this_organization_cab32fc4'),
                              },
                          )
                }
            />

            <div className="mt-6 space-y-5">
                {audience !== null && <AudienceNote audience={audience} />}

                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}

                {editing !== null && <EditPanel editing={editing} cancelHref={indexHref} />}

                {template !== null && (
                    <SetupPanel
                        template={template}
                        organization={organization}
                        ownerChoice={
                            ownerChoice
                                ? (organizationName ?? tc('console.this_organization_cab32fc4'))
                                : null
                        }
                        storeHref={storeHref}
                        cancelHref={indexHref}
                    />
                )}

                {view === 'environment' ? (
                    <>
                        <Panel
                            title={consoleT('console.on_every_sign_in_page_27ea6bb3')}
                            description={consoleT(
                                'console.your_own_credentials_with_each_provider_offered_to_ever_d1715d1a',
                            )}
                        >
                            {environmentProviders.length === 0 ? (
                                <Empty
                                    lines={[
                                        consoleLastText(
                                            'console.no_providers_for_the_environment_yet_people_sign_in_wit_c6bb86e5',
                                        ),
                                        consoleLastText(
                                            'console.choose_one_under_add_a_provider_to_offer_it_on_every_si_b0f45965',
                                        ),
                                    ]}
                                />
                            ) : (
                                <ul>
                                    {environmentProviders.map((row, index) => (
                                        <ProviderItem
                                            key={row.id}
                                            row={row}
                                            last={index === environmentProviders.length - 1}
                                            everywhere
                                            controls={controls}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Panel>

                        {(organizationProviders.length > 0 || optOuts.length > 0) && (
                            <Panel
                                title={consoleT('console.set_by_one_organization_0d4057d6')}
                                description={consoleT(
                                    'console.an_organization_s_own_provider_replaces_the_environment_5a3f34fa',
                                )}
                            >
                                <ul>
                                    {organizationProviders.map((row, index) => (
                                        <ProviderItem
                                            key={row.id}
                                            row={row}
                                            last={
                                                index === organizationProviders.length - 1 &&
                                                optOuts.length === 0
                                            }
                                            everywhere={false}
                                            controls={controls}
                                        />
                                    ))}
                                    {optOuts.map((row, index) => (
                                        <li
                                            key={`${row.organizationId}:${row.provider}`}
                                            className="flex flex-wrap items-center gap-3 py-3.5"
                                            style={
                                                index < optOuts.length - 1
                                                    ? { borderBottom: '1px solid var(--border)' }
                                                    : undefined
                                            }
                                        >
                                            <ProviderMark provider={row.provider} size={20} />
                                            <p className="min-w-0 flex-1 text-sm">
                                                {consoleRich(
                                                    'console.social_provider_disabled_organization',
                                                    {
                                                        provider: (
                                                            <span className="font-medium">
                                                                {row.name}
                                                            </span>
                                                        ),
                                                        organization: row.organization,
                                                    },
                                                )}
                                            </p>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.put(
                                                        row.inheritHref,
                                                        {
                                                            organization: row.organizationId,
                                                            offered: true,
                                                        },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                {consoleT('console.offer_it_again_c7715a69')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </Panel>
                        )}
                    </>
                ) : (
                    <>
                        {ownerChoice && (
                            <Panel
                                title={consoleT('console.on_every_sign_in_page_27ea6bb3')}
                                description={consoleT(
                                    'console.your_own_credentials_with_each_provider_offered_on_ever_82ce1e08',
                                )}
                            >
                                {environmentProviders.length === 0 ? (
                                    <Empty
                                        lines={[
                                            consoleLastText(
                                                'console.no_providers_for_the_whole_environment_yet_91fd34a0',
                                            ),
                                            consoleLastText(
                                                'console.choose_one_under_add_a_provider_it_is_offered_on_every_fe325acd',
                                            ),
                                        ]}
                                    />
                                ) : (
                                    <ul>
                                        {environmentProviders.map((row, index) => (
                                            <ProviderItem
                                                key={row.id}
                                                row={row}
                                                last={index === environmentProviders.length - 1}
                                                everywhere
                                                controls={controls}
                                            />
                                        ))}
                                    </ul>
                                )}
                            </Panel>
                        )}
                        <Panel
                            title={consoleFinalText('console.on_part1_s_sign_in_page_c900d0aa', {
                                part1:
                                    organizationName ??
                                    consoleNestedText('console.this_organization_cab32fc4'),
                            })}
                            description={
                                <>
                                    {consoleRich(
                                        'console.in_the_order_people_see_them_turning_one_of_the_environ_390888a5',
                                        {
                                            part1: ' ',
                                            part2:
                                                policyHref === null ? (
                                                    consoleNestedText(
                                                        'console.authentication_policy_07e21daf',
                                                    )
                                                ) : (
                                                    <Link href={policyHref}>
                                                        {consoleT(
                                                            'console.authentication_policy_07e21daf',
                                                        )}
                                                    </Link>
                                                ),
                                        },
                                    )}
                                </>
                            }
                            action={
                                environmentHref !== null ? (
                                    <Link href={environmentHref} className="btn btn-ghost btn-sm">
                                        {consoleT('console.the_environment_s_providers_e11ef97b')}
                                    </Link>
                                ) : undefined
                            }
                        >
                            {page.length === 0 ? (
                                <Empty
                                    lines={[
                                        consoleLastText(
                                            'console.no_social_providers_here_yet_people_sign_in_with_a_pass_a33ed020',
                                        ),
                                        consoleLastText(
                                            'console.add_one_below_to_offer_it_on_this_organization_s_page_51427e0a',
                                        ),
                                    ]}
                                />
                            ) : (
                                <ul>
                                    {page.map((row, index) => (
                                        <PageItem
                                            key={row.id}
                                            row={row}
                                            last={index === page.length - 1}
                                            organizationName={organizationName}
                                            controls={controls}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </>
                )}

                {available.length > 0 && (
                    <Panel
                        title={consoleT('console.add_a_provider_f03153fc')}
                        description={consoleT(
                            'console.you_need_credentials_from_your_own_account_with_the_pro_6556573b',
                        )}
                    >
                        <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                            {available.map((option) => {
                                const chosen = template?.key === option.key;

                                return (
                                    <Link
                                        key={option.key}
                                        href={option.href}
                                        preserveScroll
                                        aria-current={chosen ? 'true' : undefined}
                                        aria-label={consoleFinalText('console.add_part1_abe41350', {
                                            part1: option.name,
                                        })}
                                        className="flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-start transition"
                                        style={{
                                            border: `1px solid ${chosen ? 'var(--accent)' : 'var(--border)'}`,
                                            background: chosen
                                                ? 'var(--accent-soft)'
                                                : 'transparent',
                                        }}
                                    >
                                        <ProviderMark provider={option.key} size={20} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium text-sm truncate">
                                                {option.name}
                                            </span>
                                            <span
                                                className="block text-xs truncate"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {option.protocol}
                                            </span>
                                        </span>
                                    </Link>
                                );
                            })}
                        </div>
                    </Panel>
                )}
            </div>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.name ?? ''}
                verb={consoleShortText('console.remove_c3812fc4')}
                consequence={consoleUiText(
                    'console.it_stops_appearing_as_a_button_and_its_credentials_are_120d1fa5',
                )}
                onConfirm={() => {
                    const target = removing;
                    setRemoving(null);

                    if (target !== null) {
                        router.delete(target.href, { preserveScroll: true });
                    }
                }}
            />

            <ConfirmDelete
                open={turningOff !== null}
                onOpenChange={(open) => !open && setTurningOff(null)}
                name={turningOff?.name ?? ''}
                verb={consoleAttributeText('console.turn_off_06f0e210')}
                consequence={
                    turningOff?.everywhere === true
                        ? consoleAttributeText(
                              'console.the_button_disappears_from_every_sign_in_page_in_this_e_c817a046',
                          )
                        : consoleAttributeText(
                              'console.the_button_disappears_from_this_organization_s_sign_in_88a1cc4f',
                          )
                }
                onConfirm={() => {
                    const target = turningOff;
                    setTurningOff(null);

                    if (target !== null) {
                        router.post(target.href, {}, { preserveScroll: true });
                    }
                }}
            />
        </>
    );
}

interface RowControls {
    onRemove: (row: { name: string; removeHref: string }) => void;
    onTurnOff: (row: { name: string; disableHref: string }, everywhere: boolean) => void;
}

function Empty({ lines }: { lines: string[] }) {
    return (
        <div className="py-6 text-center">
            {lines.map((line) => (
                <p
                    key={line}
                    className="text-sm mt-1 first:mt-0"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    {line}
                </p>
            ))}
        </div>
    );
}

/**
 * The redirect URI a provider must be given, copyable. Shown on every row: it is available
 * nowhere else, and a mismatch fails with an error naming the client id rather than the URI.
 */
function RedirectUri({ name, uri }: { name: string; uri: string }) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleT } = useConsoleTranslator();
    return (
        <p className="mt-1.5 flex items-center gap-2 flex-wrap">
            <span className="mono text-xs break-all" style={{ color: 'var(--muted-foreground)' }}>
                <span style={{ color: 'var(--foreground)' }}>
                    {consoleT('console.redirect_uri_de6f19ce')}
                </span>{' '}
                {uri}
            </span>
            <CopyButton
                size="sm"
                value={uri}
                label={consoleFinalText('console.copy_the_redirect_uri_for_part1_5c877cd5', {
                    part1: name,
                })}
            />
        </p>
    );
}

/** The controls a provider's OWNER has: change, turn off or on, remove. */
function OwnerControls({
    row,
    everywhere,
    controls,
}: {
    row: {
        name: string;
        enabled: boolean;
        editHref: string;
        enableHref: string;
        disableHref: string;
        removeHref: string;
    };
    everywhere: boolean;
    controls: RowControls;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleT } = useConsoleTranslator();
    return (
        <div className="flex flex-wrap gap-2">
            <Button size="sm" asChild>
                <Link
                    href={row.editHref}
                    preserveScroll
                    aria-label={consoleFinalText('console.change_part1_518348c7', {
                        part1: row.name,
                    })}
                >
                    {consoleT('console.change_c0bf75bd')}
                </Link>
            </Button>
            {row.enabled ? (
                <Button
                    size="sm"
                    onClick={() => controls.onTurnOff(row, everywhere)}
                    aria-label={consoleFinalText('console.turn_off_part1_cdd28418', {
                        part1: row.name,
                    })}
                >
                    {consoleT('console.turn_off_06f0e210')}
                </Button>
            ) : (
                <Button
                    size="sm"
                    onClick={() => router.post(row.enableHref, {}, { preserveScroll: true })}
                    aria-label={consoleFinalText('console.turn_on_part1_41acb93f', {
                        part1: row.name,
                    })}
                >
                    {consoleT('console.turn_on_5a1f096a')}
                </Button>
            )}
            <Button
                size="sm"
                variant="danger"
                onClick={() => controls.onRemove(row)}
                aria-label={consoleFinalText('console.remove_part1_369983dd', { part1: row.name })}
            >
                {consoleT('console.remove_c3812fc4')}
            </Button>
        </div>
    );
}

function ProviderItem({
    row,
    last,
    everywhere,
    controls,
}: {
    row: ProviderRow;
    last: boolean;
    everywhere: boolean;
    controls: RowControls;
}) {
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <li
            className="flex flex-col gap-3 py-3.5 sm:flex-row sm:items-start"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="flex min-w-0 flex-1 gap-3">
                <ProviderMark provider={row.provider ?? ''} size={20} />
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-sm flex flex-wrap items-center gap-1.5">
                        {row.name}
                        {row.organization !== null && <Badge>{row.organization}</Badge>}
                        {row.replacesEnvironment && (
                            <Badge>{consoleT('console.replaces_the_environment_s_426dcbe4')}</Badge>
                        )}
                        <Pill tone={row.enabled ? 'success' : 'neutral'}>
                            {row.enabled
                                ? consoleText('console.on_13001175')
                                : consoleText('console.off_ca7981b4')}
                        </Pill>
                    </p>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {row.scopes.length > 0
                            ? consoleT('console.provider_scopes', {
                                  protocol: row.protocol,
                                  scopes: row.scopes.join(' '),
                              })
                            : row.protocol}
                    </p>
                    <RedirectUri name={row.name} uri={row.callbackUri} />
                </div>
            </div>
            <OwnerControls row={row} everywhere={everywhere} controls={controls} />
        </li>
    );
}

const PAGE_STATE = (
    t: Translator['t'],
): Record<PageRow['state'], { label: string; tone: 'success' | 'neutral' }> => ({
    offered: { label: t('console.on_13001175'), tone: 'success' },
    hidden: { label: t('console.turned_off_here_701c16b0'), tone: 'neutral' },
    replaced: { label: t('console.replaced_by_its_own_59cb9f23'), tone: 'neutral' },
    off: { label: t('console.off_ca7981b4'), tone: 'neutral' },
});

/** One button on one organization's page — inherited, or the organization's own. */
function PageItem({
    row,
    last,
    organizationName,
    controls,
}: {
    row: PageRow;
    last: boolean;
    organizationName: string | null;
    controls: RowControls;
}) {
    const { t: consoleNestedText } = useConsoleTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const own = row.source === 'organization';
    const state = PAGE_STATE(consoleOptionText)[row.state];

    return (
        <li
            className="flex flex-col gap-3 py-3.5 sm:flex-row sm:items-start"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="flex min-w-0 flex-1 gap-3">
                <ProviderMark provider={row.provider ?? ''} size={20} />
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-sm flex flex-wrap items-center gap-1.5">
                        {row.name}
                        <Badge>
                            {own
                                ? consoleFinalText('console._part1_s_own_8576fa02', {
                                      part1:
                                          organizationName ??
                                          consoleNestedText('console.this_organization_bdaad4df'),
                                  })
                                : consoleText('console.from_the_environment_4511e421')}
                        </Badge>
                        {own && row.replacesEnvironment === true && (
                            <Badge>{consoleT('console.replaces_the_environment_s_426dcbe4')}</Badge>
                        )}
                        <Pill tone={state.tone}>{state.label}</Pill>
                    </p>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {row.protocol}
                        {row.state === 'off' &&
                            !own &&
                            consoleRuntimeText(
                                'console._turned_off_for_the_whole_environment_d3ef7aec',
                            )}
                    </p>
                    {own && <RedirectUri name={row.name} uri={row.callbackUri} />}
                </div>
            </div>
            {own &&
            row.editHref !== undefined &&
            row.enableHref !== undefined &&
            row.disableHref !== undefined &&
            row.removeHref !== undefined ? (
                <OwnerControls
                    row={{
                        name: row.name,
                        enabled: row.enabled,
                        editHref: row.editHref,
                        enableHref: row.enableHref,
                        disableHref: row.disableHref,
                        removeHref: row.removeHref,
                    }}
                    everywhere={false}
                    controls={controls}
                />
            ) : (
                row.inheritHref !== null &&
                (row.state === 'offered' || row.state === 'hidden') && (
                    <Button
                        size="sm"
                        aria-label={
                            row.state === 'offered'
                                ? consoleFinalText('console.turn_off_part1_on_this_page_e3c91b07', {
                                      part1: row.name,
                                  })
                                : consoleFinalText(
                                      'console.offer_part1_on_this_page_again_924a212a',
                                      { part1: row.name },
                                  )
                        }
                        onClick={() => {
                            const href = row.inheritHref;

                            if (href !== null) {
                                router.put(
                                    href,
                                    { offered: row.state === 'hidden' },
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        {row.state === 'offered'
                            ? consoleText('console.turn_off_here_73de69be')
                            : consoleText('console.offer_it_again_c7715a69')}
                    </Button>
                )
            )}
        </li>
    );
}

/**
 * The credential fields both forms share, in the order the work happens: the provider's own
 * values, the client id, the secret (or Apple's note that there is none), extra scopes.
 */
function CredentialFields({
    template,
    data,
    errors,
    setData,
    keepingSecrets,
}: {
    template: Template;
    data: {
        clientId: string;
        clientSecret: string;
        parameters: Record<string, string>;
        scopes: string;
    };
    errors: Partial<Record<string, string>>;
    setData: (key: 'clientId' | 'clientSecret' | 'parameters' | 'scopes', value: never) => void;
    /** On the change form: a blank secret keeps the one on file. */
    keepingSecrets: boolean;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const setParameter = (key: string, value: string) =>
        setData('parameters', { ...data.parameters, [key]: value } as never);

    return (
        <>
            {template.parameters.map((parameter) => (
                <Field
                    key={parameter.key}
                    label={parameter.label}
                    hint={
                        keepingSecrets && parameter.multiline
                            ? consoleText('console.leave_empty_to_keep_the_one_on_file_c2fa1a1e')
                            : parameter.help === ''
                              ? undefined
                              : parameter.help
                    }
                    error={errors[`parameters.${parameter.key}`]}
                >
                    {parameter.multiline ? (
                        <Textarea
                            rows={4}
                            className="mono text-xs"
                            placeholder={parameter.example}
                            value={data.parameters[parameter.key] ?? ''}
                            onChange={(event) => setParameter(parameter.key, event.target.value)}
                        />
                    ) : (
                        <Input
                            placeholder={parameter.example}
                            value={data.parameters[parameter.key] ?? ''}
                            onChange={(event) => setParameter(parameter.key, event.target.value)}
                        />
                    )}
                </Field>
            ))}

            <Field
                label={
                    template.mintsItsOwnSecret
                        ? consoleText('console.services_id_f7ca55a9')
                        : consoleText('console.client_id_8726db01')
                }
                hint={
                    template.mintsItsOwnSecret
                        ? consoleText(
                              'console.the_services_id_you_enabled_sign_in_with_apple_on_not_t_c93a711d',
                          )
                        : undefined
                }
                error={errors.clientId}
            >
                <Input
                    name="clientId"
                    value={data.clientId}
                    onChange={(event) => setData('clientId', event.target.value as never)}
                />
            </Field>

            {/*
                ASKED FOR ONLY WHERE ONE EXISTS. Apple issues no client secret: the
                credential is an ES256 assertion minted per request from the key above.
            */}
            {template.mintsItsOwnSecret ? (
                <p
                    className="text-sm rounded-lg px-3 py-2.5"
                    style={{
                        background: 'var(--surface-2)',
                        border: '1px solid var(--border)',
                        color: 'var(--muted-foreground)',
                    }}
                >
                    {consoleRich(
                        'console.there_is_no_client_secret_to_paste_part1_expects_a_sign_574fe375',
                        { part1: template.name },
                    )}
                </p>
            ) : (
                <Field
                    label={consoleT('console.client_secret_4aded5fa')}
                    hint={
                        keepingSecrets
                            ? consoleText('console.leave_empty_to_keep_the_one_on_file_c2fa1a1e')
                            : undefined
                    }
                    error={errors.clientSecret}
                >
                    <Input
                        name="clientSecret"
                        type="password"
                        autoComplete="off"
                        value={data.clientSecret}
                        onChange={(event) => setData('clientSecret', event.target.value as never)}
                    />
                </Field>
            )}

            <Field
                label={consoleT('console.extra_scopes_optional_4f0ad065')}
                hint={consoleFinalText(
                    'console.only_if_your_app_needs_more_from_part1_than_sign_in_doe_fc539001',
                    { part1: template.name },
                )}
                error={errors.scopes}
            >
                <Input
                    name="scopes"
                    className="mono"
                    value={data.scopes}
                    onChange={(event) => setData('scopes', event.target.value as never)}
                />
            </Field>
        </>
    );
}

/** A copyable redirect URI on a setup or change form. */
function RedirectUriBlock({ uri }: { uri: string }) {
    const { t: consoleT } = useConsoleTranslator();
    return (
        <div className="mt-2 flex items-center gap-2">
            <p
                className="mono text-xs break-all rounded-lg px-3 py-2 min-w-0 flex-1"
                style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
            >
                {uri}
            </p>
            <CopyButton value={uri} label={consoleT('console.copy_the_redirect_uri_d632d11a')} />
        </div>
    );
}

function PanelHead({ template, title }: { template: Template; title: string }) {
    const { rich: consoleRich } = useConsoleTranslator();
    return (
        <div
            className="p-4 flex items-center gap-2.5"
            style={{ borderBottom: '1px solid var(--border)' }}
        >
            <ProviderMark provider={template.key} size={22} />
            <div className="min-w-0">
                <h2 className="font-semibold text-sm">{title}</h2>
                <p className="text-sm mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                    {template.protocol}
                    {template.documentationUrl !== null && (
                        <>
                            {' · '}
                            <a
                                href={template.documentationUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline underline-offset-2"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                {consoleRich('console._part1_s_own_guide_b4f507d4', {
                                    part1: template.name,
                                })}
                            </a>
                        </>
                    )}
                </p>
            </div>
        </div>
    );
}

/**
 * The setup panel, in the order the work actually happens.
 *
 * WHO IT IS FOR comes first on the environment console, and defaults to the whole
 * environment — the common case, and the one the button on every sign-in page needs. The
 * redirect URI comes next, before the credential fields, and it is the REAL one: the id it
 * contains was reserved when this form was drawn, so it can be pasted into the provider's
 * console now rather than fixed up after saving.
 */
function SetupPanel({
    template,
    organization,
    ownerChoice,
    storeHref,
    cancelHref,
}: {
    template: SetupTemplate;
    organization: OrganizationPicker | null;
    /** The organization's name where the form may also set a provider up for every page. */
    ownerChoice: string | null;
    storeHref: string;
    cancelHref: string;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        provider: template.key,
        organization: organization?.selected?.id ?? '',
        // The whole environment first, wherever this form may choose it: it is what "turn
        // on Google for my app" means.
        forEnvironment: ownerChoice !== null,
        reservedId: template.reservedId,
        clientId: '',
        clientSecret: '',
        scopes: '',
        parameters: Object.fromEntries(
            template.parameters.map((parameter) => [parameter.key, '']),
        ) as Record<string, string>,
    });
    const forEnvironment =
        (organization !== null && form.data.organization === '') ||
        (ownerChoice !== null && form.data.forEnvironment);

    return (
        <div className="card">
            <PanelHead
                template={template}
                title={consoleFinalText('console.set_up_part1_9cb13ea2', { part1: template.name })}
            />

            <form
                className="p-4 space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, { preserveScroll: true });
                }}
            >
                {organization !== null && (
                    <OrganizationPickerField
                        picker={organization}
                        label={consoleT('console.who_is_it_for_96ddb8a4')}
                        error={form.errors.organization}
                        onChange={(id) => form.setData('organization', id)}
                        hint={consoleT(
                            'console.the_whole_environment_offers_it_on_every_sign_in_page_c_6d75dee5',
                        )}
                    />
                )}

                {ownerChoice !== null && (
                    <RadioGroup
                        label={consoleT('console.who_is_it_for_96ddb8a4')}
                        name="forEnvironment"
                        value={form.data.forEnvironment ? 'environment' : 'organization'}
                        onValueChange={(value) =>
                            form.setData('forEnvironment', value === 'environment')
                        }
                        options={[
                            {
                                value: 'environment',
                                label: consoleText(
                                    'console.every_sign_in_page_in_this_environment_1aab0c59',
                                ),
                                hint: consoleText(
                                    'console.every_organization_here_inherits_it_fc5fe521',
                                ),
                            },
                            {
                                value: 'organization',
                                label: consoleFinalText(
                                    'console.only_part1_s_sign_in_page_1fce5b6e',
                                    { part1: ownerChoice },
                                ),
                                hint: consoleText(
                                    'console.its_own_credentials_in_place_of_the_environment_s_for_t_bf11c9ef',
                                ),
                            },
                        ]}
                    />
                )}

                <div>
                    <p className="text-sm font-semibold">
                        {consoleT('console.1_register_this_redirect_uri_9c27ff08')}
                    </p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console._part1_refuses_the_sign_in_unless_this_matches_exactly_8d9cdd26',
                            { part1: template.name, part2: template.name },
                        )}
                    </p>
                    <RedirectUriBlock uri={template.redirectUri} />
                </div>

                {template.setupSteps.length > 0 && (
                    <div>
                        <p className="text-sm font-semibold">
                            {consoleRich('console.2_in_part1_7c638ecc', { part1: template.name })}
                        </p>
                        <ol
                            className="mt-2 space-y-1.5 text-sm list-decimal ps-5"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {template.setupSteps.map((step) => (
                                <li key={step}>{step}</li>
                            ))}
                        </ol>
                    </div>
                )}

                <div className="space-y-4">
                    <p className="text-sm font-semibold">
                        {consoleRich('console.3_paste_what_part1_gave_you_c9b6b1ec', {
                            part1: template.name,
                        })}
                    </p>
                    <CredentialFields
                        template={template}
                        data={form.data}
                        errors={form.errors as Partial<Record<string, string>>}
                        setData={(key, value) => form.setData(key, value)}
                        keepingSecrets={false}
                    />
                </div>

                <div className="flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
                    <Button asChild>
                        <Link href={cancelHref} preserveScroll>
                            {consoleT('console.cancel_19766ed6')}
                        </Link>
                    </Button>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {form.processing
                            ? consoleFinalText('console.checking_with_part1_1374a323', {
                                  part1: template.name,
                              })
                            : forEnvironment
                              ? consoleFinalText('console.turn_on_part1_for_everyone_ef925d74', {
                                    part1: template.name,
                                })
                              : consoleFinalText('console.turn_on_part1_41acb93f', {
                                    part1: template.name,
                                })}
                    </Button>
                </div>
            </form>
        </div>
    );
}

/** New credentials for a provider that exists — rotating a secret keeps its redirect URI. */
function EditPanel({ editing, cancelHref }: { editing: Editing; cancelHref: string }) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        clientId: editing.clientId,
        clientSecret: '',
        scopes: editing.scopes,
        parameters: editing.values,
        fromOrganization: editing.fromOrganization,
    });

    return (
        <div className="card">
            <PanelHead
                template={editing}
                title={
                    editing.organization === null
                        ? consoleFinalText('console.change_environment_provider', {
                              provider: editing.name,
                          })
                        : consoleFinalText('console.change_organization_provider', {
                              provider: editing.name,
                              organization: editing.organization,
                          })
                }
            />

            <form
                className="p-4 space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(editing.updateHref, { preserveScroll: true });
                }}
            >
                <div>
                    <p className="text-sm font-semibold">
                        {consoleT('console.redirect_uri_98e548b4')}
                    </p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console.unchanged_by_anything_here_so_nothing_to_update_in_part_75ac8c0d',
                            { part1: editing.name },
                        )}
                    </p>
                    <RedirectUriBlock uri={editing.redirectUri} />
                </div>

                <CredentialFields
                    template={editing}
                    data={form.data}
                    errors={form.errors as Partial<Record<string, string>>}
                    setData={(key, value) => form.setData(key, value)}
                    keepingSecrets
                />

                <div className="flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
                    <Button asChild>
                        <Link href={cancelHref} preserveScroll>
                            {consoleT('console.cancel_19766ed6')}
                        </Link>
                    </Button>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleRich('console.save_part1_d2b657ed', { part1: editing.name })}
                    </Button>
                </div>
            </form>
        </div>
    );
}

SocialProviders.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;

/**
 * WHOSE SIGN-IN THIS IS. The platform's own environment and a tenant's have the same page
 * and very different audiences, and a provider set up on the wrong one is a button nobody
 * who wanted it ever sees.
 */
function AudienceNote({ audience }: { audience: NonNullable<Props['audience']> }) {
    const { rich: consoleRich } = useConsoleTranslator();
    if (audience.kind === 'platform') {
        return (
            <p className="cbx-shell-notice" role="note">
                <Icon name="info" className="w-4 h-4 shrink-0" />
                <span className="min-w-0 flex-1">
                    {consoleRich(
                        'console.these_are_the_buttons_on_part1_how_every_organization_o_ca549807',
                        {
                            part1: (
                                <span className="mono">
                                    {consoleRich('console._part1_login_cd2b0075', {
                                        part1: audience.host,
                                    })}
                                </span>
                            ),
                            part2: ' ',
                            part3: <span className="mono">{audience.host}</span>,
                        },
                    )}
                </span>
            </p>
        );
    }

    return (
        <p className="cbx-shell-notice" role="note">
            <Icon name="info" className="w-4 h-4 shrink-0" />
            <span className="min-w-0 flex-1">
                {consoleRich(
                    'console.these_buttons_are_for_the_people_who_sign_in_to_your_ap_580f5efd',
                    {
                        part1: ' ',
                        part2: <span className="mono">{audience.host}</span>,
                        part3: audience.consoleHost !== null && (
                            <>
                                {consoleRich(
                                    'console._part1_this_console_is_not_signed_in_to_with_them_admin_a3d216ed',
                                    {
                                        part1: ' ',
                                        part2: <span className="mono">{audience.consoleHost}</span>,
                                    },
                                )}
                            </>
                        ),
                    },
                )}
            </span>
        </p>
    );
}
