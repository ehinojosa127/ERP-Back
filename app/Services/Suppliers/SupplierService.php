<?php

namespace App\Services\Suppliers;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit\AuditUserPresenter;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use App\Support\Suppliers\SupplierKind;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SupplierService
{
    private const SEARCHABLE_COLUMNS = [
        'name',
        'lastname',
        'company_name',
        'ruc',
        'dni',
        'phone_number',
        'city',
    ];

    public function list(ListQuery $query, ?string $kind = null): LengthAwarePaginator
    {
        $builder = Supplier::query()->orderBy('company_name')->orderBy('lastname');

        if ($kind !== null) {
            $this->applyKindFilter($builder, $kind);
        }

        $paginator = SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
        $paginator->setCollection(
            AuditUserPresenter::presentMany($paginator->getCollection()),
        );

        return $paginator;
    }

    private function applyKindFilter(Builder $builder, string $kind): void
    {
        if ($kind === SupplierKind::COMPANY) {
            $builder->where(
                fn (Builder $inner) => $inner
                    ->whereNotNull('company_name')
                    ->orWhereNotNull('ruc'),
            );

            return;
        }

        $builder->whereNull('company_name')->whereNull('ruc');
    }

    /**
     * @return array<string, mixed>
     */
    public function find(Supplier $supplier): array
    {
        return AuditUserPresenter::present($supplier);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(array $data, User $author): array
    {
        $supplier = Supplier::query()->create([
            ...$this->attributes($data),
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);

        return AuditUserPresenter::present($supplier);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(Supplier $supplier, array $data, User $author): array
    {
        $supplier->update([
            ...$this->attributes($data),
            'updated_by' => $author->id,
        ]);

        return AuditUserPresenter::present($supplier->fresh() ?? $supplier);
    }

    public function delete(Supplier $supplier): void
    {
        if (Purchase::query()->where('supplier_id', $supplier->id)->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar este proveedor porque tiene compras registradas.',
            );
        }

        $supplier->delete();
    }

    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'lastname' => $data['lastname'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'ruc' => $data['ruc'] ?? null,
            'dni' => $data['dni'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'city' => $data['city'] ?? null,
        ];
    }
}
