<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\Models\EnrolmentCode;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * The short-lived code the Trusted devices page renders as a QR.
 *
 * WHY THIS EXISTS
 * ---------------
 * The code used to be a bare hostname with no expiry and no identity — safe to
 * screenshot, and therefore safe to plant. Anyone who obtained the image could point a
 * handset at this host indefinitely, and nothing tied the enrolment that followed to
 * the person whose screen displayed it.
 *
 * WHAT IT IS NOT
 * --------------
 * It is not an anti-phishing control, and it must not be described as one. The app
 * fetches the verifying key from the very host the code names, so a code forged for a
 * hostile host, signed by that host, verifies perfectly. A signature can only ever say
 * "this host said this".
 *
 * What it does buy is FRESHNESS — a two-minute life means a code must come from a
 * console session open right now — and BINDING: `sub` travels to `POST /devices`, where
 * it is checked against the subject that actually signed in, so a code scanned off a
 * colleague's screen cannot attach a handset to their account.
 *
 * Signed with the platform's ordinary RS256 signing key, the one already published at
 * `/.well-known/jwks.json`. No new key, no new distribution problem: a self-hosted
 * installation publishes its own and the app verifies against whatever it finds there.
 */
final class EnrolmentToken
{
    /**
     * Two minutes.
     *
     * Long enough to unlock a phone, open the app and line up the camera; short enough
     * that a photograph of the screen is worthless by the time it is shared. The console
     * page re-renders the code before this elapses, so the user never sees it lapse.
     */
    public const TTL = 120;

    /**
     * The `typ` header. A resource that accepts more than one kind of signed token must
     * be able to refuse the wrong kind — without it, any RS256 token this platform ever
     * minted for any purpose would be presentable here.
     */
    private const TYPE = 'cbox-enrolment+jwt';

    public function __construct(
        private readonly TokenSigner $signer,
        private readonly IssuerResolver $issuers,
    ) {}

    /**
     * A code for one signed-in subject.
     */
    public function mint(string $subjectId): string
    {
        $now = Carbon::now();

        return $this->signer->sign([
            'iss' => $this->issuers->issuer(),
            'sub' => $subjectId,
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + self::TTL,
            'jti' => (string) Str::ulid(),
        ], SigningAlg::RS256, self::TYPE);
    }

    /**
     * Verify a presented code and SPEND it, returning the subject it was minted for.
     *
     * @throws EnrolmentCodeRejected
     */
    public function consume(string $token, string $expectedSubjectId, ?string $installId = null): string
    {
        try {
            $claims = $this->signer->verify($token, [SigningAlg::RS256]);
        } catch (Throwable $e) {
            throw new EnrolmentCodeRejected('That enrolment code is not valid or has expired.', previous: $e);
        }

        // Read only AFTER the signature is verified — the header is covered by it, so
        // until then it is attacker-controlled text.
        $this->assertType($token);

        // Minted by THIS environment, for itself. Without it a code from a sibling
        // tenant on the same deployment — same signing keys — would be accepted here.
        if ($claims->string('iss') !== $this->issuers->issuer()) {
            throw new EnrolmentCodeRejected('That enrolment code was issued for a different host.');
        }

        $subject = $claims->subject();

        // The check the whole mechanism exists for. The code says who displayed it; the
        // access token says who signed in. When they differ, someone scanned a code that
        // was not theirs, and enrolling would attach this handset to the other account.
        if ($subject === null || ! hash_equals($subject, $expectedSubjectId)) {
            throw new EnrolmentCodeRejected('That enrolment code belongs to a different account.');
        }

        $jti = $claims->string('jti');
        $exp = $claims->get('exp');

        if ($jti === null || ! is_int($exp)) {
            throw new EnrolmentCodeRejected('That enrolment code is malformed.');
        }

        $this->spend($jti, $subject, $exp, $installId);

        return $subject;
    }

    /**
     * Refuse a token of any other kind.
     *
     * Not a formality. An OAuth access token is signed with the SAME RS256 key, carries
     * an `iss` this environment would accept, a `sub` that would match the caller, and a
     * `jti` — so without this check a device could present its own access token as an
     * enrolment code and enrol itself indefinitely, defeating both the TTL and the
     * single-use record. RFC 9068 §5 exists for exactly this reason.
     *
     * @throws EnrolmentCodeRejected
     */
    private function assertType(string $token): void
    {
        // `explode` always yields at least one element, so the header segment is never
        // absent — it is the empty string for an empty token, which decodes to nothing
        // and fails the check below. A `?? ''` here read as a guard and was not one.
        $segments = explode('.', $token);
        $header = json_decode(
            (string) base64_decode(strtr($segments[0], '-_', '+/'), true),
            true,
        );

        if (! is_array($header) || ($header['typ'] ?? null) !== self::TYPE) {
            throw new EnrolmentCodeRejected('That is not an enrolment code.');
        }
    }

    /**
     * Record the code as spent, or refuse because it already was.
     *
     * The uniqueness is enforced by the PRIMARY KEY, not by a preceding read. Two
     * requests arriving together would both pass a read-then-write check, and the losing
     * outcome there is a second handset enrolled on someone else's account — so the
     * database is left to arbitrate and the duplicate surfaces as an insert failure.
     */
    private function spend(string $jti, string $subjectId, int $expiresAt, ?string $installId): void
    {
        // Through the model, not DB::table(): BelongsToEnvironment is what stamps
        // `environment_id` and scopes every later read. A raw insert would write an
        // unscoped row that no tenant could see and none could be protected by.
        $code = new EnrolmentCode;
        $code->jti = $jti;
        $code->subject_id = $subjectId;
        $code->install_id = $installId;
        $code->consumed_at = Carbon::now();
        $code->expires_at = Carbon::createFromTimestamp($expiresAt);

        try {
            $code->save();
        } catch (Throwable $e) {
            throw new EnrolmentCodeRejected('That enrolment code has already been used.', previous: $e);
        }
    }
}
