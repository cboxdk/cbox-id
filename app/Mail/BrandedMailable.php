<?php

declare(strict_types=1);

namespace App\Mail;

use App\Listeners\SuppressSandboxMail;
use App\Platform\Locale\MailLocale;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * EVERY MAIL A PERSON GETS FROM CBOX ID, AND WHAT EACH ONE OWES THEM.
 *
 * The bar, held here once rather than by nine templates remembering it:
 *
 *  - BRANDED — drawn and signed in the brand of whoever the person was dealing with
 *    ({@see MailBrands}): the product name, the uploaded logo (an absolute URL on this
 *    application, never a remote image), the brand's colour on the button, the brand's
 *    sender name on the From line, and the footer.
 *  - IN THEIR LANGUAGE — every send site states it with `->locale()` ({@see MailLocale}),
 *    and the subject, the body and the text part all render inside it.
 *  - TWO PARTS — HTML and a plain-text alternative. A text part is what a screen reader in
 *    a text client, a smartwatch, a corporate gateway that strips HTML and every spam
 *    filter's "HTML-only" penalty all see; a mail without one is a worse mail for each.
 *
 * A subclass names its subject, its two views and the data they need
 * ({@see self::subjectLine()}, {@see self::views()}, {@see self::data()}); the brand,
 * the sender and the parts are put together here.
 *
 * THE BRAND IS FIXED AT THE MOMENT THE MAIL IS ASKED FOR, not when it is rendered — see
 * {@see MailBrand}. {@see self::queue()} resolves it before the message is serialized, so
 * a queued mail (every sign-in mail: a request never waits on the mail provider) keeps its
 * brand rather than falling back to ours. It also keeps the ENVIRONMENT it was asked in, so
 * the worker sends it inside that environment and a sandbox's mail is still suppressed
 * ({@see SuppressSandboxMail}). Queued mail is encrypted on the queue (it carries sign-in
 * links and codes), leaves only after the request's transaction commits, and is retried.
 */
abstract class BrandedMailable extends Mailable implements ShouldBeEncrypted
{
    use Queueable;
    use SerializesModels;

    /** A provider outage is retried, not lost. */
    public int $tries = 5;

    /** The environment the mail was asked in, for a worker that has none. */
    protected ?string $environmentKey = null;

    /** Resolved on first use; NOT public, so it never shadows the views' `$brand` name. */
    protected ?MailBrand $mailBrand = null;

    /** The organization this mail is about, when the flow knows it — for its brand. */
    protected ?string $brandOrganizationId = null;

    /**
     * Draw this mail in one organization's brand (its logo, colours and white-label name
     * over the environment's) — an invitation to it, its portal link, its certificate.
     */
    public function forOrganization(?string $organizationId): static
    {
        $this->brandOrganizationId = $organizationId;
        $this->mailBrand = null;

        return $this;
    }

    /** Pin an already-resolved brand — what a channel outside a request hands over. */
    public function inBrand(MailBrand $brand): static
    {
        $this->mailBrand = $brand;

        return $this;
    }

    public function brand(): MailBrand
    {
        return $this->mailBrand ??= app(MailBrands::class)->current($this->brandOrganizationId);
    }

    public function envelope(): Envelope
    {
        $sender = $this->brand()->senderName;
        $address = config('mail.from.address');

        // Built inside the send's `withLocale()`, so `__()` in the subject is already in the
        // recipient's language. Only the NAME of the sender is the brand's — the address
        // stays the deployment's own, the one its SPF and DKIM vouch for.
        return new Envelope(
            from: $sender !== null && is_string($address) && $address !== '' ? new Address($address, $sender) : null,
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        [$html, $text] = $this->views();
        $brand = $this->brand();

        return new Content(view: $html, text: $text, with: [
            'brand' => $brand->name,
            'theme' => $brand,
            ...$this->data(),
        ]);
    }

    /**
     * Resolve the brand before the message is serialized onto a queue: the worker that
     * renders it has no request and no environment in context.
     */
    public function queue(Queue $queue): mixed
    {
        $this->prepareForQueue();

        return parent::queue($queue);
    }

    /**
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     */
    public function later($delay, Queue $queue): mixed
    {
        $this->prepareForQueue();

        return parent::later($delay, $queue);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    /**
     * Send inside the environment the mail was asked in, when a worker sends it.
     *
     * @param  Factory|Mailer  $mailer
     */
    public function send($mailer)
    {
        $context = app(EnvironmentContext::class);
        if ($this->environmentKey === null || $context->current()?->environmentKey() === $this->environmentKey) {
            return parent::send($mailer);
        }

        return $context->runAs(GenericEnvironment::of($this->environmentKey), fn () => parent::send($mailer));
    }

    private function prepareForQueue(): void
    {
        $this->brand();
        $this->environmentKey = app(EnvironmentContext::class)->current()?->environmentKey();
        $this->afterCommit();
    }

    /** The subject, in the recipient's language. */
    abstract protected function subjectLine(): string;

    /**
     * The HTML view and its plain-text twin.
     *
     * @return array{0: string, 1: string}
     */
    abstract protected function views(): array;

    /**
     * What the two views need beyond the brand (`$brand` is its name, `$theme` the whole
     * {@see MailBrand}) and the mailable's own public properties.
     *
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return [];
    }
}
