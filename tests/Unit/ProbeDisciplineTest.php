<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces what can be enforced of CLAUDE.md rule 16: learning a device on the wire.
 *
 * When a model has no SDK and no documentation, the only way in is to speak the
 * known protocol at it and read what comes back. That work is legitimate and this
 * project has already done it — but it runs against live terminals holding real
 * employees' fingerprints, so three things must hold mechanically:
 *
 *   1. a script says up front whether it can change the device
 *   2. a trace says what it is, so a conclusion can be traced to evidence
 *   3. no raw packet capture is committed, because a real exchange carries names,
 *      card numbers and biometric templates
 *
 * What this cannot check is the part that makes a finding true: that a control run
 * against the F18 happened alongside, and that the result reproduced. That part is
 * discipline, and rule 16 says so rather than pretending otherwise.
 */
class ProbeDisciplineTest extends TestCase
{
    private const PROBE_DIR = 'tools/zkprobe';

    private function root(string $suffix = ''): string
    {
        return dirname(__DIR__, 2).'/'.$suffix;
    }

    /** @return list<string> */
    private function glob(string $pattern): array
    {
        $hits = glob($this->root($pattern)) ?: [];
        sort($hits);

        return $hits;
    }

    private function relative(string $path): string
    {
        return str_replace($this->root(), '', $path);
    }

    /**
     * Rule 16's safety line.
     *
     * A read can be repeated forever. A write can destroy an enrollment that only
     * exists because somebody stood at the terminal and pressed their finger on it
     * eight times. Whoever runs one of these scripts must be able to tell which it
     * is from the top of the file, not by reading to the end.
     */
    public function test_every_probe_script_declares_its_blast_radius(): void
    {
        $scripts = $this->glob(self::PROBE_DIR.'/*.php');

        if ($scripts === []) {
            $this->markTestSkipped(
                self::PROBE_DIR.' holds no scripts on this machine. It is untracked '
                .'scratch by design — see the housekeeping note in docs/PROJECT-STATE.md.'
            );
        }

        $undeclared = [];

        foreach ($scripts as $path) {
            // The declaration belongs in the header, where it is read before the
            // script is run — not buried beside the command that does the writing.
            $header = substr((string) file_get_contents($path), 0, 4000);

            if (! preg_match('/READ-ONLY|WRITES TO THE DEVICE/', $header)) {
                $undeclared[] = $this->relative($path);
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            "A probe script does not declare its blast radius (CLAUDE.md rule 16).\n"
            ."Put READ-ONLY in the header — and say why each command it sends is safe —\n"
            ."or WRITES TO THE DEVICE with what it writes and how to undo it.\n\n"
            ."These run against live terminals holding real enrollments. Whoever runs\n"
            ."one must know which it is before it sends its first packet.\n\n"
            .implode("\n", $undeclared)."\n"
        );
    }

    /**
     * A trace that cannot say which device it came off, or when, is not evidence.
     *
     * Folders get renamed and files get copied to a laptop. The three keys travel
     * with the file.
     */
    public function test_every_trace_says_what_it_is(): void
    {
        $traces = $this->glob(self::PROBE_DIR.'/traces*/*.json');

        if ($traces === []) {
            $this->markTestSkipped('No captured traces on this machine.');
        }

        $problems = [];

        foreach ($traces as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded)) {
                $problems[] = $this->relative($path).'  — not valid JSON';

                continue;
            }

            $missing = array_values(array_filter(
                ['label', 'host', 'started_at'],
                fn (string $key) => ! array_key_exists($key, $decoded),
            ));

            if ($missing !== []) {
                $problems[] = $this->relative($path).'  — missing: '.implode(', ', $missing);
            }
        }

        $this->assertSame(
            [],
            $problems,
            "A probe trace is not self-describing (CLAUDE.md rule 16).\n"
            ."Every trace carries `label`, `host` and `started_at`, so a finding can be\n"
            ."traced back to the terminal and the run that produced it.\n\n"
            .implode("\n", $problems)."\n"
        );
    }

    /**
     * Rule 16's privacy line.
     *
     * Capturing the vendor's own app talking to a terminal is the fastest way to
     * learn a protocol, and the resulting capture contains employees' names, card
     * numbers and fingerprint templates. The decoded structure is what belongs in
     * the repository. The capture itself does not.
     */
    public function test_no_raw_packet_capture_is_committed(): void
    {
        $tracked = [];
        $output = [];

        exec(
            'git -C '.escapeshellarg($this->root()).' ls-files 2>/dev/null',
            $output,
        );

        foreach ($output as $file) {
            if (preg_match('/\.(pcap|pcapng|cap)$/i', $file)) {
                $tracked[] = $file;
            }
        }

        $this->assertSame(
            [],
            $tracked,
            "A raw packet capture is committed (CLAUDE.md rule 16).\n"
            ."A real exchange with a terminal carries employee names, card numbers and\n"
            ."fingerprint templates. Commit the decoded structure, the offsets and the\n"
            ."reply codes, plus a redacted or synthetic fixture — never the capture.\n\n"
            .implode("\n", $tracked)."\n"
        );
    }
}
