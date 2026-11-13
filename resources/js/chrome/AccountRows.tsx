import type { ReactNode } from 'react';
import { useTranslator } from '@/i18n';
import { Button, DropdownMenu, DropdownMenuContent, DropdownMenuTrigger, Icon } from '@/ui';
import type { IconName } from '@/ui/icons';

/**
 * One row of a settings list: an icon tile, a title with its state, a line of detail, and
 * the row's actions on the right. Every list on the page is made of these, so a password,
 * a passkey, a linked Google account and an organization read the same way.
 */
export function SettingRow({
    icon,
    mark,
    title,
    badge,
    meta,
    actions,
    nested = false,
    id,
}: {
    icon?: IconName;
    /** Drawn in the tile instead of an icon: a provider's mark, an organization's initials. */
    mark?: ReactNode;
    title: ReactNode;
    badge?: ReactNode;
    meta?: ReactNode;
    actions?: ReactNode;
    nested?: boolean;
    id?: string;
}) {
    return (
        <li id={id} className={nested ? 'acct-row acct-row--nested' : 'acct-row'}>
            <span className="acct-row-icon" aria-hidden="true">
                {mark ?? (icon !== undefined && <Icon name={icon} className="w-4 h-4" />)}
            </span>
            <div className="acct-row-main">
                <p className="acct-row-title">
                    <span className="acct-row-name">{title}</span>
                    {badge}
                </p>
                {meta !== undefined && meta !== null && <p className="acct-row-meta">{meta}</p>}
            </div>
            {actions !== undefined && <div className="acct-row-actions">{actions}</div>}
        </li>
    );
}

/** The "⋯" menu at the end of a row, named after the thing it acts on. */
export function RowMenu({ name, children }: { name: string; children: ReactNode }) {
    const { t: tc } = useTranslator();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    size="sm"
                    variant="ghost"
                    className="acct-menu-button"
                    aria-label={tc('console.more_actions_for_part1_b26d24f6', { part1: name })}
                >
                    <Icon name="more" className="w-4 h-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>{children}</DropdownMenuContent>
        </DropdownMenu>
    );
}
