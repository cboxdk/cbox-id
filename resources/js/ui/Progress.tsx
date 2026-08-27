export interface ProgressProps {
    /** 0–100. Clamped, because a percentage above 100 draws a bar wider than its track. */
    percent: number;
    label: string;
    className?: string;
}

/**
 * How far through something is.
 *
 * NOT `<progress>`, and the lint rule below is silenced deliberately rather than worked
 * around. The native element's fill is drawn by a different pseudo-element in every engine
 * — `::-webkit-progress-value`, `::-moz-progress-bar` — none of which is part of the
 * cascade this design system's tokens live in, so a themed bar cannot be built from it
 * without one rule per browser that white-labelling would then have to override three
 * times. A div with `role="progressbar"` carries the identical accessible semantics: the
 * role, the bounds and the current value are all announced.
 */
export function Progress({ percent, label, className }: ProgressProps) {
    const value = Math.max(0, Math.min(100, Math.round(percent)));

    return (
        <div
            className={className === undefined ? 'cbx-progress' : `cbx-progress ${className}`}
            // eslint-disable-next-line jsx-a11y/prefer-tag-over-role -- see the note above
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={value}
            aria-label={label}
        >
            <span style={{ width: `${value}%` }} />
        </div>
    );
}
