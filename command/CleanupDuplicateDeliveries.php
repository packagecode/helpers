<?php

namespace App\Console\Commands;

use App\Models\Core\Org;
use App\Models\Inventory\Stock;
use App\Models\MongoDB\DeliverySummary as MongoDeliverySummary;
use App\Models\Order\DeliveredProduct;
use App\Models\Order\Delivery;
use App\Models\Order\DeliverySummary;
use App\Models\Order\OrderStatus;
use App\Support\Tenant\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off repair for orders that got more than one delivery because the invoice
 * bulk delivery (POST /bulk-delivery-status) ran several times in parallel.
 *
 * Each extra delivery took its qty off the seller's stock again (and, for a
 * requisition, added it to the buyer's stock again) and added another
 * 'delivered' status row. For every order with more than one delivery created
 * on --date, this keeps the order's earliest delivery and, for the later ones:
 * gives the stock back, deletes their product lines, MySQL and Mongo summary
 * rows and the deliveries, and deletes the extra 'delivered' status rows.
 * pending_qty is left alone. Dry run unless --execute.
 */
class CleanupDuplicateDeliveries extends Command
{
    protected $signature = 'tenant:cleanup-duplicate-deliveries
        {--tenant= : Tenant domain, e.g. pvm}
        {--date= : Day the duplicate deliveries were created (Y-m-d)}
        {--execute : Apply the changes (omit for a dry run)}';

    protected $description = 'Remove duplicate deliveries of the same order created on one day and give their stock back.';

