import { forwardRef, useId, useState } from 'react';
import { cn } from '@/lib/cn';
import { Icon } from './Icon';
import { Input, type InputProps } from './Input';

export interface PasswordFieldProps extends Omit<InputProps, 'type'> {
    label: string;
    error?: string | null;
    /**
     * Show the live length check under the field. On for a NEW password, off for one the
     * person already has — telling somebody their existing password is too short at the
     * moment they are typing it to prove who they are is noise, not help.
     */
    policy?: boolean;
    /** The floor the tenant's policy enforces. Stated so the person is not guessing. */
    minLength?: number;
}

/**
 * A PASSWORD FIELD THAT A PASSWORD MANAGER CAN SEE.
 *
 * The details here are the whole component. `autocomplete="new-password"` beside a
 * hidden `username` field is what lets 1Password and the browser's own manager offer to
 * generate one and then UPDATE the saved credential rather than storing a second entry —
 * and a reset page that saves a duplicate is a page that has locked somebody out slowly.
 *
 * `passwordrules` is the Safari/iOS hint, and it is the difference between a generated
 * password that satisfies this tenant's policy and one that is rejected after the person
 * has already accepted it.
 *
 * The reveal toggle is a BUTTON with a spoken state, not an icon that swaps: somebody
 * typing a generated passphrase on a phone needs to check it, and needs to be told
 * whether it is currently visible on their screen.
 */
export const PasswordField = forwardRef<HTMLInputElement, PasswordFieldProps>(
    function PasswordField(
        { label, error, policy = false, minLength = 12, className, value, onChange, ...props },
        ref,
    ) {
        const id = useId();
        const [visible, setVisible] = useState(false);

        const typed = typeof value === 'string' ? value : '';
        const longEnough = typed.length >= minLength;

        const policyId = `${id}-policy`;
        const errorId = `${id}-error`;

        return (
            <div className={cn(className)}>
                <label className="label" htmlFor={id}>
                    {label}
                </label>

                <div style={{ position: 'relative' }}>
                    <Input
                        ref={ref}
                        id={id}
                        scale="lg"
                        type={visible ? 'text' : 'password'}
                        minLength={props.autoComplete === 'new-password' ? minLength : undefined}
                        // Safari and iOS read this when they offer to generate one. Without
                        // it they can produce a password this tenant's policy then refuses,
                        // after the person has already saved it.
                        {...(props.autoComplete === 'new-password'
                            ? { passwordrules: `minlength: ${minLength}; allowed: ascii-printable;` }
                            : {})}
                        aria-describedby={
                            [policy ? policyId : null, error ? errorId : null]
                                .filter(Boolean)
                                .join(' ') || undefined
                        }
                        aria-invalid={error ? true : undefined}
                        value={value}
                        onChange={onChange}
                        style={{ paddingRight: '2.75rem' }}
                        {...props}
                    />

                    <button
                        type="button"
                        onClick={() => setVisible((shown) => !shown)}
                        className="cbx-iconbtn"
                        style={{ position: 'absolute', right: '4px', top: '5px' }}
                        aria-pressed={visible}
                        aria-label={visible ? 'Hide password' : 'Show password'}
                        title={visible ? 'Hide password' : 'Show password'}
                    >
                        <Icon name={visible ? 'eyeOff' : 'eye'} className="w-4 h-4" />
                    </button>
                </div>

                {policy && (
                    <div
                        id={policyId}
                        className="mt-2 flex items-center gap-1.5 text-xs"
                        style={{ color: longEnough ? 'var(--success-strong)' : 'var(--faint)' }}
                    >
                        <Icon name="check" className="w-3.5 h-3.5" />
                        <span>At least {minLength} characters</span>
                    </div>
                )}

                <p id={errorId} className="field-error" role="alert" hidden={!error}>
                    {error}
                </p>
            </div>
        );
    },
);

/**
 * The hidden field a password manager needs to know WHICH credential is being changed.
 *
 * Without it, a reset or change form is an anonymous new-password box: the manager saves
 * a second entry rather than updating the one the person already has, and the next sign-in
 * offers them two.
 */
export function PasswordManagerIdentity({ username }: { username?: string }) {
    return (
        <input
            type="text"
            name="username"
            autoComplete="username"
            defaultValue={username}
            readOnly
            className="hidden"
            tabIndex={-1}
            aria-hidden="true"
        />
    );
}
