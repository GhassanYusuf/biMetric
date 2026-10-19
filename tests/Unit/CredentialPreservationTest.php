<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the mechanical half of CLAUDE.md rule 19: the system keeps a copy of
 * every card and fingerprint, takes it before anything on a terminal is destroyed,
 * and writes it back when the person is added again.
 *
 * Owner, 2026-10-11, after Akram was removed from IT Room and came back with no
 * finger and no card: "not just fingerprint but also card number, you have to make
 * a procedure and a rule". The procedure is docs/credential-preservation.md.
 *
 * What this cannot check is that a NEW destructive path (a wipe, a bulk delete, a
 * device reset) takes the copy first. Every such path must go through
 * DeviceUserSyncService::removeEmployee() or call the same save step. That part is
 * discipline — and the first assertion below catches the obvious way round it.
 */
class CredentialPreservationTest extends TestCase
{
    private const SYNC = 'app/Services/Devices/DeviceUserSyncService.php';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::root().'/'.$relative);
    }

    /** The body of one method, from its signature to the next method. */
    private static function method(string $source, string $name): string
    {
        $start = strpos($source, "function {$name}(");
        self::assertNotFalse($start, "{$name}() not found");
        $next = preg_match('/\n    (?:public|private|protected) function /', $source, $m, PREG_OFFSET_CAPTURE, $start + 10)
            ? $m[0][1] : strlen($source);

        return substr($source, $start, $next - $start);
    }

    /** @return list<string> app PHP files, relative */
    private static function appFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/app'));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen(self::root()) + 1);
            }
        }

        return $files;
    }

    public function test_only_remove_employee_deletes_a_user_from_a_terminal(): void
    {
        $offenders = [];
        foreach (self::appFiles() as $file) {
            // The drivers and the manager DEFINE deleteUser(); they are the plumbing.
            if (str_contains($file, 'Services/Devices/ZktecoDriver.php')
                || str_contains($file, 'Services/Devices/DeviceDriverManager.php')
                || str_contains($file, 'Services/Devices/C3/')
                || str_contains($file, 'Services/Devices/Csl/')) {
                continue;
            }
            $calls = preg_match_all('/->deleteUser\(/', self::read($file));
            if ($calls > 0 && $file !== self::SYNC) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Deleting a terminal user outside DeviceUserSyncService skips the rule-19 copy.');

        // Exactly three deleters: removeEmployee() (an employee) and
        // releaseUnlinkedNumber() (a record linked to nobody), both saving first; and
        // withTemporaryUser(), which deletes only the "zkt-test" user it created
        // itself moments before (2026-10-11).
        $this->assertSame(3, preg_match_all('/->deleteUser\(/', self::read(self::SYNC)));
        $temp = self::method(self::read(self::SYNC), 'withTemporaryUser');
        $this->assertStringContainsString("'zkt-test'", $temp, 'it only ever deletes the user it created');
    }

    public function test_freeing_a_number_saves_the_record_and_fingers_before_it_deletes(): void
    {
        $body = self::method(self::read(self::SYNC), 'releaseUnlinkedNumber');

        $read = strpos($body, '->readTemplates(');
        $save = strpos($body, "Storage::disk('local')->put(");
        $delete = strpos($body, '->deleteUser(');

        $this->assertNotFalse($read);
        $this->assertNotFalse($save);
        $this->assertNotFalse($delete);
        $this->assertLessThan($delete, $save, 'the snapshot must be written BEFORE the delete');
        $this->assertLessThan($save, $read);
    }

    public function test_removal_saves_card_and_fingers_before_it_deletes(): void
    {
        $body = self::method(self::read(self::SYNC), 'removeEmployee');

        $save = strpos($body, 'saveCredentialsBeforeRemoval(');
        $delete = strpos($body, '->deleteUser(');

        $this->assertNotFalse($save, 'removeEmployee() must call saveCredentialsBeforeRemoval()');
        $this->assertNotFalse($delete);
        $this->assertLessThan($delete, $save, 'the copy must be taken BEFORE the delete');

        $saver = self::method(self::read(self::SYNC), 'saveCredentialsBeforeRemoval');
        $this->assertStringContainsString('recordCard(', $saver, 'the card is part of the copy');
        $this->assertStringContainsString('->capture(', $saver, 'the fingers are part of the copy');
    }

    public function test_adding_a_person_writes_their_card_and_fingers_back(): void
    {
        $push = self::method(self::read(self::SYNC), 'pushEmployee');
        $this->assertStringContainsString('restoreCredentials(', $push);

        $restore = self::method(self::read(self::SYNC), 'restoreCredentials');
        $this->assertStringContainsString('cardToRestore(', $restore);
        $this->assertStringContainsString('restoreOnto(', $restore);
    }

    public function test_no_code_deletes_a_stored_fingerprint_copy(): void
    {
        // A BiometricTemplate delete must be limited to bare slot markers. Deleting a
        // device or an employee (FK cascade) is the only way a copy goes.
        $offenders = [];
        foreach (self::appFiles() as $file) {
            $source = self::read($file);
            if (! preg_match_all('/BiometricTemplate::query\(\)[^;]*?->delete\(\)/s', $source, $m)) {
                continue;
            }
            foreach ($m[0] as $statement) {
                if (! str_contains($statement, "whereNull('template_sha1')")) {
                    $offenders[] = $file;
                }
            }
        }

        $this->assertSame([], $offenders, "Stored fingerprint copies are only removed with their employee or terminal; scope deletes with whereNull('template_sha1').");
    }

    public function test_a_terminal_without_a_card_never_clears_the_record_card(): void
    {
        $read = self::method(self::read(self::SYNC), 'readEnrollment');

        $this->assertDoesNotMatchRegularExpression(
            "/'card_number'\s*=>\s*\\\$cardNumber,\s*\n\s*'enrollment_synced_at'/",
            $read,
            'readEnrollment() must not copy a terminal\'s empty card onto employees.card_number',
        );
        $this->assertStringContainsString('if ($cardNumber !== null)', $read);
    }
}
