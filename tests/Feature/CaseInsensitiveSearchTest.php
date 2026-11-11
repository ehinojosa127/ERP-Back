<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseInsensitiveSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_mixed_case_in_direct_and_related_columns(): void
    {
        $category = Category::query()->create(['name' => 'Ropa Escolar']);
        $product = Product::query()->create([
            'name' => 'Falda Negra 4p M',
            'sku' => 'SKU-00001',
            'sale_price' => 10,
            'category_id' => $category->id,
        ]);
        $otherCategory = Category::query()->create(['name' => 'Otro']);
        Product::query()->create([
            'name' => 'Falda Negra 4p M',
            'sku' => 'SKU-00002',
            'sale_price' => 10,
            'category_id' => $otherCategory->id,
        ]);

        foreach (['falda negra', 'FALDA NEGRA', 'FaLdA NeGrA', 'ropa escolar', 'ROPA ESCOLAR', 'sku-00001'] as $search) {
            $results = SearchablePaginator::paginate(
                Product::query()->where('category_id', $category->id),
                new ListQuery($search, 1, 10),
                ['name', 'sku', 'category.name'],
            );

            $this->assertSame(1, $results->total(), $search);
            $this->assertSame([$product->id], $results->pluck('id')->all(), $search);
        }
    }

    public function test_postgresql_search_uses_ilike_including_related_columns(): void
    {
        // Compile the production query without requiring a PostgreSQL server.
        config(['database.connections.search_pgsql' => [
            'driver' => 'pgsql',
            'database' => 'search_test',
            'prefix' => '',
        ]]);
        $builder = Product::on('search_pgsql')->where('category_id', 123);
        SearchablePaginator::applySearch($builder, 'FaLdA', ['name', 'category.name']);

        $sql = $builder->toSql();
        $this->assertSame(2, substr_count($sql, ' ilike '));
        $this->assertStringContainsString('"name"::text ilike ?', $sql);
        $this->assertStringContainsString('exists (select * from "categories"', $sql);
        $this->assertSame([123, '%FaLdA%', '%FaLdA%'], $builder->getBindings());
    }

    public function test_search_keeps_percent_and_underscore_literal_in_postgresql(): void
    {
        config(['database.connections.search_pgsql' => [
            'driver' => 'pgsql',
            'database' => 'search_test',
            'prefix' => '',
        ]]);
        $builder = Category::on('search_pgsql');
        SearchablePaginator::applySearch($builder, '50%_Azul', ['name']);

        $this->assertStringContainsString(' ilike ', $builder->toSql());
        $this->assertSame(['%50\\%\\_Azul%'], $builder->getBindings());
    }
}
