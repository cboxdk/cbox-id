<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\PortalLinks\CreatePortalLink;
use App\Platform\Enums\PortalIntent;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An Admin Portal link, sent to the customer's IT contact by whoever minted it
 * ({@see CreatePortalLink}).
 *
 * The person reading it has no account here and may never have heard of this platform, so
 * it says plainly what they are being asked to set up, for which organization, and until
 * when the link works — and that it works once. A HOSTED mail: in the language the minter
 * chose for the recipient, not the console's English.
 */
final class PortalLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $intents  the {@see PortalIntent} values it covers
     */
    public function __construct(
        public string $organization,
        public string $url,
        public array $intents,
        public CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.portal_link.subject', [
                'organization' => $this->organization,
                'brand' => MailText::brand(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.portal-link', with: [
            'brand' => MailText::brand(),
            'tasks' => array_map(static function (string $intent): string {
                $line = __('mail.portal_link.intents.'.$intent);

                return is_string($line) ? $line : $intent;
            }, $this->intents),
            // In the reader's own calendar and clock, as the template renders in their locale.
            'expiresOn' => $this->expiresAt->copy()->locale(app()->getLocale())->isoFormat('llll'),
        ]);
    }
}
