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

        // Tenancy is PINNED here, not inherited from the developer's `.env`.
        //
        // `PlaneResolver::isMultiTenant()` reads the stated flag before it derives
        // anything, and a multi-tenant deployment splits the planes: the default test
        // host resolves to the platform root, so every subject-plane route 404s. Setting
        // `CBOX_ID_MULTI_TENANT=true` in a local `.env` to look at the console took the
        // suite from 14 failures to 136 — console, auth, accessibility, query budgets —
        // all of them a plane bulkhead doing its job on a request that meant nothing.
        //
        // It has to be config, not `<env>` in phpunit.xml: PHPUnit sets those before the
        // framework boots and Laravel's `LoadEnvironmentVariables` then loads `.env` over
        // the top, so even `force="true"` loses. `.env.testing` would work but replaces
        // the whole file, which is a second copy of every unrelated key to drift.
        //
        // Single-tenant is the baseline because it is the shape a fresh install has. A
        // test that wants the SaaS shape states it — `config(['cbox-id.tenancy...'])` —
        // and that statement now decides the test instead of agreeing with the ambient
        // value by luck.
        config([
            'cbox-id.tenancy.multi_tenant' => false,
            'cbox-id.tenancy.account_host' => null,
            'cbox-id.environments.base_domains' => [],
        ]);

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
