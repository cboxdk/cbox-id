<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use Cbox\Id\FrontendApi\Contracts\FrontendConfigContributor;
use Cbox\Id\Identity\Contracts\SignInMethods;

/**
 * Which sign-in methods an embedded sign-in box should draw — `methods` in the Frontend
 * API's `/config` document.
 *
 * The hosted page stopped drawing a passkey or magic-link button the environment switched
 * off; an embedded component reading the same document has to be able to do the same, or
 * it draws a button whose endpoint answers 403. Public by nature: the hosted sign-in page
 * shows the same thing to anybody who opens it.
 */
final readonly class SignInMethodsConfig implements FrontendConfigContributor
{
    public function __construct(private SignInMethods $methods) {}

    public function contribute(array $config): array
    {
        return [
            'methods' => [
                'passkeys' => $this->methods->passkeysEnabled(),
                'magic_link' => $this->methods->magicLinkEnabled(),
            ],
        ];
    }
}
