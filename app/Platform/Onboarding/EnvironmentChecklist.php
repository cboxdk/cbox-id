<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Models\OnboardingDismissal;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Console\ShellContext;
use App\Platform\EnvironmentWorkspace;
use App\Support\CliClient;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\SessionParticipant;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * AN ENVIRONMENT'S "GET STARTED", measured against what is actually there.
 *
 * Like the organization's {@see SetupChecklist}: every step is a question the environment's
 * own state can answer, so the list is never stale and there is nothing to tick by hand —
 * create an app over the API, from MCP or in the console and the step ticks the same way.
 * A sign-in counts when a token was issued to a person for one of this environment's apps;
 * an agent counts when a management key has been USED, not merely minted.
 *
 * THE CLI'S OWN APP IS NOT YOURS. This environment provisions an app for the `cbox` CLI to
 * sign in through; counting it would tick "Create your first app" before the developer had
 * created anything, and a sign-in to the CLI would tick "Sign somebody in".
 *
 * Dismissal is per person, per environment ({@see OnboardingDismissal} with no
 * organization): one administrator who has seen enough does not take the guidance away
 * from a colleague who joined yesterday.
 */
final readonly class EnvironmentChecklist
{
    public function __construct(
        private EnvironmentWorkspace $workspace,
        private PlatformRoot $platformRoot,
        private Memberships $memberships,
        private Invitations $invitations,
        private ShellContext $shell,
    ) {}

    /**
     * Each step and whether it is done, in order.
     *
     * @param  string|null  $workspaceId  The workspace (root organization) administering this
     *                                    environment — whose team "Invite a teammate" counts.
     * @return array<string, bool> Keyed by {@see EnvironmentStep} value.
     */
    public function progress(?string $workspaceId): array
    {
        $done = [];

        foreach (EnvironmentStep::cases() as $step) {
            $done[$step->value] = $this->isDone($step, $workspaceId);
        }

        return $done;
    }

    /**
     * What the page and the home page draw.
     *
     * @return array{steps: list<array{key: string, title: string, description: string, actionLabel: string, href: string|null, done: bool}>, completed: int, total: int, percent: int, isComplete: bool, next: string|null}
     */
    public function toProps(?string $workspaceId): array
    {
        $steps = [];

        foreach ($this->progress($workspaceId) as $key => $done) {
            $step = EnvironmentStep::from($key);
            $route = $step->route();

            $steps[] = [
                'key' => $key,
                'title' => $step->title(),
                'description' => $step->description(),
                'actionLabel' => $step->actionLabel(),
                'href' => match (true) {
                    $step === EnvironmentStep::InviteTeammate => $this->shell->onWorkspaceHost('members'),
                    $route !== null && Route::has($route) => route($route),
                    default => null,
                },
                'done' => $done,
            ];
        }

        $completed = count(array_filter($steps, static fn (array $step): bool => $step['done']));
        $total = count($steps);
        $next = array_values(array_filter($steps, static fn (array $step): bool => ! $step['done']))[0]['title'] ?? null;

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'percent' => $total === 0 ? 100 : (int) round($completed / $total * 100),
            'isComplete' => $completed === $total,
            'next' => $next,
        ];
    }

    public function isDismissed(string $subjectId): bool
    {
        return OnboardingDismissal::query()
            ->whereNull('organization_id')
            ->where('subject_id', $subjectId)
            ->exists();
    }

    public function dismiss(string $subjectId): void
    {
        OnboardingDismissal::query()->firstOrCreate([
            'organization_id' => null,
            'subject_id' => $subjectId,
        ]);
    }

    public function restore(string $subjectId): void
    {
        OnboardingDismissal::query()
            ->whereNull('organization_id')
            ->where('subject_id', $subjectId)
            ->delete();
    }

    /** Whether somebody has signed in to $clientId — the quickstart's wait. */
    public function signedIn(string $clientId): bool
    {
        return AccessToken::query()->where('client_id', $clientId)->whereNotNull('user_id')->exists()
            || SessionParticipant::query()->where('client_id', $clientId)->exists();
    }

    private function isDone(EnvironmentStep $step, ?string $workspaceId): bool
    {
        return match ($step) {
            EnvironmentStep::CreateApp => $this->apps()->exists(),
            EnvironmentStep::AddRedirect => $this->apps()->get(['id', 'redirect_uris'])
                ->contains(static fn (Client $client): bool => ($client->redirect_uris ?? []) !== []),
            EnvironmentStep::FirstSignIn => $this->anySignIn(),
            EnvironmentStep::BrandSignIn => $this->branded(),
            EnvironmentStep::CreateOrganization => Organization::query()
                ->where('status', '!=', OrganizationStatus::Deleted->value)
                ->exists(),
            EnvironmentStep::ConnectSso => Connection::query()->exists(),
            EnvironmentStep::ConnectAgent => EnvironmentApiKey::query()
                ->whereNull('revoked_at')
                ->whereNotNull('last_used_at')
                ->exists(),
            EnvironmentStep::InviteTeammate => $workspaceId !== null && $this->hasTeammate($workspaceId),
            EnvironmentStep::GoLive => $this->apps()->get(['id', 'redirect_uris'])
                ->contains(static fn (Client $client): bool => array_any($client->redirect_uris ?? [], self::public(...))),
        };
    }

    /**
     * This environment's apps, the CLI's own left out.
     *
     * @return Builder<Client>
     */
    private function apps(): Builder
    {
        return Client::query()->where('name', '!=', CliClient::NAME);
    }

    private function anySignIn(): bool
    {
        $apps = $this->apps()->pluck('client_id')->all();

        if ($apps === []) {
            return false;
        }

        return AccessToken::query()->whereIn('client_id', $apps)->whereNotNull('user_id')->exists()
            || SessionParticipant::query()->whereIn('client_id', $apps)->exists();
    }

    private function branded(): bool
    {
        $settings = $this->workspace->environment()?->settings;

        if (! is_array($settings)) {
            return false;
        }

        $appearance = $settings['appearance'] ?? null;

        // An UPLOADED logo counts; a remote URL saved before uploads replaced it does not —
        // it is no longer drawn anywhere, so it brands nothing.
        return (is_array($appearance) && $appearance !== [])
            || app(BrandImages::class)->url(BrandImage::Logo, null) !== null;
    }

    /** The workspace's team is more than its founder, or somebody has been asked to join. */
    private function hasTeammate(string $workspaceId): bool
    {
        return (bool) $this->platformRoot->run(fn (): bool => $this->memberships->countForOrganization($workspaceId) > 1
            || $this->invitations->pending($workspaceId, 1)->isNotEmpty());
    }

    /** A real address: https, and not this machine. */
    private static function public(mixed $uri): bool
    {
        if (! is_string($uri) || ! str_starts_with(strtolower($uri), 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        return $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
            && ! str_ends_with($host, '.localhost')
            && ! str_ends_with($host, '.test');
    }
}
