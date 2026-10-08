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
    /** The DNS TXT value to publish — the one thing somebody copies into another tab. */
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
    const form = useForm({ domain: '' });
    const [removing, setRemoving] = useState<DomainClaim | null>(null);

    return (
        <Panel
            title="Email domains"
            description="Prove the organization owns a domain, then route everyone on it to their own sign-in."
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
                    <Field label="Domain" className="flex-1" error={form.errors.domain}>
                        <Input
                            name="domain"
                            className="mono"
                            placeholder="acme.com"
                            value={form.data.domain}
                            onChange={(event) => form.setData('domain', event.target.value)}
                        />
                    </Field>
                    <Button type="submit" className="shrink-0" loading={form.processing}>
                        Add domain
                    </Button>
                </form>

                {domains.length === 0 && (
                    <EmptyState
                        icon="shield"
                        title="No domains yet"
                        description="Add the domain this organization's people have email addresses on, publish the TXT record, and verify it."
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
                                {domain.verified ? 'Verified' : 'Unverified'}
                            </Pill>
                            {domain.capture && <Badge>Capturing sign-ins</Badge>}
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
                                    Add this DNS <b>TXT</b> record at{' '}
                                    <span className="mono">{domain.domain}</span>, then verify.
                                </p>
                                {/*
                                    Its own copy button: somebody is about to paste this
                                    into a DNS panel in another tab, and selecting a value
                                    out of a sentence by hand is where a truncated record
                                    comes from.
                                */}
                                <div className="flex items-start gap-2">
                                    <code className="mono text-xs break-all select-all flex-1">
                                        {domain.token}
                                    </code>
                                    <CopyButton value={domain.token} />
                                </div>
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
                                    Verify
                                </Button>
                            )}

                            <Button
                                size="sm"
                                disabled={!domain.verified && !domain.capture}
                                onClick={() =>
                                    router.post(domain.urls.capture, {}, { preserveScroll: true })
                                }
                            >
                                {domain.capture ? 'Stop capturing' : 'Capture sign-ins'}
                            </Button>

                            <Button size="sm" variant="danger" onClick={() => setRemoving(domain)}>
                                Remove
                            </Button>
                        </div>

                        {!domain.verified && !domain.capture && (
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                Verify the domain before turning capture on — until then, this
                                organization has not proved it owns those addresses.
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
                consequence="The claim is dropped. If capture was on, people on this domain go back to the ordinary sign-in."
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
