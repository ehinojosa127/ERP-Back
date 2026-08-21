<?php

namespace App\Support\Query;

/**
 * Parámetros normalizados de cualquier listado de la API. Los controladores
 * los construyen desde la petición y los servicios solo reciben este objeto,
 * de modo que la capa de negocio no depende de la capa HTTP.
 */
final class ListQuery
{
    public function __construct(
        public readonly ?string $search,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public function hasSearch(): bool
    {
        return $this->search !== null && $this->search !== '';
    }
}
