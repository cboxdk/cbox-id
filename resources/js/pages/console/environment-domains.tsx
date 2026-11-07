import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
    Select,
} from '@/ui';

interface Challenge {
    domain: string;
    recordName: string;
    recordValue: string;
    verified: boolean;
}

type Props = PageProps<{
    environments: { id: string; name: string }[];
    selected: string;
    /** The live domain for the chosen environment, or null while none is verified. */
    verifiedDomain: string | null;
    challenge: Challenge | null;
    urls: { request: string; verify: string; destroy: string };
    help: HelpContent;
}>;

export default function EnvironmentDomains({
    environments,
    selected,
    verifiedDomain,
    challenge,
    urls,
    help,
}: Props) {
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    /*
     * "DNS has not propagated" is not an error about the DOMAIN — the value is fine and
     * the record is probably right — so the server reports it under a key no input owns,
     * and the page reads it from the shared bag rather than from a field's own errors.
     */
    const verifyError = usePage().props.errors.verify;

    const [removing, setRemoving] = useState(false);

    return (
        <div style={{ maxWidth: '42rem' }}>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.serve_an_environment_s_identity_endpoints_on_your_own_d_c475397f',
                )}
            />

            {environments.length === 0 ? (
                <div className="card mt-6">
                    <EmptyState
                        icon="layers"
                        title={consoleT('console.no_environments_yet_8c63cac4')}
                        description={consoleT(
                            'console.create_an_environment_first_then_you_can_give_it_a_cust_e7ae7f95',
                        )}
                    />
                </div>
            ) : (
                <div className="mt-6 space-y-6">
                    <Field label={consoleT('console.environment_9e471951')}>
                        <Select
                            value={selected}
                            onValueChange={(environment) =>
                                router.get(
                                    window.location.pathname,
                                    { environment },
                                    { preserveState: true, preserveScroll: true, replace: true },
                                )
                            }
                            options={environments.map((environment) => ({
                                value: environment.id,
                                label: environment.name,
                            }))}
                        />
                    </Field>

                    {verifiedDomain !== null ? (
                        <Live
                            domain={verifiedDomain}
                            environment={selected}
                            onRemove={() => setRemoving(true)}
                        />
                    ) : challenge !== null ? (
                        <Pending
                            challenge={challenge}
                            environment={selected}
                            verifyError={verifyError}
                            urls={urls}
                            onCancel={() => setRemoving(true)}
                        />
                    ) : (
                        <RequestDomain environment={selected} href={urls.request} />
                    )}
                </div>
            )}

            <ConfirmDelete
                open={removing}
                onOpenChange={setRemoving}
                name={
                    verifiedDomain ??
                    challenge?.domain ??
                    consoleAttributeText('console.this_domain_d9e25fa6')
                }
                verb={
                    verifiedDomain !== null
                        ? consoleAttributeText('console.stop_serving_339887ce')
                        : consoleAttributeText('console.cancel_verification_2ce006fd')
                }
                consequence={
                    verifiedDomain !== null
                        ? consoleAttributeText(
                              'console.this_environment_falls_back_to_its_default_domain_every_201d6a2d',
                          )
                        : consoleAttributeText(
                              'console.the_dns_txt_challenge_stops_being_valid_re_adding_the_d_0338f0b8',
                          )
                }
                onConfirm={() => {
                    setRemoving(false);
                    router.delete(urls.destroy, {
                        data: { environment: selected },
                        preserveScroll: true,
                    });
                }}
            />
        </div>
    );
}

/** A domain that is verified and serving. */
function Live({
    domain,
    environment,
    onRemove,
}: {
    domain: string;
    environment: string;
    onRemove: () => void;
}) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <div className="space-y-4">
            <div
                className="rounded-xl border p-4"
                style={{
                    borderColor: 'color-mix(in oklch, var(--success) 35%, transparent)',
                    background: 'var(--success-soft)',
                }}
            >
                <div className="flex items-center gap-2 flex-wrap">
                    <Icon
                        name="shield"
                        className="w-4 h-4"
                        style={{ color: 'var(--success-strong)' }}
                    />
                    <span className="font-medium">{domain}</span>
                    <Badge>{consoleT('console.verified_4f783840')}</Badge>
                </div>
                <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich(
                        'console.this_environment_s_issuer_discovery_and_jwks_are_served_06e9c844',
                        {
                            part1: ' ',
                            part2: (
                                <span className="mono">
                                    {consoleRich('console.https_part1_00ce7acb', { part1: domain })}
                                </span>
                            ),
                        },
                    )}
                </p>
            </div>

            <Button variant="danger" onClick={onRemove} data-environment={environment}>
                {consoleT('console.remove_domain_5ce43507')}
            </Button>
        </div>
    );
}

