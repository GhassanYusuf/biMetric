<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the frontend rules in CLAUDE.md that no bundler or type checker will
 * catch. A convention nobody can violate by accident beats one written down and
 * forgotten.
 */
class FrontendConventionsTest extends TestCase
{
    /** Files allowed to mention the banned APIs — the replacements and the docs. */
    private const ALLOWED = [
        'resources/js/spa/shared/confirm.jsx',
        'resources/js/spa/shared/toast.jsx',
        'resources/js/spa/main.jsx',
        'resources/js/Components/ConfirmDialog.jsx',
        'resources/js/app.jsx',
    ];

    /** @return list<string> every .js/.jsx file under resources/js */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/resources/js';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['js', 'jsx'], true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function isAllowed(string $path): bool
    {
        foreach (self::ALLOWED as $allowed) {
            if (str_ends_with($path, $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function test_no_native_browser_dialogs_anywhere_in_the_frontend(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            if ($this->isAllowed($path)) {
                continue;
            }

            $source = file_get_contents($path);
            $lines = explode("\n", $source);

            foreach ($lines as $number => $line) {
                // window.confirm(...) / window.alert(...) / window.prompt(...)
                // and the bare globals, but not `.confirm(` on our own object
                // nor an identifier that merely ends in those letters.
                if (preg_match('/\bwindow\s*\.\s*(confirm|alert|prompt)\s*\(/', $line)
                    || preg_match('/(?<![\w.$])(alert|prompt)\s*\(/', $line)
                    || preg_match('/(?<![\w.$])confirm\s*\(\s*[`\'"]/', $line)) {
                    $relative = substr($path, strpos($path, 'resources/js'));
                    $offenders[] = sprintf('%s:%d  %s', $relative, $number + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Native browser dialogs are banned (see CLAUDE.md).\n"
            . "Use useConfirm() for confirmations and useToast() for results.\n\n"
            . implode("\n", $offenders) . "\n"
        );
    }

    public function test_the_confirm_and_toast_providers_are_mounted(): void
    {
        $spa = file_get_contents(dirname(__DIR__, 2) . '/resources/js/spa/main.jsx');
        $this->assertStringContainsString('<ConfirmProvider>', $spa, 'SPA must mount ConfirmProvider');
        $this->assertStringContainsString('<ToastProvider>', $spa, 'SPA must mount ToastProvider');

        $inertia = file_get_contents(dirname(__DIR__, 2) . '/resources/js/app.jsx');
        $this->assertStringContainsString('<ConfirmProvider>', $inertia, 'Inertia app must mount ConfirmProvider');
    }

    public function test_every_file_using_the_hooks_also_imports_them(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            $source = file_get_contents($path);
            $relative = substr($path, strpos($path, 'resources/js'));

            // Skip the modules that define the hooks.
            if (str_contains($relative, 'shared/toast.jsx') || str_contains($relative, 'shared/confirm.jsx')
                || str_contains($relative, 'Components/ConfirmDialog.jsx')) {
                continue;
            }

            if (preg_match('/\btoast\s*\.\s*(success|error|info)\s*\(/', $source)
                && ! str_contains($source, 'useToast')) {
                $offenders[] = "{$relative}: calls toast.* without importing useToast";
            }

            if (preg_match('/await\s+confirm\s*\(/', $source)
                && ! str_contains($source, 'useConfirm')) {
                $offenders[] = "{$relative}: awaits confirm() without importing useConfirm";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }
}
