<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrderActions::makeConfirmCodAction(),
            OrderActions::makeMarkAsPaidAction(),
            OrderActions::makeProcessingAction(),
            OrderActions::makeShipAction(),
            OrderActions::makeCompleteAction(),
            ActionGroup::make([
                OrderActions::makeUpdateTrackingAction(),
                OrderActions::makeRequestRefundAction(),
                OrderActions::makeApproveRefundAction(),
                OrderActions::makeRejectRefundAction(),
                OrderActions::makeCompleteRefundAction(),
                OrderActions::makeCancelAction(),
            ])->label('Aksi Lainnya'),
        ];
    }
}
