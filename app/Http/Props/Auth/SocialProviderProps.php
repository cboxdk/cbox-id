<?php

declare(strict_types=1);

namespace App\Http\Props\Auth;

use App\Http\Props\Prop;
use App\Platform\Social\OperatorProviders;
use App\Platform\SsoStart;
use Cbox\Id\Federation\Contracts\Connections;

/**
 * A "Continue with …" button, and the two places one can come from.
 *
 * The OPERATOR's providers (config `services.*`) are the platform's own, offered on every
 * sign-in page in the deployment. A TENANT's are its own credentials with Google or
 * GitHub, set up from its console, and belong only on its branded page.
 *
 * WHERE BOTH EXIST FOR THE SAME PROVIDER, THE TENANT'S WINS. The accounts people end up
 * with should sit with the tenant that invited them, and a button that quietly used the
 * platform's credentials instead of theirs would put those accounts on the wrong side of
 * that line — invisibly, and only discovered later.
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

        if ($organizationId !== null && $organizationId !== '') {
            foreach (app(Connections::class)->catalogueProvidersFor($organizationId) as $connection) {
                $providers[(string) $connection->provider] = new self(
                    provider: (string) $connection->provider,
                    label: $connection->name,
                    url: SsoStart::url($connection),
                );
            }
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
