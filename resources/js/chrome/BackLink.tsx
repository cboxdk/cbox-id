import { useTranslator } from '@/i18n';
import type { AccountBack } from '@/types';
import { Icon } from '@/ui';

/**
 * "← Back to Cbox Billing": the app or console the person came from. Another origin, so a
 * real navigation and never an Inertia visit. The address is the server's, validated
 * there ({@see \App\Platform\Surfaces\ReturnUrl}); this only draws it.
 */
export function BackLink({ back }: { back: AccountBack }) {
    const { t } = useTranslator();

    return (
        <a href={back.url} className="idc-back-link" data-test="account-back">
            <Icon name="arrow-left" className="w-4 h-4 shrink-0" />
            <span className="truncate">
                {t('console.back_to_part1_dc732693', { part1: back.label })}
            </span>
        </a>
    );
}
