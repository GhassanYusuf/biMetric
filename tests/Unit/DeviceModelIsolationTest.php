<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the mechanical half of CLAUDE.md rule 15: one model per file.
 *
 * The ZKTeco F18 is the only model this project has working end to end. Every
 * other terminal it has met behaves differently — the F22 refuses push reads and
 * refuses windowed reads on the users table; Camelot demands an AUTH exchange the
 * LAN F18s do not. The cheap way to absorb each of those is a conditional in the
 * middle of the working read path, and three of those turn the one device that
 * works into the device nothing can be safely changed on.
 *
 * So: a model name may appear in COMMENTS anywhere, because that is how a firmware
 * finding gets recorded and rule 12 requires it. It may not appear in executable
 * code unless the file is itself named for that model.
 *
 * What this cannot check is the other half of rule 15 — that the F18 was actually
 * re-read after shared code changed. That part is discipline.
 */
class DeviceModelIsolationTest extends TestCase
{
    /**
     * Terminal models this project has encountered or is likely to next.
     *
     * Listed so the check names the offender precisely. The generic pattern in
     * test_no_model_identifier_is_hard_coded_as_a_string() catches models nobody
     * has added here yet.
     */
    private const KNOWN_MODELS = [
        'F18', 'F19', 'F22', 'F16', 'F21',
        'K40', 'K50', 'MB460', 'SpeedFace', 'iClock', 'iFace',
    ];

    /**
     * Directories where a model branch would do real damage: the protocol library
     * and the app's device layer.
     *
     * The library is checked through its INSTALLED path for the same reason
     * ProtocolLibraryBoundaryTest does — that is the copy a licensee receives.
     */
    private const SCANNED = [
        'vendor/promoseven/biometric-protocol/src',
        'app/Services/Devices',
        'app/Contracts',
    ];

    private function root(string $suffix = ''): string
    {
        return dirname(__DIR__, 2).'/'.$suffix;
    }

    /** @return list<string> every PHP file in the scanned directories */
    private function sources(): array
    {
        $files = [];

        foreach (self::SCANNED as $dir) {
            $path = $this->root($dir);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace($this->root(), '', $path);
    }

    /**
     * The file's source with every comment and docblock removed.
     *
     * Comments are where firmware findings live — "CONFIRMED on a ZKTeco F18,
     * 22,066 bytes" is exactly the sentence rule 12 wants written down. Only what
     * actually executes is the subject of this rule.
     */
    private function executableCode(string $path): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep the newlines so reported line numbers stay honest.
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /** @return list<array{int,string}> [line, text] for each match of $pattern */
    private function matchingLines(string $code, string $pattern): array
    {
        $hits = [];

        foreach (explode("\n", $code) as $index => $line) {
            if (preg_match($pattern, $line)) {
                $hits[] = [$index + 1, trim($line)];
            }
        }

        return $hits;
    }

    public function test_the_scanned_directories_are_not_empty(): void
    {
        $this->assertGreaterThan(
            20,
            count($this->sources()),
            "Rule 15's check found almost no device-layer sources — the paths in\n"
            ."DeviceModelIsolationTest::SCANNED have moved and the check is now\n"
            ."passing by scanning nothing.\n"
        );
    }

    /**
     * The core of rule 15.
     *
     * A model name in running code is a branch, a lookup key or a comparison, and
     * all three mean one file is trying to be two devices at once.
     */
    public function test_no_shared_file_branches_on_a_device_model(): void
    {
        $violations = [];

        foreach ($this->sources() as $path) {
            $code = $this->executableCode($path);

            foreach (self::KNOWN_MODELS as $model) {
                // A file named for the model is exactly where the name belongs:
                // src/Transport/F22/WindowedBulkReader.php, or anything under it.
                if (stripos($path, $model) !== false) {
                    continue;
                }

                $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($model, '/').'(?![A-Za-z0-9_])/i';

                foreach ($this->matchingLines($code, $pattern) as [$line, $text]) {
                    $violations[] = $this->relative($path).':'.$line.'  ('.$model.')  '.$text;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "A device model is named in executable code (CLAUDE.md rule 15).\n"
            ."The F18 is the only model that works end to end; a conditional for a\n"
            ."second model inside a shared path puts that at risk on every change.\n\n"
            ."Give the model its own file instead — packages/zkteco-protocol/src/<Layer>/<Model>/…\n"
            ."for protocol differences, app/Services/Devices/<Vendor>/ for another\n"
            ."protocol entirely — and select it once, where the driver is built.\n\n"
            ."Recording a finding in a COMMENT is fine and is what rule 12 asks for.\n"
            ."Only running code is checked here.\n\n"
            .implode("\n", $violations)."\n"
        );
    }

    /**
     * The same rule for a model this test has never heard of.
     *
     * Catches 'F19', 'K40/ID' and friends appearing as a literal before anyone
     * remembers to add them to KNOWN_MODELS.
     */
    public function test_no_model_identifier_is_hard_coded_as_a_string(): void
    {
        // A terminal model as ZKTeco writes it: one to three letters, two to four
        // digits, optionally suffixed /ID, /IDF, -A. Deliberately narrow — it must
        // not fire on 'UTC+3', 'CMD_ATT_LOG' or a migration name.
        $modelLiteral = '/^[A-Za-z]{1,3}-?\d{2,4}(?:\/[A-Za-z]{1,4})?$/';

        $violations = [];

        foreach ($this->sources() as $path) {
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $value = trim($token[1], "'\"");

                if ($value === '' || ! preg_match($modelLiteral, $value)) {
                    continue;
                }

                if (stripos($path, $value) !== false) {
                    continue;
                }

                $violations[] = $this->relative($path).':'.$token[2].'  '.$token[1];
            }
        }

        $this->assertSame(
            [],
            $violations,
            "What looks like a device model appears as a string literal in the device\n"
            ."layer (CLAUDE.md rule 15). Behaviour is selected per model in ONE place,\n"
            ."where the driver is constructed, and lives in a file named for that model.\n\n"
            ."If this is a false positive — a constant that merely looks like a model —\n"
            ."rename it or narrow the pattern in this test, with a comment saying why.\n\n"
            .implode("\n", $violations)."\n"
        );
    }
}
