<?php

namespace App\Console\Commands;

use App\Models\GuestCheckoutLog;
use Illuminate\Console\Command;

class AnonymizeOldGuestLogs extends Command
{
    protected $signature = 'pii:anonymize {--days=90 : Usia minimum log dalam hari}';

    protected $description = 'Anonimisasi pesan WhatsApp pada log checkout tamu yang melewati batas retensi';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $count = 0;

        GuestCheckoutLog::query()
            ->where('created_at', '<', $cutoff)
            ->where('wa_message_text', '!=', '[ANONYMIZED]')
            ->chunkById(200, function ($logs) use (&$count): void {
                foreach ($logs as $log) {
                    $log->update(['wa_message_text' => '[ANONYMIZED]']);
                    $count++;
                }
            });

        $this->info("Anonimisasi selesai: {$count} log checkout tamu diproses.");

        return self::SUCCESS;
    }
}
