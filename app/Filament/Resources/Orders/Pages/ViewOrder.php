<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
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
            OrderActions::makeUpdateTrackingAction(),
            OrderActions::makeCompleteAction(),
            OrderActions::makeRequestRefundAction(),
            OrderActions::makeApproveRefundAction(),
            OrderActions::makeRejectRefundAction(),
            OrderActions::makeCompleteRefundAction(),
            OrderActions::makeCancelAction(),
        ];
    }
}
