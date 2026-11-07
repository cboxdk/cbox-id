import { useState } from 'react';
import { router } from '@inertiajs/react';
import { billingMoney } from '@/lib/billing';
import { Button, Dialog, Panel } from '@/ui';
export interface PaymentProject {
    id: string;
    name: string;
    previewHref: string;
    confirmHref: string;
    subscription?: {
        plan: string;
        status: string;
        data: { cancel_at_period_end?: boolean; period_end?: string };
    } | null;
}
type Plan = {
    features: Record<string, number | boolean | string | null>;
    key: string;
    name: string;
    priceMinor: number;
    totalMinor: number | null;
    currency: string;
    interval: string;
};
type Preview = {
    due_now_minor?: number;
    credit_minor?: number;
    credit_delta?: { forfeited?: number; granted?: number; carried?: number };
    new_recurring_minor?: number;
    new_recurring_tax?: { total_minor?: number };
    currency?: string;
    interval?: string;
    effective_at?: string;
    ends_at?: string;
    consequences?: { subscription?: string; message: string }[];
};
type Quote = {
    quote: string;
    preview: Preview;
    operation: string;
    project: PaymentProject;
    currency: string;
    interval?: string;
    returnToPlans: boolean;
};

export interface ProjectPaymentsData {
    projects: PaymentProject[];
    plans: Plan[];
    labels: Record<string, string>;
}
export default function ProjectPayments({
    data,
    canManage,
    organization,
    locale,
}: {
    data: ProjectPaymentsData;
    canManage: boolean;
    organization: string;
    locale: string;
}) {
    const { projects, plans, labels: l } = data;
    const [project, setProject] = useState<PaymentProject | null>(null);
    const [quote, setQuote] = useState<Quote | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const money = (amount: number, currency: string) => billingMoney(amount, currency, locale);
    async function post<T>(url: string, body: object): Promise<T> {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ??
                    '',
            },
            body: JSON.stringify({ ...body, organization }),
        });
        const result = await response.json().catch(() => {
            throw new Error(l.unavailable);
        });
        if (!response.ok)
            throw new Error(
                result.errors
                    ? Object.values(result.errors).flat().join(' ')
                    : (result.message ?? l.unavailable),
            );
        return result as T;
    }
    async function run(action: () => Promise<void>) {
        if (busy) return;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await action();
        } catch (e) {
            setError(e instanceof Error ? e.message : l.unavailable);
        } finally {
            setBusy(false);
        }
    }
    async function review(p: PaymentProject, operation: string, plan?: Plan) {
        await run(async () => {
            const result = await post<Omit<Quote, 'project' | 'currency'>>(p.previewHref, {
                operation,
                plan: plan?.key,
            });
            const currency =
                result.preview.currency ??
                plan?.currency ??
                plans.find((v) => v.key === p.subscription?.plan)?.currency;
            if (!currency) throw new Error(l.unavailable);
            setQuote({
                ...result,
                returnToPlans: plan !== undefined,
                project: p,
                currency,
                interval: result.preview.interval ?? plan?.interval,
            });
            setProject(null);
        });
    }
    return (
        <>
            {notice && <output>{notice}</output>}
            {error && !project && !quote && <p role="alert">{error}</p>}
            <Panel title={l.plans}>
                {projects.map((p) => (
                    <div
                        key={p.id}
                        className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] py-3"
                    >
                        <div>
                            <p className="font-medium break-all">{p.name}</p>
                            <p className="text-sm">
                                {plans.find((v) => v.key === p.subscription?.plan)?.name ??
                                    p.subscription?.plan ??
                                    l.no_subscription}{' '}
                                {p.subscription?.status}
                            </p>
                            {p.subscription?.data.cancel_at_period_end && (
                                <p>
                                    {l.ends}:{' '}
                                    {p.subscription.data.period_end
                                        ? new Date(
                                              p.subscription.data.period_end,
                                          ).toLocaleDateString(locale)
                                        : '—'}
                                </p>
                            )}
                        </div>
                        {canManage && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() => setProject(p)}
                                >
                                    {l.plans}
                                </Button>
                                {p.subscription &&
                                    ['active', 'trialing'].includes(p.subscription.status) && (
                                        <Button
                                            variant="ghost"
                                            disabled={busy}
                                            onClick={() =>
                                                void review(
                                                    p,
                                                    p.subscription?.data.cancel_at_period_end
                                                        ? 'reactivate'
                                                        : 'cancel',
                                                )
                                            }
                                        >
                                            {p.subscription.data.cancel_at_period_end
                                                ? l.reactivate
                                                : l.cancel}
                                        </Button>
                                    )}
                            </div>
                        )}
                    </div>
                ))}
            </Panel>
            <Dialog
                open={project !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setProject(null);
                }}
                title={l.plans}
                description={project?.name}
            >
                <div className="space-y-4">
                    {error && <p role="alert">{error}</p>}
                    {plans.length === 0 && <p>{l.not_configured}</p>}
                    {plans.map((plan) => (
                        <div key={plan.key} className="rounded border border-[var(--border)] p-4">
                            <p className="font-medium">{plan.name}</p>
                            {typeof plan.features['cbox-id.environments'] === 'number' && (
                                <p>
                                    {plan.features['cbox-id.environments']} {l.environments}
                                </p>
                            )}
                            <p>
                                {money(plan.totalMinor ?? plan.priceMinor, plan.currency)} /{' '}
                                {plan.interval}
                            </p>
                            {plan.totalMinor === null && <p>{l.tax_pending}</p>}
                            <Button
                                disabled={busy}
                                onClick={() => project && void review(project, 'plan', plan)}
                            >
                                {l.select}
                            </Button>
                        </div>
                    ))}
                </div>
            </Dialog>
            <Dialog
                open={quote !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setQuote(null);
                }}
                title={l.review}
                description={l.review_description}
                footer={
                    <>
                        <Button
                            variant="secondary"
                            disabled={busy}
                            onClick={() => {
                                setError('');
                                if (quote?.returnToPlans) setProject(quote.project);
                                setQuote(null);
                            }}
                        >
                            {l.back}
                        </Button>
                        <Button
                            loading={busy}
                            onClick={() =>
                                quote &&
                                void run(async () => {
                                    const result = await post<{ url?: string }>(
                                        quote.project.confirmHref,
                                        { quote: quote.quote },
                                    );
                                    if (result.url) {
                                        window.location.assign(result.url);
                                        return;
                                    }
                                    setQuote(null);
                                    setNotice(l.saved);
                                    router.reload({ only: ['projectPayments'] });
                                })
                            }
                        >
                            {quote?.operation === 'checkout' ? l.checkout : l.confirm}
                        </Button>
                    </>
                }
            >
                {quote && (
                    <div className="space-y-3">
                        <p className="font-medium">{quote.project.name}</p>
                        {quote.preview.due_now_minor !== undefined && (
                            <p>
                                {l.due}: {money(quote.preview.due_now_minor, quote.currency)}
                            </p>
                        )}
                        {quote.preview.new_recurring_minor !== undefined && (
                            <p>
                                {l.recurring}:{' '}
                                {money(
                                    quote.preview.new_recurring_tax?.total_minor ??
                                        quote.preview.new_recurring_minor,
                                    quote.currency,
                                )}{' '}
                                {quote.interval && `/ ${quote.interval}`}{' '}
                                {quote.operation === 'change' &&
                                    quote.preview.new_recurring_tax?.total_minor === undefined &&
                                    l.tax_pending}
                            </p>
                        )}
                        {(quote.preview.credit_minor ?? 0) > 0 && (
                            <p>
                                {l.credit}: {money(quote.preview.credit_minor!, quote.currency)}
                            </p>
                        )}
                        {(['forfeited', 'granted', 'carried'] as const).map((kind) => {
                            const amount = quote.preview.credit_delta?.[kind] ?? 0;
                            return amount > 0 ? (
                                <p key={kind}>
                                    {l[`credits_${kind}`]}:{' '}
                                    {new Intl.NumberFormat(locale).format(amount)}
                                </p>
                            ) : null;
                        })}
                        {(quote.preview.effective_at || quote.preview.ends_at) && (
                            <p>
                                {l.effective}:{' '}
                                {new Date(
                                    (quote.preview.effective_at ?? quote.preview.ends_at)!,
                                ).toLocaleString(locale)}
                            </p>
                        )}
                        {quote.preview.consequences?.map((c) => (
                            <p key={c.subscription ?? c.message}>{c.message}</p>
                        ))}
                        {error && <p role="alert">{error}</p>}
                    </div>
                )}
            </Dialog>
        </>
    );
}
