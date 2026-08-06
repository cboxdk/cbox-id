<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * One nonce per request, shared between the policy header and the markup it permits.
 *
 * Livewire emits its runtime configuration as an INLINE `<script>`. Under a policy with
 * `script-src 'self'` and no `'unsafe-inline'` — which is the policy worth having — the
 * browser refuses to execute it, and the console arrives with no Livewire at all: every
 * button dead, no error on screen, and a violation in a console nobody has open.
 *
 * The two ways out are not equal. `'unsafe-inline'` permits every inline script on every
 * page forever, including one an injection manages to place, which is the whole thing the
 * directive exists to stop. A nonce permits exactly the scripts we put the value on, and
 * only for this response.
 *
 * Bound `scoped`, so the value is generated once per request and is the same value the
 * header and the markup see. A second nonce would be a header that permits a script that
 * does not exist and markup that carries one nobody allowed.
 */
final class CspNonce
{
    private ?string $value = null;

    /**
     * 128 bits, base64. A nonce that repeats across responses is one an attacker can
     * read from a page they can already see and reuse on a page they are injecting into.
     */
    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }
}
