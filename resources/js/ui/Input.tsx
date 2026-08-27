import { forwardRef, type InputHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';
import { useFieldControl } from './Field';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
    /** `lg` is the sign-in surface's scale; the console is dense and uses the default. */
    scale?: 'md' | 'lg';
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
    { scale = 'md', className, ...props },
    ref,
) {
    const field = useFieldControl();

    return (
        <input
            ref={ref}
            className={cn('input', scale === 'lg' && 'input-lg', className)}
            {...field}
            {...props}
        />
    );
});

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
    { className, rows = 4, ...props },
    ref,
) {
    const field = useFieldControl();

    return (
        <textarea
            ref={ref}
            rows={rows}
            className={cn('input', className)}
            style={{ height: 'auto', padding: '8px 10px', lineHeight: 1.5 }}
            {...field}
            {...props}
        />
    );
});
