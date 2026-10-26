<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Promoseven\Biometric\C3\Capability\C3Capabilities;
use Promoseven\Biometric\C3\Model\PanelModelFactory;
use Promoseven\Biometric\Model\TerminalModelFactory;

/**
 * Enforces the mechanical half of CLAUDE.md rule 21: each model has its own method
 * for each action, and changing one model never moves another.
 *
 * PINNED below is every model's level for the actions that touch a person's user ID,
 * name, card and fingerprints. A change meant for one model that shifts another's
 * level fails here, naming the model — that is the "don't break the others with the
 * same solution" check. Changing a level ON PURPOSE means updating this table,
 * docs/device-models.md and a casebook entry together (rule 20).
 *
 * Also checks docs/device-models.md has a section for every model the code knows.
 */
class DeviceModelMethodologyTest extends TestCase
{
    private const ACTIONS = [
        'user.read', 'user.write', 'user.delete', 'user.renumber',
        'card.read', 'card.write', 'fingerprint.enroll',
        'template.blob.read', 'template.upload', 'template.delete',
        'password.read', 'password.write', // the keypad password, 2026-10-12
        'user.privilege', // admin / normal user, 2026-10-18
        'device.wipe', // every user removed, 2026-10-21 (rule 22)
    ];

    /** model => levels, in ACTIONS order. c/i/u/n = confirmed/implemented/unknown/not_implemented. */
    private const PINNED = [
        'standalone:F18' => 'c c c n c c c c i i c c c i', // password read C 2026-10-12, write C 2026-10-20; privilege C 2026-10-20; wipe I 2026-10-21
        'standalone:F22' => 'c c c u c i u c i u c c c u', // template.upload I since 2026-10-11 (On The Road); password write and privilege C 2026-10-20; wipe never run on an F22
        'standalone:UA300' => 'c u u u c u u c u u i u u u', // own file 2.17.0 (Catering): reads C, no write has run on a UA300
        'standalone:K40' => 'i u u u i u u i u u i u u u', // any model without a file
        'c3:inBIO460' => 'c i i n u i n c i n c c n n', // password write C 2026-10-20 (Glass Door)
        'c3:TF1700' => 'c i i n u i n c i n c c n n', // password write C 2026-10-20 (Entrance)
    ];

    private static function levelsFor(string $key): string
    {
        [$family, $model] = explode(':', $key, 2);
        $set = $family === 'standalone'
            ? TerminalModelFactory::for($model)->capabilities()
            : C3Capabilities::forModel($model);

        $short = ['confirmed' => 'c', 'implemented' => 'i', 'unknown' => 'u', 'not_implemented' => 'n'];

        return implode(' ', array_map(fn ($a) => $short[$set->level($a)], self::ACTIONS));
    }

    public function test_every_models_levels_are_pinned(): void
    {
        $diff = [];
        foreach (self::PINNED as $model => $expected) {
            $actual = self::levelsFor($model);
            if ($actual !== $expected) {
                $diff[] = sprintf("%-16s expected %s\n%-16s actual   %s", $model, $expected, '', $actual);
            }
        }

        $this->assertSame([], $diff, "A model's methods changed (rule 21). Columns: ".implode(' ', self::ACTIONS)
            ."\nIf this was intended for THAT model, update PINNED, docs/device-models.md and the casebook.\n".implode("\n", $diff));
    }

    public function test_the_methodology_has_a_section_for_every_known_model(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/device-models.md');

        foreach ([...TerminalModelFactory::known(), ...PanelModelFactory::known()] as $model) {
            $this->assertMatchesRegularExpression('/^## '.preg_quote($model, '/').'\b/m', $doc, "docs/device-models.md has no section for {$model}");
        }
        $this->assertMatchesRegularExpression('/\*\*Last updated: \d{4}-\d{2}-\d{2}\*\*/', $doc);
    }
}
