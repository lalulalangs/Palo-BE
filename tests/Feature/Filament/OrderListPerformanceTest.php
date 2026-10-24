<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\AdminUser;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penjaga regresi performa daftar pesanan.
 *
 * Yang diuji bukan kecepatan (rapuh di CI) melainkan bentuk query: badge tab
 * tidak boleh kembali menjadi COUNT per status, dan opsi filter kurir tidak
 * boleh di-query ulang setiap render.
 */
class OrderListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create(['email' => 'admin@palorinjani.com']);
    }

    private function createOrder(string $status, string $courier = 'JNE'): Order
    {
        return Order::create([
            'order_number' => 'UJN-'.fake()->unique()->numerify('########-#####'),
            'status' => $status,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'subtotal' => 350000,
            'discount_amount' => 0,
            'shipping_cost' => 25000,
            'total' => 375000,
            'shipping_recipient_name' => 'Rinjani Walker',
            'shipping_phone' => '081299998888',
            'shipping_full_address' => 'Desa Sembalun Lawang, Lombok Timur, NTB 83656',
            'shipping_courier' => $courier,
            'shipping_service' => 'REG',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function recordQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $callback();

        return $queries;
    }

    public function test_tab_badges_use_one_aggregate_query_instead_of_count_per_status(): void
    {
        $this->createOrder(Order::STATUS_PENDING);
        $this->createOrder(Order::STATUS_PENDING);
        $this->createOrder(Order::STATUS_COMPLETED);

        $queries = $this->recordQueries(function (): void {
            Livewire::actingAs($this->admin, 'admin')
                ->test(ListOrders::class)
                ->assertSuccessful();
        });

        $perStatusCounts = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'count(*)')
                && str_contains($sql, 'from "orders"')
                && str_contains($sql, '"status" =')
        );

        $this->assertCount(
            0,
            $perStatusCounts,
            'Badge tab dihitung per status; seharusnya satu query agregat group by status.'
        );

        $this->assertTrue(
            collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'group by "status"')),
            'Query agregat badge tab tidak ditemukan.'
        );
    }

    public function test_courier_filter_options_are_queried_once_across_renders(): void
    {
        $this->createOrder(Order::STATUS_PENDING, 'JNE');
        $this->createOrder(Order::STATUS_PENDING, 'J&T');

        $queries = $this->recordQueries(function (): void {
            Livewire::actingAs($this->admin, 'admin')->test(ListOrders::class)->assertSuccessful();
            Livewire::actingAs($this->admin, 'admin')->test(ListOrders::class)->assertSuccessful();
        });

        $distinctCourierQueries = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'distinct')
                && str_contains($sql, 'shipping_courier')
        );

        $this->assertCount(
            1,
            $distinctCourierQueries,
            'Opsi filter kurir di-query ulang setiap render; seharusnya dilayani cache.'
        );
    }

    public function test_date_filter_includes_the_whole_selected_day(): void
    {
        $lateNight = $this->createOrder(Order::STATUS_PENDING);
        $nextDay = $this->createOrder(Order::STATUS_PENDING);

        // Rentang harus inklusif sampai 23:59 pada tanggal yang dipilih.
        DB::table('orders')->where('id', $lateNight->id)->update(['created_at' => '2026-10-24 23:30:00']);
        DB::table('orders')->where('id', $nextDay->id)->update(['created_at' => '2026-10-25 00:30:00']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->filterTable('created_at', [
                'created_from' => '2026-10-24',
                'created_until' => '2026-10-24',
            ])
            ->assertCanSeeTableRecords([$lateNight])
            ->assertCanNotSeeTableRecords([$nextDay]);
    }
}
