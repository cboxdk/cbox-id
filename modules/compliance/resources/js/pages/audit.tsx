import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

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
                description={tc('console.the_same_append_only_hash_chained_trail_as_t_36aa80ca')}
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
                <Field label={tc('console.action_64cff131')}>
                    <Input
                        placeholder={tc('console.e_g_auth_login_3aa2dd9a')}
                        value={action}
                        onChange={(event) => setAction(event.target.value)}
                    />
                </Field>
                <Field label={tc('console.actor_449995c4')}>
                    <Input
                        placeholder={tc('console.actor_id_2007dae3')}
                        value={actor}
                        onChange={(event) => setActor(event.target.value)}
                    />
                </Field>
            </div>

            {entries.length === 0 ? (
                <EmptyState
                    icon="audit"
                    title={tc('console.no_audit_entries_match_4d50185d')}
                    description={tc(
                        'console.adjust_the_filters_above_or_clear_them_to_se_3a188dad',
                    )}
                />
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table
                            caption={tc(
                                'console.the_audit_trail_for_this_chain_newest_first_52c34d8a',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th className="text-right">{tc('console.seq_43ac2602')}</Th>
                                    <Th>{tc('console.when_cf9c7aa2')}</Th>
                                    <Th>{tc('console.action_64cff131')}</Th>
                                    <Th>{tc('console.actor_449995c4')}</Th>
                                    <Th>{tc('console.target_978354db')}</Th>
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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

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
                {verification.valid
                    ? tc('console.chain_verified_705c1a7c')
                    : tc('console.chain_broken_cfca4423')}
            </span>

            <span className="text-xs" style={{ opacity: 0.8 }}>
                {!verification.valid ? (
                    <>
                        {tc('console.broke_at_sequence_e0d268aa')}
                        {verification.brokenAt} — {verification.reason}.
                    </>
                ) : verification.whole ? (
                    <>
                        {tc('console.all_6b2fd69c')}
                        {verification.count.toLocaleString(locale)}
                        {tc('console.entries_checked_hashes_and_linkage_intact_01c0f5a3')}
                    </>
                ) : (
                    /*
                        SAYS WHICH ENTRIES, because "chain verified" over a window is only an
                        honest badge if it names the window. The tail is what a page can
                        re-check on every render; the whole chain is a deliberate ask.
                    */
                    <>
                        {tc('console.the_most_recent_f414a29a')}
                        {verification.count.toLocaleString(locale)}
                        {tc('console.entries_checked_hashes_and_linkage_intact_an_9216f061')}
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
                    ? tc('console.verifying_63bbd08c')
                    : verification.whole
                      ? tc('console.check_the_whole_chain_again_41ea9972')
                      : tc('console.verify_the_whole_chain_89e70b76')}
            </Button>
        </output>
    );
}

AuditTrail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
