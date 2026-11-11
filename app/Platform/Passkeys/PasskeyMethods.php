<?php

declare(strict_types=1);

namespace App\Platform\Passkeys;

use Cbox\Id\OAuthServer\Enums\AuthMethod;

/**
 * The `amr` a passkey sign-in records, said once for every door that takes one.
 *
 * `mfa` stands for "the authenticator verified the person", which is only true while this
 * deployment requires it (`cbox-id.webauthn.user_verification`, on unless turned off).
 * With it off a passkey proves possession alone, so `mfa` is left out, and the session
 * does not reach the phishing-resistant class the operator plane asks for.
 */
final class PasskeyMethods
{
    /**
     * @return list<string>
     */
    public static function used(): array
    {
        $methods = AuthMethod::forPasskey();

        return config('cbox-id.webauthn.user_verification', true) !== false
            ? $methods
            : array_values(array_diff($methods, [AuthMethod::MultiFactor->value]));
    }
}
