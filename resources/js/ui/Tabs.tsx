import { Tabs as Primitive } from 'radix-ui';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface TabsProps {
    value: string;
    onValueChange: (value: string) => void;
    /** Names the set of tabs for assistive technology — "Saved views", "Delivery status". */
    label: string;
    className?: string;
    children: ReactNode;
}

/**
 * Underline tabs.
 *
 * NARROW BY DESIGN. The pattern rule in this design system is that a tab which changes
 * what the page is ABOUT is a page, with its own URL, so it can be linked, bookmarked and
 * opened in a background tab. What is left for this component is filtering one view —
 * saved views, a status filter over the same list — where the URL is a query parameter,
 * not a route.
 *
 * If you find yourself putting a form in a tab panel, it wanted to be a page.
 */
export function Tabs({ value, onValueChange, label, className, children }: TabsProps) {
    return (
        <Primitive.Root value={value} onValueChange={onValueChange} className={cn(className)}>
            <Primitive.List className="cbx-tabs" aria-label={label}>
                {children}
            </Primitive.List>
        </Primitive.Root>
    );
}

export function Tab({ value, children }: { value: string; children: ReactNode }) {
    return (
        <Primitive.Trigger value={value} className="cbx-tab">
            {children}
        </Primitive.Trigger>
    );
}
