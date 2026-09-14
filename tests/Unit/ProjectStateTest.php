<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces CLAUDE.md rule 14: the state of the work lives in the repository.
 *
 * Assistant memory is per-machine and does not travel. `docs/PROJECT-STATE.md` is
 * what a second machine, a fresh clone or a different assistant actually gets, so
 * the structure that makes it readable is worth a test.
 *
 * What this CANNOT check is whether the contents are true or current — a stale
 * state file passes every assertion here. That part is discipline, and rule 14
 * says so out loud rather than pretending otherwise.
 */
class ProjectStateTest extends TestCase
{
    private const PATH = 'docs/PROJECT-STATE.md';

    private function contents(): string
    {
        $path = dirname(__DIR__, 2).'/'.self::PATH;

        $this->assertFileExists(
            $path,
            self::PATH." is missing.\n"
            ."CLAUDE.md rule 14: it is the answer to \"where were we?\" and the only\n"
            ."part of a session that survives a change of machine.\n"
        );

        return file_get_contents($path);
    }

    public function test_the_state_file_exists_and_is_not_a_stub(): void
    {
        $body = $this->contents();

        // A placeholder passes a "file exists" check and helps nobody.
        $this->assertGreaterThan(
            2000,
            strlen($body),
            self::PATH.' looks like a stub. It has to carry enough for somebody to '
            .'resume the work without asking.',
        );
    }

    public function test_the_state_file_is_dated(): void
    {
        $body = $this->contents();

        $this->assertMatchesRegularExpression(
            '/Last updated:\s*\*{0,2}\s*\d{4}-\d{2}-\d{2}/',
            $body,
            'No `Last updated: YYYY-MM-DD` in '.self::PATH.".\n"
            ."A state file with no date cannot be trusted, and an ISO date is the only\n"
            ."form that survives being read in another timezone six months later.\n",
        );
    }

    public function test_the_state_file_carries_every_required_section(): void
    {
        $body = $this->contents();

        /*
         * Matched case-insensitively against the whole file rather than against
         * heading syntax, so the file stays free to reorganise its own headings.
         * The point is that each CONCERN is addressed somewhere.
         */
        $required = [
            'where we stopped' => 'what is done, what is next, what is blocked',
            'session log' => 'dated entries, newest first',
            'open question' => 'what only the owner can decide',
            'operational' => 'ports, env quirks, device addresses, known traps',
            'debt' => 'what is cheaper to record than to rediscover',
        ];

        $missing = [];

        foreach ($required as $needle => $why) {
            if (! str_contains(mb_strtolower($body), $needle)) {
                $missing[] = sprintf('"%s" — %s', $needle, $why);
            }
        }

        $this->assertSame(
            [],
            $missing,
            self::PATH." is missing required sections (CLAUDE.md rule 14):\n\n"
            .implode("\n", $missing)."\n",
        );
    }

    /**
     * Rule 14's sharpest requirement: the file must say what has not left this
     * machine. Documenting work that is sitting in one working tree, without
     * saying so, is worse than not documenting it — the reader believes a clone
     * elsewhere contains it.
     */
    public function test_the_state_file_addresses_what_is_unpushed(): void
    {
        $body = mb_strtolower($this->contents());

        $mentionsSyncState = str_contains($body, 'uncommitted')
            || str_contains($body, 'not committed')
            || str_contains($body, 'unpushed')
            || str_contains($body, 'not pushed');

        $this->assertTrue(
            $mentionsSyncState,
            self::PATH." never says what is committed and pushed.\n"
            ."Rule 14: state the sync position explicitly. A reader on another machine\n"
            ."otherwise assumes everything described here is available to them.\n",
        );
    }

    /**
     * Relative dates rot the moment they are written. "Fixed it yesterday" is
     * meaningless to the person reading three months later, which is exactly who
     * this file is for.
     */
    public function test_the_session_log_uses_absolute_dates(): void
    {
        $body = $this->contents();

        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}/',
            $body,
            'No ISO date anywhere in '.self::PATH.'.',
        );

        $offenders = [];
        $banned = ['yesterday', 'last week', 'this morning', 'a few days ago', 'last month'];

        foreach (explode("\n", $body) as $number => $line) {
            $lower = mb_strtolower($line);

            foreach ($banned as $phrase) {
                // The rule text itself names these phrases in order to ban them.
                if (str_contains($lower, $phrase) && ! str_contains($lower, 'never')) {
                    $offenders[] = sprintf('%s:%d  %s', self::PATH, $number + 1, trim($line));
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Relative dates in '.self::PATH." (CLAUDE.md rule 14 — use YYYY-MM-DD):\n\n"
            .implode("\n", $offenders)."\n",
        );
    }
}
