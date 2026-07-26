<?php

namespace Tests;

use App\Support\Tenancy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tenancy is a process-static context. The test process is reused across
        // test classes, so reset it before each test — otherwise a tenant set by
        // one test would leak into the next (e.g. auto-stamping a stale tenant_id
        // onto factory-created users and breaking unrelated tests).
        Tenancy::clear();
    }
}
