<?php

namespace Tests\Unit;

use App\Services\Devices\ZktecoDriver;
use PHPUnit\Framework\TestCase;

/**
 * The Identity panel's capacities (owner, 2026-11-18): read from GET_FREE_SIZES and kept only when the
 * reply checks itself (capacity − used = free for users, fingerprints and punches).
 */
class DeviceCapacitiesTest extends TestCase
{
    /** IT ROOM's real reply (F18, 192.168.7.62), tools/zkprobe/traces-2026-10-18/free-sizes-15-01.json. */
    private const IT_ROOM = '0000000000000000000000000000000007000000000000000a00000000000000442c00000000000035060000000000000200000002000000b80b000088130000a0860100ae0b0000811300005c5a0100000000000000000000000000';

    private function capacities(string $reply): array
    {
        $m = new \ReflectionMethod(ZktecoDriver::class, 'capacities');

        return $m->invoke(null, $reply);
    }

    public function test_the_f18s_real_reply_gives_its_capacities(): void
    {
        $this->assertSame(
            ['user_capacity' => 5000, 'fingerprint_capacity' => 3000, 'log_capacity' => 100000],
            $this->capacities(hex2bin(self::IT_ROOM)),
        );
    }

    public function test_a_reply_that_does_not_check_itself_gives_nothing(): void
    {
        $bytes = hex2bin(self::IT_ROOM);
        $bytes = substr_replace($bytes, pack('V', 12345), 19 * 4, 4); // records free no longer = capacity − used

        $this->assertSame([], $this->capacities($bytes));
        $this->assertSame([], $this->capacities(''));
    }
}
