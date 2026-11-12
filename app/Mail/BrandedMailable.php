<?php

declare(strict_types=1);

namespace App\Mail;

use App\Listeners\SuppressSandboxMail;
use App\Platform\Locale\MailLocale;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * links and codes) and leaves only after the request's transaction commits.
 *
 * EVERY MAIL IS QUEUED ({@see ShouldQueue}), whether the send site says `send()` or
 * `queue()`, and goes out through the one mailer, Cbox Relay. There is no second provider
 * to fail over to (Brain mem_MeQkGzrwgYRrXMWJJtSECE): a Relay outage is ridden out by the
 * queue instead. A refused try is retried with a growing pause ({@see self::backoff()})
 * for up to {@see self::RETRY_HOURS} hours, then the job fails into `failed_jobs`. Inside
 * that window the attempt count is no limit — Laravel ignores the worker's `--tries` once
 * a job has a {@see self::retryUntil()} — so an outage shorter than the window delays a
 * mail and never loses it.
 *
 * A mail whose link or code dies sooner stops trying when it dies
 * ({@see self::lifetime()}): nobody should get a sign-in code hours after it stopped
 * working.
 */
abstract class BrandedMailable extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** How long a mail is retried through a mail-provider outage, at most. */
    public const int RETRY_HOURS = 6;

    /** When what this mail carries stops working, where the send site knows it. */
    protected ?DateTimeInterface $usableUntil = null;

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
     * @param  DateTimeInterface|\DateInterval|int  $delay
     */
    public function later($delay, Queue $queue): mixed
    {
        $this->prepareForQueue();

        return parent::later($delay, $queue);
    }

    /**
     * Stop retrying when what the mail carries stops working: a link or code past its
     * expiry is worth nothing to the person, so it is not sent at all.
     */
    public function usableUntil(?DateTimeInterface $until): static
    {
        $this->usableUntil = $until;

        return $this;
    }

    /**
     * When to stop trying: {@see self::RETRY_HOURS} from now, or sooner when the link or
     * code inside dies sooner. Read once, when the mail is queued.
     */
    public function retryUntil(): DateTimeInterface
    {
        $window = Carbon::now()->addHours(self::RETRY_HOURS);
        $lifetime = $this->lifetime();

        return $lifetime !== null && $lifetime < $window ? Carbon::instance($lifetime) : $window;
    }

    /**
     * Seconds to wait before each retry; the last pause repeats until {@see retryUntil()}.
     * The pauses grow to 15 minutes and stay there: a mail goes out at most 15 minutes
     * after Relay recovers, and a long outage costs four tries an hour, not a tight loop.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600, 900];
    }

    /** The mail gave up. The job and its exception are in `failed_jobs`. */
    public function failed(Throwable $e): void
    {
        Log::warning('A mail was not delivered.', [
            'mail' => static::class,
            'exception' => $e->getMessage(),
        ]);
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

    /**
     * When the link or code this mail carries stops working, or null for a mail with
     * nothing that expires. A subclass with its own clock overrides it.
     */
    protected function lifetime(): ?DateTimeInterface
    {
        return $this->usableUntil;
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
