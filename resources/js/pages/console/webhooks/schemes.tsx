import type { Translator } from '@/i18n';
import type { RadioOption } from '@/ui';

/**
 * How an endpoint's deliveries are signed — the two schemes the framework offers, named
 * the way the API names them.
 *
 * Shared by the create form, the detail page's "Change scheme" and the list's badge, so
 * the three never describe the same scheme two ways.
 */
export type SignatureScheme = 'cbox' | 'standard_webhooks';

export const signatureSchemeLabels: Record<SignatureScheme, string> = {
    cbox: 'Cbox',
    standard_webhooks: 'Standard Webhooks',
};

export const signatureSchemeOptions = (t: Translator['t']): RadioOption<SignatureScheme>[] => [
    {
        value: 'cbox',
        label: signatureSchemeLabels.cbox,
        hint: t('console.x_cbox_signature_with_a_hex_hmac_the_default_verified_w_3bc267fa'),
    },
    {
        value: 'standard_webhooks',
        label: signatureSchemeLabels.standard_webhooks,
        hint: t('console.webhook_id_webhook_timestamp_and_webhook_signature_with_73e511b0'),
    },
];

/**
 * The form of the ids an endpoint's deliveries carry: bare ULIDs, as every endpoint has
 * received them so far, or public ids (`usr_…`), the API's own.
 */
export type IdFormat = 'raw' | 'prefixed';

export const idFormatOptions = (t: Translator['t']): RadioOption<IdFormat>[] => [
    {
        value: 'raw',
        label: t('console.bare_ids_76c5132c'),
        hint: t('console.as_every_endpoint_has_received_them_so_far_k_cf784ba9'),
    },
    {
        value: 'prefixed',
        label: t('console.public_ids_c30ab664'),
        hint: t('console.prefixed_by_kind_usr_org_the_same_ids_the_ap_1358fe56'),
    },
];
