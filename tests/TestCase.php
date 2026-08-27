<?php

namespace Tests;

use Acop\Homologation\HomologationGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (in_array((string) getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)) {
            require_once dirname(__DIR__).'/tools/homologation/HomologationGuard.php';
            HomologationGuard::assertSafe();
        }

        parent::setUp();
    }
}
