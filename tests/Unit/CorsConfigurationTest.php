<?php

namespace Tests\Unit;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_cors_origins_are_explicit_and_never_wildcard(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertIsArray($origins);
        $this->assertNotEmpty($origins);
        $this->assertNotContains('*', $origins);
        $this->assertSame(['api/*'], config('cors.paths'));
    }
}
