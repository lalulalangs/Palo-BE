<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Helper pembuatan data uji (user, produk, SKU) diekspos ke semua test
    // supaya setiap test punya cara yang sama menyiapkan fixture.
    use CreatesTestData;
}
