<?php

declare(strict_types=1);

namespace App\Http\Props\Auth;

use App\Http\Props\Prop;
use App\Platform\Social\OperatorProviders;
use App\Platform\SsoStart;
use Cbox\Id\Federation\Contracts\SignInProviders;

/**
 * A "Continue with …" button, and the three places one can come from.
 *
 * The OPERATOR's providers (config `services.*`) are the platform's own, offered on every
 * sign-in page in the deployment. The ENVIRONMENT's are the vendor's own credentials with
 * Google or GitHub, set up once in its console and offered on every sign-in page in that
 * environment — including the plain one, before anybody has said who they are. An
 * ORGANIZATION's are its own credentials, offered on its branded page in place of the
 * environment's for the same provider (or the environment's may be turned off there). The
 * last two are resolved together by {@see SignInProviders::offeredTo()} — the precedence
 * lives there, once, rather than being re-derived here.
 *
 * WHERE THE OPERATOR AND A TENANT BOTH HAVE ONE FOR THE SAME PROVIDER, THE TENANT'S WINS. The
 * accounts people end up with should sit with the tenant that invited them, and a button
 * that quietly used the platform's credentials instead of theirs would put those accounts
 * on the wrong side of that line — invisibly, and only discovered later.
 */
final readonly class SocialProviderProps implements Prop
{
    public function __construct(
        /** The catalogue key — `google`, `github` — which is also the mark to draw. */
        public string $provider,
        public string $label,
        public string $url,
    ) {}

    /**
     * @return list<self>
     */
    public static function forOrganization(?string $organizationId): array
    {
        $providers = [];

        $organizationId = $organizationId === '' ? null : $organizationId;

        foreach (app(SignInProviders::class)->offeredTo($organizationId) as $connection) {
            $providers[(string) $connection->provider] = new self(
                provider: (string) $connection->provider,
                label: $connection->name,
                url: SsoStart::url($connection),
            );
        }

        foreach (app(OperatorProviders::class)->all() as $operator) {
            if (! array_key_exists($operator->key(), $providers)) {
                $providers[$operator->key()] = new self(
                    provider: $operator->key(),
                    label: $operator->label(),
                    url: route('social.redirect', $operator->key()),
                );
            }
        }

        return array_values($providers);
    }

    /**
     * @return array{provider: string, label: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'label' => $this->label,
            'url' => $this->url,
        ];
    }
}