/**
 * A claim waiting on DNS.
 *
 * The record is the whole content of this state — somebody is going to copy three values
 * into a DNS panel in another tab — so each one has its own copy button rather than being
 * a line of prose they have to select by hand.
 */
function Pending({
    challenge,
    environment,
    verifyError,
    urls,
    onCancel,
}: {
    challenge: Challenge;
    environment: string;
    verifyError: string | undefined;
    urls: { verify: string };
    onCancel: () => void;
}) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [verifying, setVerifying] = useState(false);

    return (
        <Panel
            title={consoleT('console.prove_you_control_the_domain_d57cf229')}
            description={
                <>
                    {consoleRich(
                        'console.add_this_dns_part1_record_at_your_domain_then_verify_it_3d494578',
                        {
                            part1: <b>{consoleT('console.txt_d3dc6ac9')}</b>,
                            part2: <span className="mono">{challenge.domain}</span>,
                        },
                    )}
                </>
            }
        >
            <dl className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                <Record label={consoleT('console.type_baaddf70')} value="TXT" copyable={false} />
                <Record label={consoleT('console.name_dcd1d522')} value={challenge.recordName} />
                <Record
                    label={consoleT('console.value_8e37953d')}
                    value={challenge.recordValue}
                    last
                />
            </dl>

            {verifyError !== undefined && (
                <p className="mt-3 field-error" role="alert">
                    {verifyError}
                </p>
            )}

            <div className="mt-4 flex items-center gap-2">
                <Button
                    variant="primary"
                    loading={verifying}
                    onClick={() => {
                        setVerifying(true);
                        router.post(
                            urls.verify,
                            { environment },
                            { preserveScroll: true, onFinish: () => setVerifying(false) },
                        );
                    }}
                >
                    {consoleT('console.verify_eea2745e')}
                </Button>
                <Button onClick={onCancel}>{consoleT('console.cancel_19766ed6')}</Button>
            </div>
        </Panel>
    );
}

function Record({
    label,
    value,
    copyable = true,
    last = false,
}: {
    label: string;
    value: string;
    copyable?: boolean;
    last?: boolean;
}) {
    return (
        <div
            className="flex items-center justify-between gap-4 p-3"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <dt
                className="text-xs uppercase tracking-wide shrink-0"
                style={{ color: 'var(--faint)' }}
            >
                {label}
            </dt>
            <dd className="flex items-center gap-2 min-w-0">
                <span className="mono text-sm break-all text-right">{value}</span>
                {copyable && <CopyButton value={value} />}
            </dd>
        </div>
    );
}

/** No domain yet. */
function RequestDomain({ environment, href }: { environment: string; href: string }) {
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ environment, domain: '' });

    return (
        <Panel title={consoleT('console.add_a_domain_a86506fa')}>
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    // The environment travels with the write: it is the id every fence on
                    // the server checks, and it must be the one the page is showing.
                    form.transform((data) => ({ ...data, environment }));
                    form.post(href, { preserveScroll: true });
                }}
            >
                <Field
                    label={consoleT('console.custom_domain_d7d4c1e4')}
                    hint={consoleT(
                        'console.a_hostname_you_control_usually_a_subdomain_like_id_your_01b144f8',
                    )}
                    error={form.errors.domain}
                >
                    <Input
                        name="domain"
                        className="mono"
                        autoComplete="off"
                        placeholder="id.yourcompany.com"
                        value={form.data.domain}
                        onChange={(event) => form.setData('domain', event.target.value)}
                    />
                </Field>

                <Button type="submit" variant="primary" loading={form.processing}>
                    {consoleT('console.add_domain_d86476ae')}
                </Button>
            </form>
        </Panel>
    );
}

EnvironmentDomains.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
