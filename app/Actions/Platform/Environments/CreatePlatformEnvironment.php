<?php

declare(strict_types=1);

namespace App\Actions\Platform\Environments;

use App\Actions\Platform\AsOperator;
use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Crypto\Contracts\KeyManager;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Support\Str;

/**
 * Create an environment on the deployment — a plane of its own, with its own signing key,
 * owned by no customer until it is attached to a project.
 *
 * THE DOMAIN IS NOT WRITTEN. An unverified domain written onto an environment becomes its
 * issuer and makes the plane unusable rather than reachable, so the environment is created
 * on its slug and the domain goes through the same DNS-TXT verification every other writer
 * uses. It is still taken here, as the console's form takes it: a domain already routed to
 * another environment is refused up front rather than after the operator has gone to
 * publish a record.
 *
 * The new plane's signing key is warmed at once, so its JWKS and discovery are live now.
 */
#[AsAction(
    name: 'platform.environments.create',
    summary: 'Create an environment on the deployment, with its own signing key. A domain is verified separately, by DNS.',
    scope: 'operator:environments:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['POST', '/environments'],
    status: 201,
    consoleRoutes: ['platform.environments.store'],
    consoleGate: ConsoleGate::Operator,
    schema: 'PlatformEnvironment',
    tag: 'Environments',
)]
final readonly class CreatePlatformEnvironment implements Action
{
    public function __construct(
        private EnvironmentContext $context,
        private KeyManager $keys,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(190),
            Field::string('domain')->nullable()->max(190)->describe('The domain it will serve on, once verified by DNS. Checked to be free; not written yet.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);

        $name = trim($context->string('name'));
        $domain = $context->nullableString('domain');
        $domain = $domain === null ? null : strtolower(trim($domain));

        if ($domain !== null && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) !== 1) {
            throw ActionRefused::because('invalid_domain', 'Enter a domain such as login.example.com.', 'domain');
        }

        if ($domain !== null && $this->context->withoutScope(static fn (): bool => Environment::query()->where('domain', $domain)->exists())) {
            throw ActionRefused::because('domain_taken', 'That domain is already routed to another environment.', 'domain');
        }

        $environment = Environment::query()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'status' => 'active',
        ]);

        $this->context->runAs($environment, fn () => $this->keys->activeSigningKey());

        return ActionResult::item($environment, self::present($environment));
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Environment $environment): array
    {
        return [
            'id' => $environment->id,
            'name' => $environment->name,
            'slug' => $environment->slug,
            'domain' => $environment->domain,
            'status' => $environment->status->value,
            'project_id' => $environment->getAttribute('project_id'),
            'created_at' => Timestamp::of($environment->getAttribute('created_at')),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'env';
        $slug = $base;
        $n = 2;

        while (Environment::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
