<?php

namespace Tests\Unit;

use App\Services\Zkteco\ZktecoDevice;
use PHPUnit\Framework\TestCase;

/**
 * The template-table layout is UNVERIFIED against this project's hardware, so
 * the decoder's contract is the thing worth pinning down: it either returns
 * records we can trust, or it returns [] meaning "unknown". It must never return
 * a partial or plausible-looking miscount, because a wrong "0 fingerprints"
 * silently tells an operator someone is unenrolled when they are not.
 */
class FingerprintTemplateDecodingTest extends TestCase
{
    /** Builds a template table in the layout decodeTemplateRecords expects. */
    private function table(array $records, int $blobLength = 10): string
    {
        $body = '';
        foreach ($records as [$uid, $fid]) {
            $blob = str_repeat("\xAB", $blobLength);
            $body .= pack('v', 6 + strlen($blob)) . pack('v', $uid) . chr($fid) . chr(1) . $blob;
        }

        return pack('V', strlen($body)) . $body;
    }

    public function test_it_decodes_uid_and_finger_index_per_record(): void
    {
        $decoded = ZktecoDevice::decodeTemplateRecords($this->table([[7, 0], [7, 3], [9, 5]]));

        $this->assertCount(3, $decoded);
        $this->assertSame(['uid' => 7, 'fid' => 0], $decoded[0]);
        $this->assertSame(['uid' => 7, 'fid' => 3], $decoded[1]);
        $this->assertSame(['uid' => 9, 'fid' => 5], $decoded[2]);
    }

    public function test_it_reads_every_finger_slot_zero_through_nine(): void
    {
        $records = [];
        foreach (range(0, 9) as $slot) {
            $records[] = [4, $slot];
        }

        $decoded = ZktecoDevice::decodeTemplateRecords($this->table($records));

        $this->assertSame(range(0, 9), array_column($decoded, 'fid'));
    }

    public function test_a_finger_index_outside_zero_to_nine_abandons_the_whole_read(): void
    {
        // 11 is not a valid finger slot: the layout assumption is wrong, so
        // nothing decoded so far can be trusted.
        $this->assertSame([], ZktecoDevice::decodeTemplateRecords($this->table([[7, 0], [7, 11]])));
    }

    public function test_a_record_claiming_to_overrun_the_buffer_abandons_the_read(): void
    {
        $body = pack('v', 4096) . pack('v', 7) . chr(0) . chr(1) . 'short';

        $this->assertSame([], ZktecoDevice::decodeTemplateRecords(pack('V', strlen($body)) . $body));
    }

    public function test_a_record_too_small_for_its_own_header_abandons_the_read(): void
    {
        $body = pack('v', 2) . pack('v', 7) . chr(0) . chr(1);

        $this->assertSame([], ZktecoDevice::decodeTemplateRecords(pack('V', strlen($body)) . $body));
    }

    public function test_a_truncated_table_decodes_to_unknown(): void
    {
        $this->assertSame([], ZktecoDevice::decodeTemplateRecords(''));
        $this->assertSame([], ZktecoDevice::decodeTemplateRecords("\x01\x02"));
    }

    public function test_trailing_bytes_abandon_the_read_rather_than_undercount(): void
    {
        // A record walk that doesn't land exactly on the end of the body means the
        // buffer was truncated or misaligned. Reporting the records decoded so far
        // would undercount somebody's fingers, which is worse than "unknown".
        $table = $this->table([[7, 0], [7, 3]]);

        $this->assertSame([], ZktecoDevice::decodeTemplateRecords($table . "\x00\x00\x00\x00\x00\x00\x00"));
    }

    /**
     * Real 22,066-byte template table captured from the IT Room terminal
     * (ZKTeco @ 192.168.7.62) via `zkteco:probe-templates`. This is the
     * regression guard that the layout assumption actually holds on the
     * hardware this project runs against.
     */
    public function test_it_decodes_the_real_device_template_table(): void
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/zkteco/fingerprint-template-table.bin');

        $this->assertSame(22066, strlen($raw), 'fixture changed size');

        $records = ZktecoDevice::decodeTemplateRecords($raw);

        $this->assertCount(19, $records, 'the real table holds 19 templates');

        $byUid = [];
        foreach ($records as $record) {
            $byUid[$record['uid']][] = $record['fid'];
        }
        foreach ($byUid as $uid => $fids) {
            sort($fids);
            $byUid[$uid] = $fids;
        }

        // Cross-checked against a per-slot CMD_USERTEMP_RRQ probe of the same
        // device: uid 8 answered only for fid 6, and with a 1304-byte template
        // matching this record's size.
        $this->assertSame([6], $byUid[8], 'uid 8 has exactly one template, in slot 6');
        $this->assertSame([6, 7], $byUid[1]);
        $this->assertSame([3, 6], $byUid[2]);
        $this->assertSame([1, 6], $byUid[15]);
        $this->assertSame([2], $byUid[19]);

        // 13 of the 19 enrolled users have at least one finger on file.
        $this->assertCount(13, $byUid);

        // Every finger index must be a real slot.
        foreach ($records as $record) {
            $this->assertGreaterThanOrEqual(0, $record['fid']);
            $this->assertLessThanOrEqual(9, $record['fid']);
        }
    }
}
