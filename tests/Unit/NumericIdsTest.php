<?php

namespace Tests\Unit;

use App\Http\Middleware\NumericIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Integration API answers carry ids as numbers (owner, 2026-11-02). SQL Server returns bigints
 * as text, which the test database (SQLite) never does, so the shape is pinned here directly.
 */
class NumericIdsTest extends TestCase
{
    private function through(array $data): array
    {
        return (new NumericIds)->handle(new Request, fn () => new JsonResponse($data))->getData(true);
    }

    public function test_ids_from_sql_server_become_numbers(): void
    {
        $out = $this->through(['data' => [
            'id' => 53, 'employee_id' => '1829', 'device_id' => '15', 'device_command_id' => '18998',
            'access_level_ids' => ['3', '4'], 'company' => ['id' => '4', 'name' => 'PROMOSEVEN'],
            'items' => [['id' => '7', 'tenant_id' => '1']],
        ], 'meta' => ['next_after_id' => '90211']]);

        $this->assertSame(1829, $out['data']['employee_id']);
        $this->assertSame(15, $out['data']['device_id']);
        $this->assertSame(18998, $out['data']['device_command_id']);
        $this->assertSame([3, 4], $out['data']['access_level_ids']);
        $this->assertSame(4, $out['data']['company']['id']);
        $this->assertSame(7, $out['data']['items'][0]['id']);
        $this->assertSame(90211, $out['meta']['next_after_id']);
        $this->assertSame(53, $out['data']['id']);
    }

    public function test_text_ids_and_other_fields_stay_as_they_are(): void
    {
        $out = $this->through(['data' => [
            'external_id' => '1', 'device_user_id' => '0099902', 'employee_code' => '90110121',
            'card_number' => '2857525', 'name' => '15', 'employee_id' => null, 'session_id' => 'abc',
            'leading_id' => '007',
        ]]);

        $this->assertSame('1', $out['data']['external_id']);
        $this->assertSame('0099902', $out['data']['device_user_id']);
        $this->assertSame('90110121', $out['data']['employee_code']);
        $this->assertSame('2857525', $out['data']['card_number']);
        $this->assertSame('15', $out['data']['name']);
        $this->assertNull($out['data']['employee_id']);
        $this->assertSame('abc', $out['data']['session_id']);
        $this->assertSame('007', $out['data']['leading_id'], 'a leading zero is not a number');
    }
}
