<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use App\Platform\Appearance\Appearance;
use Cbox\Id\FrontendApi\Contracts\FrontendConfigContributor;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;

/**
 * The customer's theme, on the document a browser reads before it draws a sign-in box.
 *
 * WITHOUT THIS THE CHANNEL IS HALF USEFUL. A page can learn where to POST and which
 * social buttons to show, and then renders them in our colours instead of the customer's
 * — which is exactly the tell that gives away an embedded widget as somebody else's. The
 * whole point of embedding rather than redirecting is that it looks like the product it
 * is embedded in.
 *
 * ENVIRONMENT-LEVEL ONLY, and that is a real limit rather than an oversight.
 * {@see Appearance::effective()} prefers an ORGANIZATION's theme over the environment
 * default, and this document is fetched before anybody has identified themselves — there
 * is no organization yet, and asking for one by email would be the enumeration oracle
 * this channel exists to avoid. So a browser gets the environment's theme, and an
 * organization-specific one applies from the moment the flow knows who it is talking to.
 *
 * Everything here is already public: the same tokens are served in a `<style>` block on
 * the hosted sign-in page, which anybody can view source on. This changes who can render
 * them, not who can know them.
 */
final class AppearanceConfig implements FrontendConfigContributor
{
    public function __construct(private readonly EnvironmentContext $environments) {}

    public function contribute(array $config): array
    {
        $appearance = $this->forCurrentEnvironment();

        if ($appearance === null) {
            // No override: the platform default applies, and saying nothing is how the
            // SDK knows to use its own. An empty object here would read as "themed, with
            // no colours", which renders a blank sign-in box.
            return $config;
        }

        $config['appearance'] = $appearance->toArray();

        return $config;
    }

    /**
     * The environment's own theme, or null when it has not set one.
     *
     * Read through the environment already in context — the Frontend API middleware put
     * it there from the publishable key — so nothing on the request can point this at
     * another customer's environment.
     */
    private function forCurrentEnvironment(): ?Appearance
    {
        $key = $this->environments->current()?->environmentKey();

        if ($key === null) {
            return null;
        }

        $environment = Environment::query()->find($key);

        if ($environment === null) {
            return null;
        }

        /** @var array<string, mixed>|null $settings */
        $settings = is_array($raw = $environment->getAttribute('settings')) ? $raw : null;

        return Appearance::effective(null, $settings);
    }
}
