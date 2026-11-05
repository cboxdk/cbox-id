import { type ReactNode, useEffect, useRef } from 'react';
import { Icon } from './Icon';

/** Keep optional settings nearby, and reveal their errors before moving focus. */
export function Disclosure({
    title,
    summary,
    hasError = false,
    defaultOpen = false,
    children,
}: {
    title: ReactNode;
    summary?: ReactNode;
    hasError?: boolean;
    defaultOpen?: boolean;
    children: ReactNode;
}) {
    const ref = useRef<HTMLDetailsElement>(null);

    useEffect(() => {
        if (hasError && ref.current) ref.current.open = true;
    }, [hasError]);

    return (
        <details
            ref={ref}
            className="cbx-disclosure"
            open={defaultOpen || undefined}
            onInvalidCapture={(event) => {
                if (ref.current) ref.current.open = true;
                const target = event.target;
                if (target instanceof HTMLElement) requestAnimationFrame(() => target.focus());
            }}
        >
            <summary>
                <span className="min-w-0 flex-1">
                    <span className="font-medium">{title}</span>
                    {summary && <span className="cbx-disclosure-summary">{summary}</span>}
                </span>
                <Icon name="chevron" className="w-4 h-4 shrink-0" />
            </summary>
            <div className="cbx-disclosure-body">{children}</div>
        </details>
    );
}
