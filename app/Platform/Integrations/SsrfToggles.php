<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use Cbox\Id\Kernel\Ssrf\UrlVerification;
use Illuminate\Contracts\Config\Repository;

/**
 * THE SSRF GUARD TOGGLES, READ ONE WAY. Each outbound plane's `verify_url` comes from the
 * environment, where `CBOX_ID_ACTIONS_VERIFY_URL=1`, `on` or `yes` stays a STRING. The
 * guards that ask {@see UrlVerification::enforced()} read any of those as on; a reader that
 * compares `=== true` read them as off. Inline-hook delivery did, so registration was
 * guarded and delivery was not: a hook host rebound to an internal address after
 * registration got the signed hook context at every sign-in (security finding #56).
 *
 * So every toggle is stored as the boolean the guards already agree it means, once, before
 * anything reads it: off only when it says off.
 */
final class SsrfToggles
{
    /** @var list<string> */
    public const array KEYS = [
        'cbox-id.webhooks.verify_url',
        'cbox-id.provisioning.verify_url',
        'cbox-id.federation.verify_url',
        'cbox-id.migration.verify_url',
        'cbox-id.oauth.client_id_metadata_documents.verify_url',
        'cbox-id.oauth.ciba.ping.verify_url',
        'cbox-id.oauth.backchannel_logout.verify_url',
        'cbox-id.external_actions.verify_url',
        'cbox-id.pipes.verify_url',
    ];

    public static function apply(Repository $config): void
    {
        foreach (self::KEYS as $key) {
            if ($config->has($key)) {
                $config->set($key, UrlVerification::enforced($key));
            }
        }
    }
}
