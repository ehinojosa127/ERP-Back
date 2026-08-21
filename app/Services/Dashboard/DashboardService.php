<?php

namespace App\Services\Dashboard;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Support\Orders\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const LOW_STOCK_THRESHOLD = 5;

    private const TOP_PRODUCTS_LIMIT = 5;

    private const MONTHS = 6;

    public function summary(): array
    {
        $from = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        return [
            'kpis' => $this->kpis($from),
            'income_vs_expense' => $this->incomeVsExpense($from),
            'orders_by_status' => $this->ordersByStatus(),
            'purchases_by_status' => $this->purchasesByStatus(),
            'top_selling_products' => $this->topSellingProducts($from),
            'low_stock_products' => $this->lowStockProducts(),
            'billing' => app(\App\Services\Billing\BillingQueryService::class)->dashboardSummary(),
        ];
    }

    /** @return array<string, float|int> */
    private function kpis(Carbon $from): array
    {
        $income = (float) OrderPayment::query()
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $expense = (float) PurchasePayment::query()
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $openOrders = Order::query()
            ->whereNotIn('status', [OrderStatus::CLOSED, OrderStatus::CANCELLED])
            ->count();

        $lowStock = Product::query()
            ->select('products.id')
            ->selectSub(Product::stockSubquery(), 'stock')
            ->get()
            ->filter(fn (Product $p) => (int) $p->stock <= self::LOW_STOCK_THRESHOLD)
            ->count();

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'net' => round($income - $expense, 2),
            'open_orders' => $openOrders,
            'low_stock_count' => $lowStock,
        ];
    }

    /** @return array<int, array{period: string, income: float, expense: float}> */
    private function incomeVsExpense(Carbon $from): array
    {
        $driver = DB::connection()->getDriverName();
        $periodExpr = match ($driver) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };

        $incomeRows = OrderPayment::query()
            ->selectRaw("{$periodExpr} as period, COALESCE(SUM(amount), 0) as total")
            ->where('created_at', '>=', $from)
            ->groupBy('period')
            ->pluck('total', 'period');

        $expenseRows = PurchasePayment::query()
            ->selectRaw("{$periodExpr} as period, COALESCE(SUM(amount), 0) as total")
            ->where('created_at', '>=', $from)
            ->groupBy('period')
            ->pluck('total', 'period');

        $series = [];
        $cursor = $from->copy();

        for ($i = 0; $i < self::MONTHS; $i++) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'period' => $key,
                'income' => round((float) ($incomeRows[$key] ?? 0), 2),
                'expense' => round((float) ($expenseRows[$key] ?? 0), 2),
            ];
            $cursor->addMonth();
        }

        return $series;
    }

    /** @return array<int, array{status: string, count: int}> */
    private function ordersByStatus(): array
    {
        $counts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(OrderStatus::values())
            ->map(fn (string $status) => [
                'status' => $status,
                'count' => (int) ($counts[$status] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{status: string, count: int}> */
    private function purchasesByStatus(): array
    {
        $counts = Purchase::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return $counts->map(fn ($count, $status) => [
            'status' => (string) $status,
            'count' => (int) $count,
        ])->values()->all();
    }

    /** @return array<int, array{product_id: int, product_name: string, quantity: int}> */
    private function topSellingProducts(Carbon $from): array
    {
        return OrderDetail::query()
            ->selectRaw('COALESCE(product_id, 0) as product_id, product_name, SUM(quantity) as quantity')
            ->whereHas(
                'order',
                fn ($q) => $q
                    ->where('created_at', '>=', $from)
                    ->where('status', '!=', OrderStatus::CANCELLED),
            )
            ->whereNotNull('product_id')
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('quantity')
            ->limit(self::TOP_PRODUCTS_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'quantity' => (int) $row->quantity,
            ])
            ->all();
    }

    /** @return array<int, array{id: int, name: string, sku: string, stock: int}> */
    private function lowStockProducts(): array
    {
        return Product::query()
            ->select('products.id', 'products.name', 'products.sku')
            ->selectSub(Product::stockSubquery(), 'stock')
            ->orderBy('stock')
            ->limit(20)
            ->get()
            ->filter(fn (Product $product) => (int) $product->stock <= self::LOW_STOCK_THRESHOLD)
            ->take(self::TOP_PRODUCTS_LIMIT)
            ->values()
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
                'stock' => (int) $product->stock,
            ])
            ->all();
    }
}
