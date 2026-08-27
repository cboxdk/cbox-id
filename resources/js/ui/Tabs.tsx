import { Tabs as Primitive } from 'radix-ui';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface TabsProps {
    value: string;
    onValueChange: (value: string) => void;
    /** Names the set of tabs for assistive technology — "Saved views", "Delivery status". */
    label: string;
    className?: string;
    /** The `<Tab>`s themselves. */
    children: ReactNode;
    /**
     * The `<TabPanel>`s, when the tabs have panels.
     *
     * A separate prop rather than more children because Radix wants the list and the
     * panels as siblings under one root, and both have to be INSIDE it: a panel rendered
     * outside is a panel the trigger's `aria-controls` points at and cannot find.
     */
    panels?: ReactNode;
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
export function Tabs({ value, onValueChange, label, className, children, panels }: TabsProps) {
    return (
        <Primitive.Root value={value} onValueChange={onValueChange} className={cn(className)}>
            <Primitive.List className="cbx-tabs" aria-label={label}>
                {children}
            </Primitive.List>

            {panels}
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

/**
 * The panel one tab shows.
 *
 * REQUIRED WHENEVER THERE IS ONE. A `<Tab>` announces itself as controlling a panel — that
 * is what makes it a tab rather than a button — so rendering the content beside the list
 * instead leaves the trigger pointing at an element that does not exist, and a screen
 * reader is told there is somewhere to go and finds nothing there.
 *
 * The narrow rule above still stands: a tab that changes what the page is ABOUT is a page.
 * What this is for is one view rendered several ways — the same "connect it" instructions
 * in each SDK, the same list under a status filter.
 */
export function TabPanel({ value, children }: { value: string; children: ReactNode }) {
    return <Primitive.Content value={value}>{children}</Primitive.Content>;
}
