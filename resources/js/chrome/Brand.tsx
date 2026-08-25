/*
 * `role="img"` on an INLINE svg, and it cannot be an <img>.
 *
 * The mark is filled from `var(--primary)`, which is how it follows the theme in both
 * directions and how it picks up a customer's white-label palette with no second asset
 * uploaded. An `<img src="data:…">` is a separate document: it resolves none of this
 * page's custom properties, so it would paint one fixed colour on every theme and for
 * every tenant. The role is what makes the inline svg announce as a single named image
 * rather than as a pile of shapes.
 */
/* eslint-disable jsx-a11y/prefer-tag-over-role */
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { cn } from '@/lib/cn';

/**
 * The Cbox · ID monogram, and the product's name beside it.
 *
 * Drawn from the `--primary` token rather than shipped as an image, so it follows the
 * theme in both directions — and, more to the point, so it follows a CUSTOMER's palette
 * on a branded sign-in without needing a second asset uploaded.
 */
export function Brand({ compact = false, className }: { compact?: boolean; className?: string }) {
    const { app } = usePage<SharedProps>().props;

    return (
        <span className={cn('inline-flex items-center gap-2.5 select-none', className)}>
            <svg
                viewBox="0 0 64 64"
                width="32"
                height="32"
                role="img"
                aria-label={app.name}
                style={{ flexShrink: 0, borderRadius: '9px', boxShadow: 'var(--shadow-card)' }}
            >
                <rect x="2" y="2" width="60" height="60" rx="14" fill="var(--primary)" />
                <text
                    x="32"
                    y="44"
                    textAnchor="middle"
                    fill="var(--primary-foreground)"
                    fontFamily="var(--font-display)"
                    fontWeight="700"
                    fontSize="30"
                    letterSpacing="-0.04em"
                >
                    ID
                </text>
            </svg>

            {!compact && (
                <span
                    className="font-semibold tracking-tight"
                    style={{ fontSize: '1.02rem', color: 'var(--foreground)' }}
                >
                    {app.name}
                </span>
            )}
        </span>
    );
}
