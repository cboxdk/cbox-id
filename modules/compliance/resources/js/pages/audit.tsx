import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, EmptyState, Field, Input, PageHeader, Table, Td, TdMono, Th } from '@/ui';

interface Entry {
    id: string;
    sequence: number;
    when: string | null;
    action: string;
    actor: string;
    target: string | null;
}

interface Verification {
    valid: boolean;
    count: number;
    brokenAt: number | null;
    reason: string | null;
    /** Whether the whole chain was checked, or only the tail. */
    whole: boolean;
}

type Props = PageProps<{
    entries: Entry[];
    filters: { action: string; actor: string };
    verification: Verification;
    help: HelpContent;
}>;

function listHref(action: string, actor: string, verifyAll = false): string {
    const query = new URLSearchParams();

    if (action !== '') {
        query.set('action', action);
    }

    if (actor !== '') {
        query.set('actor', actor);
    }

    if (verifyAll) {
        query.set('verifyAll', '1');
    }

    const rest = query.toString();

    return rest === '' ? window.location.pathname : `${window.location.pathname}?${rest}`;
}

export default function AuditTrail({ entries, filters, verification, help }: Props) {
    const [action, setAction] = useState(filters.action);
    const [actor, setActor] = useState(filters.actor);
    const [verifying, setVerifying] = useState(false);

    useEffect(() => {
        if (action === filters.action && actor === filters.actor) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(action, actor),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
            // 400ms rather than the console's usual 300: each keystroke re-reads the trail
            // and re-verifies its tail, which is the most expensive filter in the product.
        }, 400);

        return () => clearTimeout(timer);
    }, [action, actor, filters.action, filters.actor]);

    return (
        <div className="space-y-6">
            {/*
                The description no longer promises searching "across organizations": it never
                did that safely, and the field that appeared to offer it was the leak.
            */}
            <PageHeader
                help={help}
                description="The same append-only, hash-chained trail as the activity log, with the chain verified end to end."
            />

            <Chain
                verification={verification}
                verifying={verifying}
                onVerifyAll={() => {
                    setVerifying(true);
                    router.get(
                        listHref(action, actor, true),
                        {},
                        {
                            preserveState: true,
                            preserveScroll: true,
                            onFinish: () => setVerifying(false),
                        },
                    );
                }}
            />

            {/*
                No organization filter: this page shows YOUR organization's trail and no
                other. It used to accept one, which made the id in a text box the only thing
                standing between an org admin and every other tenant's records.
            */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label="Action">
                    <Input
                        placeholder="e.g. auth.login"
                        value={action}
                        onChange={(event) => setAction(event.target.value)}
                    />
                </Field>
                <Field label="Actor">
                    <Input
                        placeholder="actor id"
                        value={actor}
                        onChange={(event) => setActor(event.target.value)}
                    />
                </Field>
            </div>

            {entries.length === 0 ? (
                <EmptyState
                    icon="audit"
                    title="No audit entries match"
                    description="Adjust the filters above, or clear them to see the full trail."
                />
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table caption="The audit trail for this chain, newest first">
                            <thead>
                                <tr>
                                    <Th className="text-right">Seq</Th>
                                    <Th>When</Th>
                                    <Th>Action</Th>
                                    <Th>Actor</Th>
                                    <Th>Target</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <TdMono className="text-right text-xs">
                                            {entry.sequence}
                                        </TdMono>
                                        <TdMono className="whitespace-nowrap text-xs">
                                            {entry.when ?? '—'}
                                        </TdMono>
                                        <Td className="font-medium whitespace-nowrap">
                                            {entry.action}
                                        </Td>
                                        <Td style={{ color: 'var(--muted-foreground)' }}>
                                            {entry.actor}
                                        </Td>
                                        <Td style={{ color: 'var(--muted-foreground)' }}>
                                            {entry.target ?? '—'}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * The chain-verification badge.
 *
 * `-strong`, NOT the base tokens: the base `--success` and `--destructive` measure under
 * 4.5:1 on their own soft wash, and this text is 12px. "Chain broken" — the most important
 * message on the page — was the worse of the two at 3.94:1.
 *
 * An `<output>`, so the result is announced when it changes after "Verify the whole chain";
 * the button is otherwise the only control here, and activating it used to produce no
 * announcement at all. And the button is ALWAYS rendered, so activating it does not delete
 * the element that has focus — it used to disappear on success, dumping focus to `<body>`.
 */
function Chain({
    verification,
    verifying,
    onVerifyAll,
}: {
    verification: Verification;
    verifying: boolean;
    onVerifyAll: () => void;
}) {
    const tone = verification.valid
        ? {
              borderColor: 'color-mix(in oklch, var(--success) 20%, transparent)',
              background: 'var(--success-soft)',
              color: 'var(--success-strong)',
          }
        : {
              borderColor: 'color-mix(in oklch, var(--destructive) 20%, transparent)',
              background: 'var(--destructive-soft)',
              color: 'var(--destructive-strong)',
          };

    return (
        // An <output>, which carries `role=status` implicitly — the result has to be
        // announced when it changes after "Verify the whole chain", because that button is
        // otherwise the only control here and activating it used to say nothing at all.
        <output
            className="flex flex-wrap items-center gap-3 rounded-xl border p-4 text-sm"
            style={{ ...tone, display: 'flex' }}
        >
            <span className="font-medium">
                {verification.valid ? 'Chain verified' : 'Chain broken'}
            </span>

            <span className="text-xs" style={{ opacity: 0.8 }}>
                {!verification.valid ? (
                    <>
                        Broke at sequence {verification.brokenAt} — {verification.reason}.
                    </>
                ) : verification.whole ? (
                    <>
                        All {verification.count.toLocaleString()} entries checked; hashes and
                        linkage intact.
                    </>
                ) : (
                    /*
                        SAYS WHICH ENTRIES, because "chain verified" over a window is only an
                        honest badge if it names the window. The tail is what a page can
                        re-check on every render; the whole chain is a deliberate ask.
                    */
                    <>
                        The most recent {verification.count.toLocaleString()} entries checked;
                        hashes and linkage intact, and the last signed checkpoint still anchors the
                        entry it was taken over.
                    </>
                )}
            </span>

            <Button
                size="sm"
                className="ms-auto shrink-0"
                loading={verifying}
                onClick={onVerifyAll}
            >
                {verifying
                    ? 'Verifying…'
                    : verification.whole
                      ? 'Check the whole chain again'
                      : 'Verify the whole chain'}
            </Button>
        </output>
    );
}

AuditTrail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
