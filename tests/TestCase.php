<?php

namespace Tests;

use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Risk\Contracts\MailDomainResolver;
use Cbox\Risk\Contracts\TorExitNodes;
use Cbox\Risk\Testing\FakeMailDomainResolver;
use Cbox\Risk\Testing\FakeTorExitNodes;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiContract;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every HTTP response the suite produces passes through here, so the API contract
     * is checked on every `/api/*` request any test makes — no per-test opt-in.
     *
     * See {@see ApiContract}: one error envelope on failures, and a body that validates
     * against the documented OpenAPI response schema on documented operations.
     *
     * @param  Request  $request
     * @param  Response  $response
     */
    protected function createTestResponse($response, $request): TestResponse
    {
        ApiContract::verify($request, $response);

        return parent::createTestResponse($response, $request);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every test runs inside a default environment — the platform's hard outer
        // scope is deny-by-default, so without one the environment-owned models
        // return nothing. The web SetEnvironment middleware falls back to the same
        // default, so HTTP requests in tests share the environment the test seeds.
        config(['cbox-id.environments.default' => 'env_test']);
        app(EnvironmentContext::class)->set(GenericEnvironment::of('env_test'));

        // Keep risk-scoring DNS/Tor lookups offline and deterministic in tests.
        $this->app->instance(MailDomainResolver::class, new FakeMailDomainResolver);
        $this->app->instance(TorExitNodes::class, new FakeTorExitNodes);

        // The password policy is enforced at the credential primitive, so EVERY test
        // that creates a subject would otherwise reach out to HaveIBeenPwned — slow,
        // flaky, and it publishes a hash prefix for every fixture password to a third
        // party. NeverBreachedCheck is the framework's honest inert default: it claims
        // nothing rather than pretending it looked. Production binds the real
        // {@see \App\Platform\BreachedPasswords}; a test that wants breach behaviour
        // binds its own double.
        $this->app->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    }
}
