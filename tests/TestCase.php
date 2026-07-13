<?php

namespace Tests;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Risk\Contracts\MailDomainResolver;
use Cbox\Risk\Contracts\TorExitNodes;
use Cbox\Risk\Testing\FakeMailDomainResolver;
use Cbox\Risk\Testing\FakeTorExitNodes;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
    }
}
