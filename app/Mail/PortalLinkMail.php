<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\PortalLinks\CreatePortalLink;
use App\Platform\Enums\PortalIntent;
use Carbon\CarbonInterface;

/**
 * An Admin Portal link, sent to the customer's IT contact by whoever minted it
 * ({@see CreatePortalLink}).
 *
 * The person reading it has no account here and may never have heard of this platform, so
 * it says plainly what they are being asked to set up, for which organization, and until
 * when the link works — and that it works once. A HOSTED mail: in the language the minter
 * chose for the recipient, not the console's English.
 */
final class PortalLinkMail extends BrandedMailable
{
    /**
     * @param  list<string>  $intents  the {@see PortalIntent} values it covers
     */
    public function __construct(
        public string $organization,
        public string $url,
        public array $intents,
        public CarbonInterface $expiresAt,
    ) {}

    protected function subjectLine(): string
    {
        $subject = __('mail.portal_link.subject', [
            'organization' => $this->organization,
            'brand' => $this->brand()->name,
        ]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.portal-link', 'mail.text.portal-link'];
    }

    protected function data(): array
    {
        return [
            'tasks' => array_map(static function (string $intent): string {
                $line = __('mail.portal_link.intents.'.$intent);

                return is_string($line) ? $line : $intent;
            }, $this->intents),
            // In the reader's own calendar and clock, as the template renders in their locale.
            'expiresOn' => $this->expiresAt->copy()->locale(app()->getLocale())->isoFormat('llll'),
        ];
    }
}
