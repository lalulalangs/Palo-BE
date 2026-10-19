<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Pembayaran',
            self::AwaitingConfirmation => 'Menunggu Konfirmasi',
            self::Paid => 'Dibayar',
            self::Processing => 'Diproses',
            self::Shipped => 'Dikirim',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
            self::Expired => 'Kedaluwarsa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::AwaitingConfirmation => 'warning',
            self::Paid => 'info',
            self::Processing => 'primary',
            self::Shipped => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::Expired => 'gray',
        };
    }

    public function frontend(): string
    {
        return match ($this) {
            self::Pending => 'menunggu_pembayaran',
            self::AwaitingConfirmation => 'menunggu_konfirmasi',
            self::Paid => 'dibayar',
            self::Processing => 'diproses',
            self::Shipped => 'dikirim',
            self::Completed => 'selesai',
            self::Cancelled => 'dibatalkan',
            self::Expired => 'expired',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    public static function labelFor(self|string $status): string
    {
        $case = $status instanceof self ? $status : self::tryFrom($status);

        return $case?->label() ?? (string) $status;
    }

    public static function colorFor(self|string $status): string
    {
        $case = $status instanceof self ? $status : self::tryFrom($status);

        return $case?->color() ?? 'gray';
    }
}
