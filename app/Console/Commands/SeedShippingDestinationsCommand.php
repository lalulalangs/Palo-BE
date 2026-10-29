<?php

namespace App\Console\Commands;

use Database\Seeders\ShippingDestinationSeeder;
use Illuminate\Console\Command;

class SeedShippingDestinationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shipping:seed-destinations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed master destinasi pengiriman lokal untuk pencarian kilat dan penghematan kuota API RajaOngkir';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Memulai seeding master destinasi pengiriman lokal...');

        $seeder = new ShippingDestinationSeeder;
        $seeder->run();

        $this->info('Master destinasi pengiriman lokal berhasil di-seed.');

        return self::SUCCESS;
    }
}
