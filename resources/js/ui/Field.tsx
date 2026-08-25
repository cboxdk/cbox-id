import { createContext, type ReactNode, useContext, useId, useMemo } from 'react';
import { cn } from '@/lib/cn';

interface FieldContextValue {
    controlId: string;
    describedBy: string | undefined;
    invalid: boolean;
    required: boolean;
}

const FieldContext = createContext<FieldContextValue | null>(null);

/**
 * The wiring a control needs from its `<Field>` — id, `aria-describedby`, `aria-invalid`.
 *
 * A control calls this and spreads the result. Outside a Field it returns nothing, which
 * is what lets `<Input>` be used bare (a search box in a toolbar) without a label it has
 * no room for.
 */
export function useFieldControl(): Partial<{
    id: string;
    'aria-describedby': string;
    'aria-invalid': true;
    required: boolean;
}> {
    const field = useContext(FieldContext);

    if (field === null) {
        return {};
    }

    return {
        id: field.controlId,
        ...(field.describedBy !== undefined ? { 'aria-describedby': field.describedBy } : {}),
        ...(field.invalid ? { 'aria-invalid': true as const } : {}),
        required: field.required,
    };
}

export interface FieldProps {
    label: ReactNode;
    /**
     * The control's id.
     *
     * Generated when omitted, which is the common case and the reason this component
     * exists. State one only where something OUTSIDE the field refers to it — a link that
     * focuses it, or a deep link into a form.
     */
    id?: string;
    /**
     * A control on the label's row, right-aligned — "Forgot password?", "Use a different
     * email".
     *
     * Here rather than at the call site because the alternative is what it replaces: a
     * visible heading beside the link and a second, screen-reader-only `<label>` for the
     * input, so the field had two names and only one of them was on screen.
     */
    labelAction?: ReactNode;
    /** Guidance shown under the control, always — not a placeholder, not a tooltip. */
    hint?: ReactNode;
    /**
     * The server's message for this field. Present, the control is marked invalid and the
     * message is announced, because a validation error that only appears visually is
     * invisible to the person most likely to have made one.
     */
    error?: string | null;
    required?: boolean;
    className?: string;
    children: ReactNode;
}

/**
 * A labelled form control, wired.
 *
 * WHY THIS IS A COMPONENT AND NOT A CONVENTION. The Volt console wrote label, hint and
 * error markup by hand at every call site — several hundred of them — and the wiring
 * between them was a matter of remembering: `for`/`id`, `aria-describedby` for the hint,
 * `aria-invalid` and a live region for the error. Wherever it was forgotten, the control
 * was unlabelled and the error silent, and nothing said so.
 *
 * Here the ids are generated and the relationships are not optional. A field cannot be
 * built without a label, and an error cannot be shown without being announced.
 */
export function Field({
    label,
    id: statedId,
    labelAction,
    hint,
    error,
    required = false,
    className,
    children,
}: FieldProps) {
    const generated = useId();
    const id = statedId ?? generated;
    const controlId = statedId ?? `${id}-control`;
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    const describedBy =
        [hint !== undefined ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') ||
        undefined;

    // Memoized because every control under this provider re-renders when the identity
    // of the value changes — and on a form with twenty fields, a keystroke in one of them
    // re-rendering all of them is measurable.
    const context = useMemo(
        () => ({ controlId, describedBy, invalid: Boolean(error), required }),
        [controlId, describedBy, error, required],
    );

    return (
        <FieldContext.Provider value={context}>
            <div className={cn(className)}>
                <div
                    className={cn(labelAction !== undefined && 'flex items-center justify-between gap-3')}
                >
                    <label
                        className="label"
                        htmlFor={controlId}
                        style={labelAction === undefined ? undefined : { marginBottom: '6px' }}
                    >
                        {label}
                        {required && (
                            <>
                                {' '}
                                <span aria-hidden="true" style={{ color: 'var(--destructive)' }}>
                                    *
                                </span>
                                <span className="sr-only">(required)</span>
                            </>
                        )}
                    </label>
                    {labelAction}
                </div>

                {children}

                {hint !== undefined && (
                    <p
                        id={hintId}
                        style={{
                            fontSize: '12px',
                            color: 'var(--muted-foreground)',
                            marginTop: '5px',
                        }}
                    >
                        {hint}
                    </p>
                )}

                {/*
                    The region exists whether or not there is an error to put in it. A
                    live region that is INSERTED carrying its text is registered by the
                    accessibility tree as a new subtree rather than an update, and NVDA
                    and VoiceOver stay silent — which is the exact bug the Volt toast
                    component was written twice to fix.
                */}
                <p id={errorId} className="field-error" role="alert" hidden={!error}>
                    {error}
                </p>
            </div>
        </FieldContext.Provider>
    );
}
