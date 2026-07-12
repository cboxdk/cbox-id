<?php

namespace Tests;

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

        // Keep risk-scoring DNS/Tor lookups offline and deterministic in tests.
        $this->app->instance(MailDomainResolver::class, new FakeMailDomainResolver);
        $this->app->instance(TorExitNodes::class, new FakeTorExitNodes);
    }
}
