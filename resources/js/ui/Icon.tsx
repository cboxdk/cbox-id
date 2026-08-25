import type { SVGProps } from 'react';
import { cn } from '@/lib/cn';
import { type IconName, iconPaths } from './icons';

export interface IconProps extends Omit<SVGProps<SVGSVGElement>, 'children'> {
    name: IconName;
    /**
     * What the icon MEANS to somebody who cannot see it.
     *
     * Omit it — the default — and the icon is hidden from assistive technology, which is
     * right for the overwhelming majority: an icon beside a label, or inside a button
     * that already has an accessible name, is decoration, and announcing it twice is
     * worse than not announcing it.
     *
     * Pass it only when the icon is the ENTIRE meaning and nothing near it says the same
     * thing — a bare status glyph in a table cell.
     */
    label?: string;
}

/**
 * The console's one icon. 24×24 viewBox, 1.6 stroke, `currentColor` — so an icon takes
 * the colour of whatever it sits in and never needs a colour prop.
 *
 * Sizing is a class, not a prop, because every call site already sets a size class and a
 * second mechanism would mean two answers to the same question. The default matches what
 * the Volt `<x-icon>` used.
 */
export function Icon({ name, label, className, ...props }: IconProps) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={cn('w-5 h-5', className)}
            aria-hidden={label === undefined ? true : undefined}
            role={label === undefined ? undefined : 'img'}
            aria-label={label}
            {...props}
        >
            {iconPaths[name].map((d) => (
                <path key={d} d={d} />
            ))}
        </svg>
    );
}
