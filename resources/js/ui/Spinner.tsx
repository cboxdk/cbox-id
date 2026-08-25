import { cn } from '@/lib/cn';

export interface SpinnerProps {
    className?: string;
    /**
     * What is being waited for. Given, the spinner becomes a live status; omitted — the
     * default — it is silent, which is correct whenever it sits inside a control that has
     * already announced itself busy.
     */
    label?: string;
}

/**
 * The one indeterminate progress indicator.
 *
 * `.spinner` slows to 1.6s under `prefers-reduced-motion` rather than stopping — see the
 * rule in app.css. A frozen ring is not "less motion", it is a broken control.
 */
export function Spinner({ className, label }: SpinnerProps) {
    return (
        <span
            className={cn('spinner', className)}
            role={label === undefined ? undefined : 'status'}
            aria-hidden={label === undefined ? true : undefined}
            aria-label={label}
        />
    );
}
