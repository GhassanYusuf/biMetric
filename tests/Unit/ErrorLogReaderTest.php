<?php

namespace Tests\Unit;

use App\Support\ErrorLogReader;
use Tests\TestCase;

/** The Error logs screen's reader (2026-11-09), on a log written the way Monolog writes it. */
class ErrorLogReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.timezone' => '+03:00', 'app.timezone' => 'UTC']);
        $this->dir = sys_get_temp_dir().'/errlog-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/laravel.log', implode("\n", [
            '[2026-11-08 07:00:00] production.WARNING: Device operation failed {"device_id":20,"device_name":"City Center Hotel","endpoint":"192.168.21.254:4370"} ',
            '[2026-11-08 07:05:00] production.WARNING: Device operation failed {"device_id":20,"device_name":"City Center Hotel","endpoint":"192.168.21.254:4370"} ',
            '[2026-11-08 08:00:00] testing.ERROR: from the test suite {"x":1} ',
            '[2026-11-08 09:00:00] production.ERROR: Undefined array key "role" {"exception":"[object] (ErrorException(code: 0): Undefined array key"} ',
            '#0 /var/www/zkt/app/Http/Controllers/Api/UserController.php(42): handle()',
            '#1 {main}',
            '[2026-11-08 10:00:00] production.INFO: Something routine ',
            '',
        ]));
        file_put_contents($this->dir.'/client-2026-11-08.log', '[2026-11-08 11:00:00] production.ERROR: SPA error: companies is not defined {"kind":"render","url":"https://zkt.takeone.bh/app/users","user_id":12} '."\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function reader(): ErrorLogReader
    {
        return new ErrorLogReader($this->dir);
    }

    public function test_it_reads_production_entries_only_newest_first_with_their_details(): void
    {
        $e = $this->reader()->entries(['from' => '2026-11-08', 'to' => '2026-11-08', 'level' => 'warning']);

        $this->assertSame(['SPA error: companies is not defined', 'Undefined array key "role"', 'Device operation failed', 'Device operation failed'], array_column($e, 'message'));
        $this->assertSame('browser', $e[0]['source']);
        $this->assertSame('/app/users', parse_url($e[0]['context']['url'], PHP_URL_PATH));
        $this->assertStringContainsString('UserController.php(42)', $e[1]['detail']);
        $this->assertSame('City Center Hotel', $e[2]['context']['device_name']);
    }

    public function test_level_source_search_and_dates_filter(): void
    {
        $r = $this->reader();
        $this->assertCount(2, $r->entries(['from' => '2026-11-08', 'to' => '2026-11-08', 'level' => 'error']));
        $this->assertCount(5, $r->entries(['from' => '2026-11-08', 'to' => '2026-11-08', 'level' => 'info']));
        $this->assertCount(1, $r->entries(['from' => '2026-11-08', 'to' => '2026-11-08', 'source' => 'browser']));
        $this->assertCount(2, $r->entries(['from' => '2026-11-08', 'to' => '2026-11-08', 'search' => 'city center']));
        $this->assertCount(0, $r->entries(['from' => '2026-11-07', 'to' => '2026-11-07']));
    }

    public function test_repeats_are_grouped_with_their_count_and_span(): void
    {
        $r = $this->reader();
        $groups = $r->grouped($r->entries(['from' => '2026-11-08', 'to' => '2026-11-08', 'source' => 'server']));

        $device = collect($groups)->firstWhere('message', 'Device operation failed');
        $this->assertSame(2, $device['count']);
        $this->assertSame('2026-11-08T07:00:00+00:00', $device['first_at']);
        $this->assertSame('2026-11-08T07:05:00+00:00', $device['last_at']);
        $this->assertCount(2, $groups);
    }

    public function test_a_random_client_id_does_not_split_a_group(): void
    {
        file_put_contents($this->dir.'/laravel.log', implode("\n", [
            '[2026-11-09 17:15:00] production.ERROR: MQTT [127.0.0.1:1883] [ac81f7e4e62666a3a3b2] Establishing a connection with the broker failed: Connection refused ',
            '[2026-11-09 17:20:00] production.ERROR: MQTT [127.0.0.1:1883] [025534a4384498388103] Establishing a connection with the broker failed: Connection refused ',
            '',
        ]));
        $r = $this->reader();
        $groups = $r->grouped($r->entries(['from' => '2026-11-09', 'to' => '2026-11-09', 'source' => 'server']));

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['count']);
    }
}
