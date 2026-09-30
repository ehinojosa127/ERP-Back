<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderService;
use Illuminate\Console\Command;

class ExpireRegisteredOrdersCommand extends Command
{
    protected $signature = 'orders:expire-registered';

    protected $description = 'Cancela pedidos REGISTERED que superaron el TTL configurado (red de seguridad del job diferido).';

    public function handle(OrderService $orders): int
    {
        $count = $orders->expireStaleRegisteredOrders();

        $this->info("Pedidos REGISTERED expirados: {$count}");

        return self::SUCCESS;
    }
}
