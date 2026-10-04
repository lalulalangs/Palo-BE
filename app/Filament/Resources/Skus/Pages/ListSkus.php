<?php

namespace App\Filament\Resources\Skus\Pages;

use App\Filament\Resources\Skus\SkuResource;
use Filament\Resources\Pages\ListRecords;

class ListSkus extends ListRecords
{
    protected static string $resource = SkuResource::class;
}
