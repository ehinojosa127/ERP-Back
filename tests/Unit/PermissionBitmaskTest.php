<?php

namespace Tests\Unit;

use App\Support\Auth\PermissionBitmask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermissionBitmaskTest extends TestCase
{
    /**
     * @return array<string, array{0: array<int, int>, 1: string}>
     */
    public static function encodeCases(): array
    {
        return [
            'empty' => [[], '0'],
            'single' => [[1], '2'],
            'example docs' => [[1, 3, 5], '2a'],
            'consecutive' => [[1, 2, 3, 4], '1e'],
            'sparse high' => [[1, 5, 10, 47], '800000000422'],
            'legacy subset' => [[5, 6, 7, 12], '10e0'],
        ];
    }

    /**
     * @param  array<int, int>  $ids
     */
    #[DataProvider('encodeCases')]
    public function test_encode_matches_expected_hex(array $ids, string $expected): void
    {
        $this->assertSame($expected, PermissionBitmask::encode($ids));
    }

    /**
     * @param  array<int, int>  $ids
     */
    #[DataProvider('encodeCases')]
    public function test_round_trip(array $ids, string $_expected): void
    {
        $decoded = PermissionBitmask::decode(PermissionBitmask::encode($ids));

        $this->assertSame($ids, $decoded);
    }

    public function test_all_permissions_1_to_47(): void
    {
        $ids = range(1, 47);
        $decoded = PermissionBitmask::decode(PermissionBitmask::encode($ids));

        $this->assertSame($ids, $decoded);
    }

    public function test_empty_mask_does_not_grant_permissions(): void
    {
        $this->assertSame([], PermissionBitmask::decode('0'));
        $this->assertSame([], PermissionBitmask::decode(''));
    }
}
