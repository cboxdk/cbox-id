import { Slot } from 'radix-ui';
import { type ButtonHTMLAttributes, forwardRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Icon, type IconProps } from './Icon';
import { Spinner } from './Spinner';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

const VARIANT: Record<ButtonVariant, string> = {
    primary: 'btn-primary',
    secondary: 'btn-secondary',
    ghost: 'btn-ghost',
    danger: 'btn-danger',
};

const SIZE: Record<ButtonSize, string> = {
    sm: 'btn-sm',
    md: '',
    lg: 'btn-lg',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;

    /** A leading glyph. Decorative: the label beside it is the accessible name. */
    icon?: IconProps['name'];

    /**
     * The action is in flight. The button disables itself, swaps the leading glyph for a
     * spinner and announces `aria-busy` — so a second click cannot double-submit and
     * somebody using a screen reader is told the press landed.
     *
     * The LABEL DOES NOT CHANGE. Swapping "Save" for "Saving…" reflows the button under
     * the cursor at the exact moment the person is still pointing at it, and a
     * fixed-width label makes the spinner the only thing that moves.
     */
    loading?: boolean;

    /**
     * Render the caller's own element with this button's styling — an Inertia `<Link>`,
     * an `<a>`, a Radix trigger. A control that navigates must be an anchor, and a
     * `<button onClick={() => router.visit(…)}>` is not one: it cannot be middle-clicked,
     * opened in a new tab, or copied, and it says the wrong thing to a screen reader.
     */
    asChild?: boolean;

    children?: ReactNode;
}

/**
 * The console's button. Four variants, three sizes, and no `className` gymnastics: the
 * shapes live in `app.css` as `.btn*`, and this decides which of them applies.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    {
        variant = 'ghost',
        size = 'md',
        icon,
        loading = false,
        asChild = false,
        disabled,
        className,
        children,
        type,
        ...props
    },
    ref,
) {
    const Comp = asChild ? Slot.Root : 'button';

    return (
        <Comp
            ref={ref}
            // An unspecified `type` inside a form is `submit`, which is how a "Cancel"
            // button ends up submitting the form it sits in. Only a real <button> takes
            // the attribute — an anchor rendered through asChild must not.
            type={asChild ? undefined : (type ?? 'button')}
            className={cn('btn', VARIANT[variant], SIZE[size], className)}
            disabled={asChild ? undefined : (disabled ?? loading)}
            aria-busy={loading || undefined}
            aria-disabled={asChild && (disabled ?? loading) ? true : undefined}
            {...props}
        >
            {loading ? (
                <Spinner className="shrink-0" />
            ) : icon !== undefined ? (
                <Icon name={icon} className="w-4 h-4 shrink-0" />
            ) : null}
            {/*
                Slottable marks WHICH child is the element to merge onto, so the leading
                glyph above can sit beside it and still end up inside the caller's anchor.
                Without it Slot sees two children and refuses, which is what a button that
                navigates AND has an icon would always be.
            */}
            {asChild ? <Slot.Slottable>{children}</Slot.Slottable> : children}
        </Comp>
    );
});
