<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the mechanical half of CLAUDE.md rule 20: docs/device-casebook.md, the
 * history of every device problem — what was seen, what was done, the cause, the fix
 * and the proof — so the next investigation starts from what is already known.
 *
 * It cannot check that a problem was logged at all. That part is discipline.
 */
class DeviceCasebookTest extends TestCase
{
    private const FIELDS = [
        'Date', 'Devices', 'Direction', 'Symptom', 'What was done',
        'Root cause', 'Fix', 'Verified', 'Evidence', 'Status', 'Keywords',
    ];

    private const STATUSES = ['FIXED', 'WORKAROUND', 'MONITORING', 'OPEN'];

    private static function casebook(): string
    {
        $path = dirname(__DIR__, 2).'/docs/device-casebook.md';
        self::assertFileExists($path, 'docs/device-casebook.md is required by CLAUDE.md rule 20');

        return (string) file_get_contents($path);
    }

    /** @return array<string,string> id => entry body */
    private static function entries(): array
    {
        $doc = self::casebook();
        $start = strpos($doc, "\n## Entries");
        self::assertNotFalse($start, 'the casebook needs an "## Entries" section');
        $end = strpos($doc, "\n## ", $start + 5);
        $section = substr($doc, $start, $end === false ? null : $end - $start);

        preg_match_all('/^### (DC-\d{3}) — .+$/m', $section, $m, PREG_OFFSET_CAPTURE);
        $entries = [];
        foreach ($m[1] as $i => [$id, $offset]) {
            $next = $m[0][$i + 1][1] ?? strlen($section);
            $entries[$id] = substr($section, $offset, $next - $offset);
        }

        return $entries;
    }

    public function test_the_casebook_is_dated(): void
    {
        $this->assertMatchesRegularExpression('/\*\*Last updated: \d{4}-\d{2}-\d{2}\*\*/', self::casebook());
    }

    public function test_every_entry_has_every_field(): void
    {
        $entries = self::entries();
        $this->assertNotEmpty($entries);

        foreach ($entries as $id => $body) {
            foreach (self::FIELDS as $field) {
                $this->assertMatchesRegularExpression(
                    '/^- \*\*'.preg_quote($field, '/').':\*\* \S/m',
                    $body,
                    "{$id} is missing \"{$field}\"",
                );
            }
        }
    }

    public function test_dates_are_absolute_and_statuses_known(): void
    {
        foreach (self::entries() as $id => $body) {
            $this->assertMatchesRegularExpression('/^- \*\*Date:\*\* \d{4}-\d{2}-\d{2}/m', $body, "{$id}: Date must start YYYY-MM-DD");
            preg_match('/^- \*\*Status:\*\* (\S+)/m', $body, $s);
            $this->assertContains(rtrim($s[1] ?? '', '.,'), self::STATUSES, "{$id}: Status must be one of ".implode(', ', self::STATUSES));
        }
    }

    public function test_ids_are_unique_and_newest_first(): void
    {
        $ids = array_keys(self::entries());
        $numbers = array_map(fn ($id) => (int) substr($id, 3), $ids);

        $sorted = $numbers;
        rsort($sorted);
        $this->assertSame($sorted, $numbers, 'entries must be newest first');
        $this->assertSame(count($numbers), count(array_unique($numbers)));
    }

    public function test_the_index_lists_every_entry(): void
    {
        $doc = self::casebook();
        $index = substr($doc, (int) strpos($doc, '## Index'), (int) strpos($doc, "\n## Entries") - (int) strpos($doc, '## Index'));

        foreach (array_keys(self::entries()) as $id) {
            $this->assertMatchesRegularExpression('/^\| '.$id.' \|/m', $index, "{$id} is not in the index");
        }
    }

    public function test_claude_md_carries_rule_20(): void
    {
        $rules = (string) file_get_contents(dirname(__DIR__, 2).'/CLAUDE.md');
        $this->assertStringContainsString('## 20. ', $rules);
        $this->assertStringContainsString('docs/device-casebook.md', $rules);
    }
}
