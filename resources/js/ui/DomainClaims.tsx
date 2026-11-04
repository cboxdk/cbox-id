import { useUiTranslator } from '@/i18n/ui';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from './Badge';
import { Button } from './Button';
import { ConfirmDelete } from './ConfirmDelete';
import { CopyButton } from './CopyButton';
import { EmptyState } from './EmptyState';
import { Field } from './Field';
import { Input } from './Input';
import { Panel } from './Panel';
import { Pill } from './Pill';

export interface DomainClaim {
    id: string;
    domain: string;
    verified: boolean;
    capture: boolean;
    /**
     * Where the TXT record goes (`_cbox-id-challenge.acme.com`), exactly as the verifier
     * looks it up — sent by the server, never composed here.
     */
    recordName: string;
    /** The DNS TXT value to publish. */
    token: string;
    urls: { verify: string; capture: string; remove: string };
}

/**
 * The email domains one organization claims — on an organization's Domains tab in the
 * environment console, and on the organization console's own Domains page.
 *
 * CAPTURE IS THE CONSEQUENTIAL SWITCH: it routes everyone on the domain to this
 * organization's Enterprise SSO connection, so it stays off until the domain is proven —
 * otherwise an organization could claim addresses it does not own.
 */
export function DomainClaims({ domains, addHref }: { domains: DomainClaim[]; addHref: string }) {
    const { t: tc } = useUiTranslator();

    const form = useForm({ domain: '' });
    const [removing, setRemoving] = useState<DomainClaim | null>(null);

    return (
        <Panel
            title={tc('console.email_domains_f8b00920', 'Email domains')}
            description={tc(
                'console.prove_the_organization_owns_a_domain_then_ro_3c9f7db4',
                'Prove the organization owns a domain, then route everyone on it to their own sign-in.',
            )}
        >
            <div className="space-y-4">
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(addHref, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <Field
                        label={tc('console.domain_79fa3361', 'Domain')}
                        className="flex-1"
                        error={form.errors.domain}
                    >
                        <Input
                            name="domain"
                            className="mono"
                            placeholder="acme.com"
                            value={form.data.domain}
                            onChange={(event) => form.setData('domain', event.target.value)}
                        />
                    </Field>
                    <Button type="submit" className="shrink-0" loading={form.processing}>
                        {tc('console.add_domain_d86476ae', 'Add domain')}
                    </Button>
                </form>

                {domains.length === 0 && (
                    <EmptyState
                        icon="shield"
                        title={tc('console.no_domains_yet_96cd1f06', 'No domains yet')}
                        description={tc(
                            'console.add_the_domain_this_organization_s_people_ha_e68cad70',
                            "Add the domain this organization's people have email addresses on, publish the TXT record, and verify it.",
                        )}
                    />
                )}

                {domains.map((domain) => (
                    <div
                        key={domain.id}
                        className="rounded-xl border p-4 space-y-3"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-medium mono">{domain.domain}</span>
                            <Pill tone={domain.verified ? 'success' : 'warning'}>
                                {domain.verified
                                    ? tc('console.verified_4f783840', 'Verified')
                                    : tc('console.unverified_33c8e8de', 'Unverified')}
                            </Pill>
                            {domain.capture && (
                                <Badge>
                                    {tc(
                                        'console.capturing_sign_ins_21c382a5',
                                        'Capturing sign-ins',
                                    )}
                                </Badge>
                            )}
                        </div>

                        {!domain.verified && (
                            <div
                                className="rounded-lg p-3 space-y-2"
                                style={{
                                    background: 'var(--surface-2)',
                                    border: '1px solid var(--border)',
                                }}
                            >
                                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                    {tc('console.add_this_dns_41ae6113', 'Add this DNS ')}
                                    <b>TXT</b>
                                    {tc(
                                        'console.record_then_verify_ce55fca2',
                                        ' record, then verify.',
                                    )}
                                </p>
                                {/*
                                    Name and value each with its own copy button: somebody
                                    is about to paste them into a DNS panel in another tab,
                                    and selecting a value out of a sentence by hand is where
                                    a truncated record comes from.
                                */}
                                <RecordRow
                                    label={tc('console.name_dcd1d522', 'Name')}
                                    value={domain.recordName}
                                />
                                <RecordRow
                                    label={tc('console.value_8e37953d', 'Value')}
                                    value={domain.token}
                                />
                            </div>
                        )}

                        <div className="flex items-center gap-2 flex-wrap">
                            {!domain.verified && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            domain.urls.verify,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {tc('console.verify_eea2745e', 'Verify')}
                                </Button>
                            )}

                            <Button
                                size="sm"
                                disabled={!domain.verified && !domain.capture}
                                onClick={() =>
                                    router.post(domain.urls.capture, {}, { preserveScroll: true })
                                }
                            >
                                {domain.capture
                                    ? tc('console.stop_capturing_86f919c9', 'Stop capturing')
                                    : tc('console.capture_sign_ins_01f6f9e1', 'Capture sign-ins')}
                            </Button>

                            <Button size="sm" variant="danger" onClick={() => setRemoving(domain)}>
                                {tc('console.remove_c3812fc4', 'Remove')}
                            </Button>
                        </div>

                        {!domain.verified && !domain.capture && (
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {tc(
                                    'console.verify_the_domain_before_turning_capture_on__4ff9f310',
                                    'Verify the domain before turning capture on — until then, this organization has not proved it owns those addresses.',
                                )}
                            </p>
                        )}
                    </div>
                ))}
            </div>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.domain ?? ''}
                verb="Remove"
                consequence={tc(
                    'console.the_claim_is_dropped_if_capture_was_on_peopl_17d5c40e',
                    'The claim is dropped. If capture was on, people on this domain go back to the ordinary sign-in.',
                )}
                onConfirm={() => {
                    const domain = removing;
                    setRemoving(null);

                    if (domain !== null) {
                        router.delete(domain.urls.remove, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

function RecordRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-start gap-2">
            <span className="text-xs w-12 shrink-0" style={{ color: 'var(--muted-foreground)' }}>
                {label}
            </span>
            <code className="mono text-xs break-all select-all flex-1">{value}</code>
            <CopyButton value={value} />
        </div>
    );
}
