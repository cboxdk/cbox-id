import { useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import { Button, type ButtonProps } from './Button';

type CopyState = 'idle' | 'ok' | 'failed';

export interface CopyButtonProps extends Omit<ButtonProps, 'onClick' | 'children'> {
    value: string;
    label?: string;
    copiedLabel?: string;
    failedLabel?: string;
}

/**
 * Copy to clipboard, honestly.
 *
 * `writeText()` RETURNS A PROMISE, and the version this replaces set its "Copied ✓" state
 * synchronously and unconditionally — so on an insecure origin, a denied permission or
 * any rejected write, the button said it had copied over an empty clipboard. On a
 * one-time-shown signing secret that is data loss dressed as success, so the state waits
 * for the promise and says so when it fails.
 *
 * The result is also ANNOUNCED. Swapping the button's own label is not something
 * assistive technology reliably re-reads, so a live region carries the outcome.
 */
export function CopyButton({
    value,
    label = 'Copy',
    copiedLabel = 'Copied',
    failedLabel = 'Copy failed — select and copy manually',
    size = 'sm',
    className,
    ...props
}: CopyButtonProps) {
    const [state, setState] = useState<CopyState>('idle');
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    const copy = useCallback(() => {
        clearTimeout(timer.current);

        const done = (next: CopyState): void => {
            setState(next);
            timer.current = setTimeout(() => setState('idle'), 2500);
        };

        // `?.` because the API is absent entirely outside a secure context, which is
        // exactly where a self-hosted install over plain http lands.
        const write = navigator.clipboard?.writeText(value);

        if (write === undefined) {
            done('failed');

            return;
        }

        write.then(() => done('ok')).catch(() => done('failed'));
    }, [value]);

    const message = state === 'ok' ? copiedLabel : state === 'failed' ? failedLabel : label;

    return (
        <span className={cn('inline-flex items-center gap-2', className)}>
            <Button
                size={size}
                icon={state === 'ok' ? 'check' : 'copy'}
                onClick={copy}
                {...props}
            >
                {message}
            </Button>

            {/*
                `<output>` rather than a span with role="status": it is the element the
                platform already maps to that role, and it is what the outcome of an
                action on this page actually is.

                The region is MOUNTED WHILE EMPTY. One inserted carrying its text is
                registered by the accessibility tree as a new subtree rather than an
                update, and NVDA and VoiceOver stay silent.
            */}
            <output className="sr-only">{state === 'idle' ? '' : message}</output>
        </span>
    );
}
