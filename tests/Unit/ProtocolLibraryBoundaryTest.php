<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces CLAUDE.md rule 8: the protocol package stays a reusable library.
 *
 * These are the only guarantees that keep `promoseven/biometric-protocol` installable
 * by another platform. A convention nobody can violate by accident beats one written
 * down and forgotten — and this particular boundary erodes silently, one innocent
 * `config()` call at a time, until the package is Laravel-only again.
 *
 * The library is no longer vendored in this repo: it is its own distributable
 * package, resolved through composer. So these checks run against the INSTALLED
 * copy, which is what a licensee actually receives. During development that is a
 * symlink to the working tree, so editing the library still fails this suite
 * immediately; installed from a registry it checks the published artifact.
 */
class ProtocolLibraryBoundaryTest extends TestCase
{
    /** Where composer installs the library. A path repository symlinks it here. */
    private const PACKAGE_PATH = 'vendor/promoseven/biometric-protocol';

    private function packagePath(string $suffix = ''): string
    {
        return dirname(__DIR__, 2).'/'.self::PACKAGE_PATH.$suffix;
    }

    /** @return list<string> every PHP file in the library's src/ */
    private function librarySources(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->packagePath('/src')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        $pos = strpos($path, self::PACKAGE_PATH);

        return $pos === false ? $path : substr($path, $pos);
    }

    public function test_the_library_exists_and_is_not_trivially_empty(): void
    {
        $this->assertFileExists($this->packagePath('/composer.json'));

        $sources = $this->librarySources();

        $this->assertGreaterThan(
            20,
            count($sources),
            'The protocol library looks truncated — expected the full layered structure.',
        );
    }

