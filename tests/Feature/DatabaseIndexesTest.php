<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_traffic_foreign_keys_have_indexes(): void
    {
        $this->assertTrue(Schema::hasIndex('order_items', ['order_id']));
        $this->assertTrue(Schema::hasIndex('order_items', ['sku_id']));
        $this->assertTrue(Schema::hasIndex('payments', ['order_id']));
        $this->assertTrue(Schema::hasIndex('orders', ['user_id']));
        $this->assertTrue(Schema::hasIndex('orders', ['status', 'stock_locked_until']));
        $this->assertTrue(Schema::hasIndex('order_status_histories', ['order_id']));
        $this->assertTrue(Schema::hasIndex('carts', ['user_id']));
        $this->assertTrue(Schema::hasIndex('cart_items', ['sku_id']));
    }
}
