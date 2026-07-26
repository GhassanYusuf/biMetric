<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces CLAUDE.md rule 8: the protocol package stays a reusable library.
 *
 * These are the only guarantees that keep `packages/zkteco-protocol` installable by
 * another platform. A convention nobody can violate by accident beats one written
 * down and forgotten — and this particular boundary erodes silently, one innocent
 * `config()` call at a time, until the package is Laravel-only again.
 */
class ProtocolLibraryBoundaryTest extends TestCase
{
    private function packagePath(string $suffix = ''): string
    {
        return dirname(__DIR__, 2) . '/packages/zkteco-protocol' . $suffix;
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
        $pos = strpos($path, 'packages/zkteco-protocol');

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
            . "Move framework concerns into App\\Services\\Devices\\ZktecoDriver instead.\n\n"
            . implode("\n", $offenders) . "\n",
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
            // These name library TYPES in signatures/guards through the contract,
            // which is the intended coupling: they never construct a transport or
            // speak a command.
            'app/Contracts/BiometricDeviceDriver.php',
            'app/Services/Devices/DeviceCommandHandler.php',
            'app/Services/Devices/DeviceHealthService.php',
            'app/Services/Devices/AttendanceSyncService.php',
            'app/Services/Devices/DeviceUserSyncService.php',
            'app/Services/Devices/EnrollmentOrchestratorService.php',
            'app/Jobs/ProcessDeviceCommand.php',
            'app/Models/Device.php',
            'app/Models/AccessCard.php',
            'app/Http/Controllers/Api/DeviceSyncController.php',
            'app/Http/Controllers/Api/DeviceEnrollmentController.php',
            // Binds the contract to the driver — the one place allowed to name both.
            'app/Providers/AppServiceProvider.php',
        ];

        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/app';
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
            $relative = 'app/' . substr($path, strlen($root) + 1);

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            if (str_contains(file_get_contents($path), 'Zkteco\\Protocol')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Only ZktecoDriver may import Zkteco\\Protocol (CLAUDE.md rule 8).\n"
            . "These files should depend on App\\Contracts\\BiometricDeviceDriver instead:\n  "
            . implode("\n  ", $offenders) . "\n",
        );
    }

    /** Controllers must not construct or drive the protocol themselves. */
    public function test_controllers_never_construct_a_device_session(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/app/Http/Controllers';
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
            . implode("\n  ", $offenders) . "\n",
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
            'Zkteco\\Protocol\\',
            array_key_first($composer['autoload']['psr-4']),
            'The library must own the Zkteco\\Protocol namespace.',
        );

        $this->assertSame(
            ['php'],
            array_keys($composer['require'] ?? []),
            'The library must require nothing but PHP itself. Anything else belongs in the host app.',
        );
    }
}
