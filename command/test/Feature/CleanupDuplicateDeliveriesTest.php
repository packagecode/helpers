<?php

namespace Tests\Feature;

use App\Models\Department\Department;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Order\DeliveredProduct;
use App\Models\Order\Delivery;
use App\Models\Order\Order;
use App\Models\Order\OrderStatus;
use Tests\TestCase;

class CleanupDuplicateDeliveriesTest extends TestCase
{
    private const DATE = '2026-10-14';

    private Order $order;
    private Stock $stock;
    private array $deliveries;
    private array $statuses;

    public function testDryRunReportsDuplicatesButWritesNothing()
    {
        $this->runCleanup()
            ->expectsOutputToContain('1 orders have duplicates: 2 extra deliveries')
            ->assertExitCode(0);

        $this->assertSame(3, Delivery::where('order_id', $this->order->id)->count());
        $this->assertEquals(70, $this->stock->fresh()->qty);
    }

    public function testExecuteKeepsTheFirstDeliveryAndGivesStockBack()
    {
        $this->runCleanup(['--execute' => true])->assertExitCode(0);

        $this->assertSame([$this->deliveries[0]->id], Delivery::where('order_id', $this->order->id)->pluck('id')->all());
        $this->assertSame(0, DeliveredProduct::whereIn('delivery_id', [$this->deliveries[1]->id, $this->deliveries[2]->id])->count());
        $this->assertSame(
            [$this->statuses[0]->id],
            OrderStatus::where('order_id', $this->order->id)->where('status', OrderStatus::STATUS_DELIVERED)->pluck('id')->all()
        );
        $this->assertEquals(90, $this->stock->fresh()->qty);
    }

    public function testOrdersWithASingleDeliveryAreLeftAlone()
    {
        Delivery::whereIn('id', [$this->deliveries[1]->id, $this->deliveries[2]->id])->delete();

        $this->runCleanup(['--execute' => true])
            ->expectsOutputToContain('No order has more than one delivery')
            ->assertExitCode(0);

        $this->assertEquals(70, $this->stock->fresh()->qty);
    }

    private function runCleanup(array $options = [])
    {
        return $this->artisan('tenant:cleanup-duplicate-deliveries', array_merge([
            '--tenant' => 'test',
            '--date' => self::DATE,
        ], $options));
    }

    /**
     * One order delivered three times in parallel: 10 qty each, so the seller's
     * stock went from 100 to 70 instead of 90.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $seller = factory(Department::class)->create();
        $product = factory(Product::class)->create();
        $this->order = factory(Order::class)->create(['seller_id' => $seller->id, 'is_requisition' => 0]);
        $this->stock = factory(Stock::class)->create([
            'department_id'   => $seller->id,
            'product_id'      => $product->id,
            'base_product_id' => $product->base_product_id,
            'batch_id'        => $product->batch_id,
            'qty'             => 70,
        ]);

        foreach ([0, 1, 2] as $i) {
            $this->deliveries[$i] = factory(Delivery::class)->create([
                'order_id'   => $this->order->id,
                'seller_id'  => $seller->id,
                'created_at' => self::DATE . ' 18:00:0' . $i,
            ]);
            factory(DeliveredProduct::class)->create([
                'delivery_id' => $this->deliveries[$i]->id,
                'product_id'  => $product->id,
                'qty'         => 10,
            ]);
            $this->statuses[$i] = factory(OrderStatus::class)->create([
                'order_id'   => $this->order->id,
                'status'     => OrderStatus::STATUS_DELIVERED,
                'created_at' => self::DATE . ' 18:00:0' . $i,
            ]);
        }
    }
}
