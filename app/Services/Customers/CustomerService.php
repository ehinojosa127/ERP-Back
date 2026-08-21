<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit\AuditUserPresenter;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CustomerService
{
    private const SEARCHABLE_COLUMNS = [
        'name',
        'lastname',
        'dni',
        'ruc',
        'legal_name',
        'phone_number',
        'city',
        'agency_destination',
    ];

    public function list(ListQuery $query): LengthAwarePaginator
    {
        $paginator = SearchablePaginator::paginate(
            Customer::query()->orderBy('lastname')->orderBy('name'),
            $query,
            self::SEARCHABLE_COLUMNS,
        );

        $paginator->setCollection(
            AuditUserPresenter::presentMany($paginator->getCollection()),
        );

        return $paginator;
    }

    /**
     * @return array<string, mixed>
     */
    public function find(Customer $customer): array
    {
        return AuditUserPresenter::present($customer);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(array $data, User $author): array
    {
        $customer = Customer::query()->create([
            ...$this->attributes($data),
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);

        return AuditUserPresenter::present($customer);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(Customer $customer, array $data, User $author): array
    {
        $customer->update([
            ...$this->attributes($data),
            'updated_by' => $author->id,
        ]);

        return AuditUserPresenter::present($customer->fresh() ?? $customer);
    }

    public function delete(Customer $customer): void
    {
        if (Order::query()->where('customer_id', $customer->id)->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar este cliente porque tiene pedidos registrados.',
            );
        }

        $customer->delete();
    }

    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'lastname' => $data['lastname'],
            'dni' => $data['dni'] ?? null,
            'ruc' => $data['ruc'] ?? null,
            'legal_name' => $data['legal_name'] ?? null,
            'address' => $data['address'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'city' => $data['city'] ?? null,
            'agency_destination' => $data['agency_destination'] ?? null,
        ];
    }
}