    public function handle(): int
    {
        $domain = (string) $this->option('tenant');
        $date = (string) $this->option('date');
        if ($domain === '' || $date === '' || ! strtotime($date)) {
            $this->error('Both --tenant and a valid --date (Y-m-d) are required.');

            return self::FAILURE;
        }
        $day = [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()];

        $org = Org::where('domain', $domain)->first();
        if (! $org) {
            $this->error("No tenant found for domain [{$domain}].");

            return self::FAILURE;
        }
        if (! app()->environment('testing')) {
            TenantManager::tenant($org)->reconnect();
        }

        $duplicates = $this->duplicateDeliveries($day);
        if ($duplicates->isEmpty()) {
            $this->info('No order has more than one delivery created on ' . $day[0]->toDateString() . '.');

            return self::SUCCESS;
        }

        $orderIds = $duplicates->pluck('order_id')->unique()->values();
        $statusIds = $this->extraDeliveredStatusIds($orderIds, $day);
        $stockChanges = $this->stockChanges($duplicates);

        $this->renderPlan($duplicates, $orderIds, $statusIds, $stockChanges);

        if (! $this->option('execute')) {
            $this->warn('DRY RUN — nothing was written. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        $deliveryIds = $duplicates->pluck('id')->all();
        DB::transaction(function () use ($deliveryIds, $statusIds, $stockChanges) {
            foreach ($stockChanges as $change) {
                Stock::whereKey($change['stock_id'])->lockForUpdate()->first()->increment('qty', $change['qty']);
            }
            DeliverySummary::whereIn('delivery_id', $deliveryIds)->delete();
            DeliveredProduct::whereIn('delivery_id', $deliveryIds)->delete();
            Delivery::whereIn('id', $deliveryIds)->delete();
            OrderStatus::whereIn('id', $statusIds)->delete();
        });
        $mongoDeleted = MongoDeliverySummary::whereIn('delivery_id', $deliveryIds)->delete();

        $this->info(sprintf(
            'Done. Deleted %d deliveries, %d delivered statuses and %d Mongo summary rows; gave stock back on %d stock rows.',
            count($deliveryIds),
            $statusIds->count(),
            $mongoDeleted,
            $stockChanges->count()
        ));
        $this->warn('Re-run the day\'s other delivery summaries (e.g. tenant:kpi_summary, tenant:user_sales_summary) for --date so their totals drop the duplicates.');

        Log::info('tenant:cleanup-duplicate-deliveries executed', [
            'tenant' => $domain,
            'date' => $day[0]->toDateString(),
            'deleted_delivery_ids' => $deliveryIds,
            'deleted_status_ids' => $statusIds->all(),
            'stock_changes' => $stockChanges->all(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Deliveries created on the day for an order that already has an earlier delivery.
     */
    private function duplicateDeliveries(array $day): Collection
    {
        $orderIds = Delivery::whereBetween('created_at', $day)
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id');
        if ($orderIds->isEmpty()) {
            return collect();
        }
        $keepIds = Delivery::whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->selectRaw('MIN(id) AS id')
            ->pluck('id');

        return Delivery::whereIn('order_id', $orderIds)
            ->whereNotIn('id', $keepIds)
            ->whereBetween('created_at', $day)
            ->with(['products', 'order:id,is_requisition'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Every 'delivered' status of these orders created on the day, except each order's first one.
     */
    private function extraDeliveredStatusIds(Collection $orderIds, array $day): Collection
    {
        $firstIds = OrderStatus::whereIn('order_id', $orderIds)
            ->where('status', OrderStatus::STATUS_DELIVERED)
            ->groupBy('order_id')
            ->selectRaw('MIN(id) AS id')
            ->pluck('id');

        return OrderStatus::whereIn('order_id', $orderIds)
            ->where('status', OrderStatus::STATUS_DELIVERED)
            ->whereBetween('created_at', $day)
            ->whereNotIn('id', $firstIds)
            ->pluck('id');
    }

    /**
     * Reverse of DeliveredProduct::updateStock() for the duplicates, summed per stock row:
     * the seller gets the qty back; for a requisition the buyer loses it again.
     */
    private function stockChanges(Collection $duplicates): Collection
    {
        $changes = [];
        foreach ($duplicates as $delivery) {
            foreach ($delivery->products as $line) {
                $this->addChange($changes, $delivery->seller_id, $line->product_id, (float) $line->qty);
                if ($delivery->order?->is_requisition == 1) {
                    $this->addChange($changes, $delivery->buyer_id, $line->product_id, -(float) $line->qty);
                }
            }
        }

        return collect($changes)->map(function ($change) {
            $stock = Stock::where('department_id', $change['department_id'])
                ->where('product_id', $change['product_id'])
                ->first();
            if (! $stock) {
                throw new \RuntimeException("No stock row for department {$change['department_id']}, product {$change['product_id']}.");
            }

            return $change + ['stock_id' => $stock->id, 'current_qty' => (float) $stock->qty, 'new_qty' => (float) $stock->qty + $change['qty']];
        })->values();
    }

    private function addChange(array &$changes, int $departmentId, int $productId, float $qty): void
    {
        $key = "{$departmentId}:{$productId}";
        $changes[$key] ??= ['department_id' => $departmentId, 'product_id' => $productId, 'qty' => 0.0];
        $changes[$key]['qty'] += $qty;
    }

    private function renderPlan(Collection $duplicates, Collection $orderIds, Collection $statusIds, Collection $stockChanges): void
    {
        $this->info(sprintf(
            '%d orders have duplicates: %d extra deliveries, %d product lines, %d extra delivered statuses.',
            $orderIds->count(),
            $duplicates->count(),
            $duplicates->sum(fn ($d) => $d->products->count()),
            $statusIds->count()
        ));
        $this->table(
            ['Order', 'Extra delivery ids'],
            $duplicates->groupBy('order_id')->map(fn ($rows, $orderId) => [$orderId, $rows->pluck('id')->implode(', ')])->values()->all()
        );
        $this->table(
            ['Stock id', 'Department', 'Product', 'Current qty', 'Change', 'New qty'],
            $stockChanges->map(fn ($c) => [$c['stock_id'], $c['department_id'], $c['product_id'], $c['current_qty'], $c['qty'], $c['new_qty']])->all()
        );
    }
}
