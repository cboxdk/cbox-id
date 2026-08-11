<?php

declare(strict_types=1);

namespace App\Platform\Migration;

use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Migration\Models\LegacyLoginDeclarationRecord;
use Cbox\Id\Migration\Sources\HttpCredentialSource;
use Cbox\Ssrf\Contracts\UrlGuard;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Asking a declared endpoint whether it is alive, BEFORE anybody approves it.
 *
 * Approval puts a URL on the environment's sign-in path. Until now an operator did that
 * blind: the first thing that would find out whether the endpoint answers at all was a
 * real person's real login, failing — and failing closed, so they simply could not sign
 * in and nobody would know why.
 *
 * DELIBERATELY NOT `DeclaredCredentialSource`. That one refuses everything until a
 * declaration is approved, which is the property that makes it safe to bind, and it is
 * exactly wrong here: the whole point is to test the thing that has not been approved.
 * So the probe builds its own transport from the pending row and never touches the
 * sign-in path.
 *
 * IT SENDS NO PASSWORD. `find()` asks "do you know this address" and nothing more, which
 * is why the contract has it. An operator checking their integration must not be able to
 * use this screen to test somebody's credentials — and a probe that took a password would
 * be a credential-testing oracle with an approve button next to it.
 *
 * The address it asks about is the OPERATOR'S OWN, supplied by them, so nothing here can
 * be used to ask a customer's system about a third party.
 */
final class LegacyLoginProbe
{
    public function __construct(
        private readonly Http $http,
        private readonly UrlGuard $ssrf,
        private readonly SecretBox $secrets,
    ) {}

    /**
     * Ask the declared endpoint about an address, and describe what came back.
     *
     * Returns a short, human sentence rather than a boolean, because every distinct
     * failure here needs a different action from the operator: a signature mismatch means
     * the secret does not match, a timeout means the network, a 200 with nothing usable
     * means their handler is returning the wrong shape. A boolean would send them to the
     * logs to find out which.
     */
    public function describe(LegacyLoginDeclarationRecord $declaration, string $email): string
    {
        try {
            $secret = $this->secrets->open($declaration->secret_encrypted, $declaration->secretContext());
        } catch (Throwable) {
            return 'The stored secret could not be opened. Re-declare the endpoint from your app.';
        }

        $source = new HttpCredentialSource($this->http, $this->ssrf, $declaration->url, $secret);

        try {
            $found = $source->find($email);
        } catch (Throwable $e) {
            return 'The endpoint could not be reached: '.$e->getMessage();
        }

        if ($found === null) {
            // Genuinely ambiguous, and said so. The handler may be answering correctly
            // about an address it does not know, or refusing the signature, or returning a
            // shape we cannot read — and the transport collapses all three to null on
            // purpose, because on the sign-in path they mean the same thing.
            return 'No answer for that address. That is correct if your system does not know it — otherwise check the shared secret and that your handler returns an "email" field.';
        }

        return 'Your endpoint answered for '.$found->email.'. The integration works.';
    }
}
