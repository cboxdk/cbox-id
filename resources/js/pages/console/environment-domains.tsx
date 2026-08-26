import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
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
}>;

export default function EnvironmentDomains({
    environments,
    selected,
    verifiedDomain,
    challenge,
    urls,
}: Props) {
    /*
     * "DNS has not propagated" is not an error about the DOMAIN — the value is fine and
     * the record is probably right — so the server reports it under a key no input owns,
     * and the page reads it from the shared bag rather than from a field's own errors.
     */
    const verifyError = usePage().props.errors.verify;

    const [removing, setRemoving] = useState(false);

    return (
        <div style={{ maxWidth: '42rem' }}>
            <PageHeader description="Serve an environment's identity endpoints on your own domain, verified by DNS." />

            {environments.length === 0 ? (
                <div className="card mt-6">
                    <EmptyState
                        icon="layers"
                        title="No environments yet"
                        description="Create an environment first, then you can give it a custom domain."
                    />
                </div>
            ) : (
                <div className="mt-6 space-y-6">
                    <Field label="Environment">
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
                name={verifiedDomain ?? challenge?.domain ?? 'this domain'}
                verb={verifiedDomain !== null ? 'Stop serving' : 'Cancel verification'}
                consequence={
                    verifiedDomain !== null
                        ? 'This environment falls back to its default domain. Every client pinned to the current issuer, discovery URL or JWKS URL breaks until it is repointed.'
                        : 'The DNS TXT challenge stops being valid — re-adding the domain issues a new one.'
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
                    <Icon name="shield" className="w-4 h-4" style={{ color: 'var(--success-strong)' }} />
                    <span className="font-medium">{domain}</span>
                    <Badge>Verified</Badge>
                </div>
                <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    This environment's issuer, discovery and JWKS are served on{' '}
                    <span className="mono">https://{domain}</span>. Point the host at your ingress
                    and terminate TLS there.
                </p>
            </div>

            <Button variant="danger" onClick={onRemove} data-environment={environment}>
                Remove domain
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
    const [verifying, setVerifying] = useState(false);

    return (
        <Panel
            title="Prove you control the domain"
            description={
                <>
                    Add this DNS <b>TXT</b> record at your domain, then verify. It proves you
                    control <span className="mono">{challenge.domain}</span>.
                </>
            }
        >
            <dl className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                <Record label="Type" value="TXT" copyable={false} />
                <Record label="Name" value={challenge.recordName} />
                <Record label="Value" value={challenge.recordValue} last />
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
                    Verify
                </Button>
                <Button onClick={onCancel}>Cancel</Button>
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
            <dt className="text-xs uppercase tracking-wide shrink-0" style={{ color: 'var(--faint)' }}>
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
    const form = useForm({ environment, domain: '' });

    return (
        <Panel title="Add a domain">
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
                    label="Custom domain"
                    hint="A hostname you control — usually a subdomain, like id.yourcompany.com."
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
                    Add domain
                </Button>
            </form>
        </Panel>
    );
}

EnvironmentDomains.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
