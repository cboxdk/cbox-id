import { useForm } from '@inertiajs/react';
import { Button } from './Button';

/** `App\Http\Props\Auth\LinkConfirmationProps` */
export interface LinkConfirmationContent {
    heading: string;
    lead: string;
    actionLabel: string;
    actionUrl: string;
    facts: { label: string; value: string }[];
    note: string | null;
}

/**
 * ONE CLICK BETWEEN A MAILED LINK AND WHAT IT SPENDS.
 *
 * Mail scanners and chat unfurlers open every link they see, and every link this is used
 * for is single-use — so opening the link must never be what uses it. The page the link
 * opens draws this; the button is what uses it.
 *
 * DELIBERATELY NOT AUTO-SUBMITTED. A scanner that runs JavaScript would press a button a
 * script presses for it, which is the whole thing this exists to prevent.
 *
 * Each link has its OWN page that draws this — sign-in, address confirmation, invitation,
 * setup — because a component path belongs to one controller; the markup is shared here.
 */
export function LinkConfirmation({ confirmation }: { confirmation: LinkConfirmationContent }) {
    const form = useForm({});

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {confirmation.heading}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {confirmation.lead}
            </p>

            {confirmation.facts.length > 0 && (
                <dl
                    className="mt-6 rounded-lg p-4 text-sm space-y-2.5"
                    style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
                >
                    {confirmation.facts.map((fact) => (
                        <div key={fact.label} className="flex items-baseline justify-between gap-4">
                            <dt style={{ color: 'var(--muted-foreground)' }}>{fact.label}</dt>
                            <dd className="font-medium text-right min-w-0 break-words">
                                {fact.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            <form
                className="mt-7"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(confirmation.actionUrl);
                }}
            >
                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    {confirmation.actionLabel}
                </Button>
            </form>

            {confirmation.note !== null && (
                <p className="mt-5 text-xs" style={{ color: 'var(--faint)' }}>
                    {confirmation.note}
                </p>
            )}
        </>
    );
}