    /**
     * The core of rule 8.
     *
     * Anything on this list drags a framework into a package that is supposed to run
     * anywhere: a CLI tool, a Node-adjacent worker, another PHP app, a future SaaS.
     */
    public function test_the_library_never_imports_laravel(): void
    {
        $banned = [
            '/\bIlluminate\\\\/' => 'Illuminate (Laravel framework)',
            '/\bApp\\\\/' => 'App\\ (the host application)',
            // The lookbehinds keep these from matching a METHOD of the same name:
            // `public function config(): DeviceConfig` and `$this->config()` are the
            // library's own API, not Laravel's global helpers.
            '/(?<!function )(?<![\w:>$])config\s*\(/' => 'config() helper',
            '/(?<!function )(?<![\w:>$])now\s*\(/' => 'now() helper',
            '/(?<!function )(?<![\w:>$])app\s*\(/' => 'app() container helper',
            '/\bLog::/' => 'Log facade',
            '/\bDB::/' => 'DB facade',
            '/\bCache::/' => 'Cache facade',
            '/\bCarbon\\\\Carbon\b/' => 'Carbon',
            '/\bEloquent\b/' => 'Eloquent',
        ];

        $offenders = [];

        foreach ($this->librarySources() as $path) {
            $lines = explode("\n", file_get_contents($path));

            foreach ($lines as $number => $line) {
                // Comments explain the boundary and legitimately name these things.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '|')) {
                    continue;
                }

                foreach ($banned as $pattern => $label) {
                    if (preg_match($pattern, $line)) {
                        $offenders[] = sprintf(
                            '%s:%d uses %s — %s',
                            $this->relative($path),
                            $number + 1,
                            $label,
                            trim($line),
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The protocol library must stay framework-agnostic (CLAUDE.md rule 8).\n"
            ."Move framework concerns into App\\Services\\Devices\\ZktecoDriver instead.\n\n"
            .implode("\n", $offenders)."\n",
        );
    }

    /**
     * Exactly ONE class in the app may know the library exists.
     *
     * Without this, protocol calls creep into controllers and the contract becomes
     * decoration.
     */
    public function test_only_the_driver_imports_the_protocol_library(): void
    {
        $allowed = [
            'app/Services/Devices/ZktecoDriver.php',
            /*
             * The second vendor, and the delegator that routes between them.
             *
             * CslHttpDriver speaks HTTP, not the ZKTeco protocol — yet it still has
             * to import from the package, because Capability, CapabilitySet and
             * DeviceException are the app's SHARED vocabulary and they live there.
             * That is real debt: the vocabulary belongs in a vendor-neutral package
             * and must move before a third vendor arrives. Recorded here so the next
             * person meets it deliberately rather than by surprise.
             */
            'app/Services/Devices/Csl/CslHttpDriver.php',
            'app/Services/Devices/Csl/CslWebPortalClient.php',
            /*
             * The third driver: ZKTeco C3 / PullSDK access panels (inBIO460,
             * TF1700). It speaks the library's C3 namespace, which is the intended
             * coupling — but it ALSO imports Capability, CapabilitySet and
             * DeviceException for the same reason CslHttpDriver does.
             *
             * That is the debt above, now come due: rule 8 said the shared
             * vocabulary must move to its own package "before a third vendor
             * arrives". The third vendor has arrived. Moving it was deliberately
             * not done in the same change as making two dead panels readable, so it
             * is recorded here as the next structural job rather than quietly
             * deferred again.
             */
            'app/Services/Devices/C3/C3Driver.php',
            'app/Services/Devices/DeviceDriverManager.php',
            // Extends DeviceException so every existing catch reports "terminal busy"
            // like any other device failure, and overrides isRetryable() so a queued
            // command retries instead of failing for good (2026-10-12).
            'app/Exceptions/DeviceBusyException.php',
            // These name library TYPES in signatures/guards through the contract,
            // which is the intended coupling: they never construct a transport or
            // speak a command.
            'app/Contracts/BiometricDeviceDriver.php',
            'app/Services/Devices/DeviceCommandHandler.php',
            'app/Services/Devices/DeviceHealthService.php',
            'app/Services/Devices/AttendanceSyncService.php',
            'app/Services/Devices/DeviceUserSyncService.php',
            'app/Services/Devices/EnrollmentOrchestratorService.php',
            // "Rebuild from system" (2026-10-11): plans from capability levels which
            // writes a terminal can take; names Capability only, speaks no protocol.
            'app/Services/Devices/DeviceRebuildService.php',
            // The Cron jobs runner (2026-10-20, was DeviceMorningRoutine): asks a terminal's
            // capability set whether it can take a clock / read attendance; names
            // Capability only and reaches hardware only through the command bus.
            'app/Services/Devices/ScheduledJobRunner.php',
            // Clear a terminal's punches (2026-10-18): asks for attendance.clear and
            // refuses with ProtocolException; reaches hardware only through the contract.
            'app/Services/Devices/AttendanceClearService.php',
            /*
             * Adoption: reads an existing terminal's roster before it is writable.
             * Names Capability to gate the read and DeviceUser to receive what
             * BiometricDeviceDriver::readUsers() returns. Same category as the two
             * above — it reaches the hardware only through the contract.
             */
            'app/Services/Devices/DeviceAdoptionService.php',
            /*
             * Records template digests off each terminal. Names
             * UnsupportedCommandException ONLY, to tell "this family has no readable
             * template table" apart from "the read failed" — the first must leave the
             * stored digests alone, the second is a device fault. It reaches hardware
             * solely through BiometricDeviceDriver::readTemplates(); since 2026-09-24
             * the blobs it receives go straight to FingerprintVault, encrypted, and
             * are never logged or printed.
             */
            'app/Console/Commands/DeviceReadTemplates.php',
            /*
             * The system's fingerprint copy (2026-09-24). Names
             * UnsupportedCommandException ONLY, for the same reason as the command
             * above: "this terminal has no template table" is a per-terminal SKIP in
             * the reply, "the read failed" is a FAILURE — and neither may delete a
             * stored copy. Reaches hardware solely through
             * BiometricDeviceDriver::readTemplates().
             */
            'app/Services/Employees/FingerprintVault.php',
            /*
             * Pushes vault copies to terminals (2026-09-24). Names Capability to guard
             * with require(TEMPLATE_UPLOAD) and catches UnsupportedCommandException to
             * report a terminal that cannot take templates as skipped, not failed. The
             * write itself goes through the contract's uploadTemplates().
             */
            'app/Services/Employees/FingerprintPusher.php',
            /*
             * The PIN-to-employee resolver. Names DeviceUser only — the occupant of a
             * slot, handed to it by the driver so it can decide whether a write may
             * proceed. It holds no session and issues no command: the read and the
             * write that the decision sits between both happen inside
             * BiometricDeviceDriver::saveUser(), which is where the atomicity lives.
             */
            'app/Services/Devices/DeviceIdentityResolver.php',
            'app/Jobs/ProcessDeviceCommand.php',
            'app/Models/Device.php',
            'app/Models/AccessCard.php',
            'app/Http/Controllers/Api/DeviceSyncController.php',
            /*
             * The company time zone screen (2026-10-05). Names Capability for exactly
             * the reason DeviceSyncController above is allowed: to decide whether a
             * terminal can have its clock set (SET_TIME) and to turn "no" into the
             * operator-facing reason via Capability::describe(). Same category, same
             * shape of use. It issues no command itself — the write is queued as the
             * existing sync_time DeviceCommand, and the clock read goes through
             * BiometricDeviceDriver::run().
             */
            'app/Http/Controllers/Api/DeviceTimezoneController.php',
            'app/Http/Controllers/Api/DeviceEnrollmentController.php',
            // These two name DeviceException ONLY to map a device failure onto a 502.
            // They call services, never the protocol — which the companion test
            // test_controllers_never_construct_a_device_session() enforces.
            'app/Http/Controllers/Api/DeviceController.php',
            'app/Http/Controllers/Api/EmployeeController.php',
            /*
             * The single place a device failure is RECORDED. Same category as the
             * two controllers above: it names the exception types only, to read
             * context() and isRetryable() and choose the operator's wording. It
             * never opens a session, builds a config, or touches a transport.
             *
             * The library cannot log for itself — it is framework-agnostic by rule
             * 8 and has no Log facade — so the app side has to name the exceptions
             * it is reporting on. Doing it by string class-name to satisfy this
             * test would trade a real type check for a typo waiting to happen.
             */
            'app/Support/DeviceErrorReporter.php',
            // Binds the contract to the driver — the one place allowed to name both.
            'app/Providers/AppServiceProvider.php',
            /*
             * The realtime listener. Reaches the device only through
             * BiometricDeviceDriver::open(); it names ProtocolDevice to hold the
             * returned session and Capability to gate on realtime support. It never
             * builds a config or a transport.
             */
            'app/Console/Commands/ListenZkteco.php',
            /*
             * The protocol diagnostic, and the deliberate exception.
             *
             * Its entire job is to ask raw questions of unknown firmware — command
             * numbers, byte offsets, what the codec makes of the answer — so it names
             * Protocol\Command and TemplateCodec directly. Hiding that behind the
             * contract would defeat the tool: the contract exposes what we already
             * understand, and this exists for what we do not. It still opens sessions
             * only via the driver, and it is strictly read-only.
             */
            'app/Console/Commands/ProbeFingerprintTemplates.php',
        ];

        $root = str_replace('\\', '/', dirname(__DIR__, 2)).'/app';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            // Relative to the project root, computed by LENGTH not by searching for
            // "app/" — the project directory is itself named `app`, so a search finds
            // the wrong one and yields "app/app/...".
            $relative = 'app/'.substr($path, strlen($root) + 1);

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            if (str_contains(file_get_contents($path), 'Promoseven\\Biometric')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Only ZktecoDriver may import Promoseven\\Biometric (CLAUDE.md rule 8).\n"
            ."These files should depend on App\\Contracts\\BiometricDeviceDriver instead:\n  "
            .implode("\n  ", $offenders)."\n",
        );
    }

    /** Controllers must not construct or drive the protocol themselves. */
    public function test_controllers_never_construct_a_device_session(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2)).'/app/Http/Controllers';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strpos(str_replace('\\', '/', $file->getPathname()), 'app/'));

            foreach (['DeviceClient::', 'UdpTransport', 'new Session(', 'ProtocolDevice::run', 'Device::open('] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = "{$relative} uses {$needle}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Controllers must go through a service and the driver contract, never the protocol directly.\n  "
            .implode("\n  ", $offenders)."\n",
        );
    }

    /**
     * The package must declare its own autoloading and require nothing but PHP.
     *
     * A require entry here is how "framework-agnostic" quietly stops being true.
     */
    public function test_the_package_declares_no_runtime_dependencies_beyond_php(): void
    {
        $composer = json_decode(file_get_contents($this->packagePath('/composer.json')), true);

        $this->assertSame(
            'Promoseven\\Biometric\\',
            array_key_first($composer['autoload']['psr-4']),
            'The library must own the Promoseven\\Biometric namespace.',
        );

        $this->assertSame(
            ['php'],
            array_keys($composer['require'] ?? []),
            'The library must require nothing but PHP itself. Anything else belongs in the host app.',
        );
    }
}
