import type { ReactNode } from 'react';
import { CopyButton } from './CopyButton';

export interface CodeBlockProps {
    code: string;
    /** A one-line install command shown above it — `npm i @cboxdk/id-js` — with its own copy. */
    install?: string | null;
    /** What the copy button says it copies, for a screen reader: "Copy curl". */
    copyLabel?: string;
    /** A line under the code: where it goes, what it needs. */
    caption?: ReactNode;
}

/**
 * Code a person is meant to paste, with the copy beside it.
 *
 * Preformatted and horizontally scrollable rather than wrapped: a wrapped shell command
 * reads as two commands, and a wrapped JSON key hides where the line really breaks. The
 * copy button sits OUTSIDE the scrolling box, so on a phone it stays where the thumb is
 * however long the line.
 */
export function CodeBlock({ code, install, copyLabel = 'Copy', caption }: CodeBlockProps) {
    return (
        <div>
            {install != null && install !== '' && (
                <div className="mb-2 flex items-center gap-2">
                    <p
                        className="mono text-xs rounded-lg px-3 py-2 flex-1 min-w-0 overflow-x-auto whitespace-nowrap select-all"
                        style={{
                            background: 'var(--surface-2)',
                            border: '1px solid var(--border)',
                            color: 'var(--muted-foreground)',
                        }}
                    >
                        {install}
                    </p>
                    <CopyButton value={install} label="Copy" aria-label="Copy install command" />
                </div>
            )}

            <div className="flex items-start gap-2">
                <section
                    // Scrollable sideways, so reachable by keyboard: a long line is read by scrolling it

                    // (WCAG 2.1.1; axe scrollable-region-focusable), which the lint rule does not know.

                    // oxlint-disable-next-line jsx-a11y/no-noninteractive-tabindex
                    tabIndex={0}
                    aria-label="Code"
                    className="rounded-lg p-3 overflow-x-auto text-xs mono flex-1 min-w-0"
                    style={{
                        background: 'var(--surface-2)',
                        border: '1px solid var(--border)',
                        lineHeight: 1.6,
                    }}
                >
                    <pre className="m-0"><code>{code}</code></pre>
                </section>
                <CopyButton value={code} aria-label={copyLabel} />
            </div>

            {caption !== undefined && (
                <p className="mt-2 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {caption}
                </p>
            )}
        </div>
    );
}
