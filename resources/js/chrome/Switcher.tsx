import { router } from '@inertiajs/react';
import type { SwitchOption } from '@/types';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
    Icon,
} from '@/ui';

export interface SwitcherProps {
    /** The word above the options — "Switch organization", "Switch target". */
    heading: string;
    /** Above the current value, in mono caps. Absent for the organization crumb. */
    eyebrow?: string;
    /** The current selection, always shown even when there is nothing to switch to. */
    label: string;
    caption?: string | null;
    /** The avatar-style initial tile. Absent means an icon instead. */
    initial?: string;
    icon?: 'layers';
    options: SwitchOption[];
    /** Where the POST goes, and the field name it carries. */
    action: string;
    field: string;
    /** The label on the secondary control that OPENS a row rather than selecting it. */
    openLabel?: (option: SwitchOption) => string;
}

/**
 * The topbar's context control: which organization you are acting for, and — for an
 * operator — which environment this console is pointed at.
 *
 * IT RENDERS WITH ONE OPTION TOO. A person with a single organization still needs to see
 * WHICH one, on a console where the same page means different things in different ones.
 * What it does not do with one option is open: a menu that cannot change anything is a
 * control that lies about what it does.
 */
export function Switcher({
    heading,
    eyebrow,
    label,
    caption,
    initial,
    icon,
    options,
    action,
    field,
    openLabel,
}: SwitcherProps) {
    const canSwitch = options.length > 1;

    const trigger = (
        <>
            {initial !== undefined && (
                <span
                    className="grid place-items-center rounded-md shrink-0"
                    style={{
                        width: '26px',
                        height: '26px',
                        background: 'var(--accent-soft)',
                        color: 'var(--primary)',
                        fontSize: '11px',
                        fontWeight: 700,
                    }}
                    aria-hidden="true"
                >
                    {initial}
                </span>
            )}
            {icon !== undefined && (
                <Icon name={icon} className="w-4 h-4 shrink-0" style={{ color: 'var(--primary)' }} />
            )}

            <span className="min-w-0 text-left hidden sm:block">
                {eyebrow !== undefined && (
                    <span
                        className="block uppercase"
                        style={{
                            fontSize: '10px',
                            fontWeight: 500,
                            letterSpacing: '0.04em',
                            lineHeight: 1.2,
                            color: 'var(--muted-foreground)',
                        }}
                    >
                        {eyebrow}
                    </span>
                )}
                <span
                    className="block truncate"
                    style={{ fontSize: '13px', fontWeight: 600, lineHeight: 1.2 }}
                >
                    {label}
                </span>
                {caption != null && (
                    <span
                        className="block truncate"
                        style={{ fontSize: '11px', lineHeight: 1.2, color: 'var(--muted-foreground)' }}
                    >
                        {caption}
                    </span>
                )}
            </span>

            {canSwitch && (
                <Icon
                    name="chevron"
                    className="w-4 h-4 shrink-0"
                    style={{ color: 'var(--muted-foreground)' }}
                />
            )}
        </>
    );

    if (!canSwitch) {
        return (
            <div className="cbx-switcher-item flex items-center gap-2 rounded-lg px-2 py-1.5">
                {trigger}
            </div>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="cbx-switcher-item flex items-center gap-2 rounded-lg px-2 py-1.5">
                {trigger}
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" style={{ minWidth: '260px' }}>
                <DropdownMenuLabel>{heading}</DropdownMenuLabel>

                {options.map((option) => (
                    <div key={option.id} className="flex items-center gap-1">
                        <DropdownMenuItem
                            className="min-w-0 flex-1"
                            style={option.current ? { background: 'var(--secondary)' } : undefined}
                            // `router.post` rather than a form: the row IS the control,
                            // and wrapping every one of them in a <form> put a form inside
                            // a menu, which is where the Volt version's nested-form markup
                            // came from. CSRF travels on the XSRF cookie either way.
                            onSelect={() => router.post(action, { [field]: option.id })}
                        >
                            <span className="min-w-0 flex-1 text-left">
                                <span className="block truncate">{option.label}</span>
                                {option.caption != null && (
                                    <span className="cbx-menuitem-hint truncate">{option.caption}</span>
                                )}
                            </span>
                            {option.current && (
                                <Icon
                                    name="check"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--primary)' }}
                                />
                            )}
                        </DropdownMenuItem>

                        {option.openHref !== null && openLabel !== undefined && (
                            /*
                                Leaves this host for the target's own console. Titled
                                rather than labelled inline: the row is already two lines
                                deep and a third word wraps on a narrow window.
                            */
                            <a
                                href={option.openHref}
                                className="btn btn-ghost btn-sm shrink-0"
                                style={{ padding: '6px 8px' }}
                                title={openLabel(option)}
                                aria-label={openLabel(option)}
                            >
                                <span aria-hidden="true">↗</span>
                            </a>
                        )}
                    </div>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
