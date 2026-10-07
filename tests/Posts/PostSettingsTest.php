<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Posts;

use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Posts\PostSettings;
use PHPUnit\Framework\TestCase;

final class PostSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
    }

    /** Ohne ausdrueckliches Einschalten fragt das Plugin ChurchTools nicht nach Beitraegen. */
    public function testSwitchedOffByDefault(): void
    {
        $this->assertFalse(PostSettings::isEnabled());
        $this->assertSame(['enabled' => false, 'sync_interval' => 'hourly'], PostSettings::get());
    }

    /** Das versteckte Feld vor dem Kaestchen: „0" allein heisst aus, „0" und „1" heisst an. */
    public function testTheCheckboxAndItsHiddenTwin(): void
    {
        $this->assertTrue(PostSettings::sanitize(['enabled' => '1'])['enabled']);

        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => true]);
        $this->assertFalse(PostSettings::sanitize(['enabled' => '0'])['enabled']);
    }

    /** Ein fehlender Schluessel heisst „nicht abgeschickt", nicht „leeren". */
    public function testMissingKeysKeepTheStoredValue(): void
    {
        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => true, 'sync_interval' => 'daily']);

        $this->assertSame(['enabled' => true, 'sync_interval' => 'daily'], PostSettings::sanitize([]));
        $this->assertSame(['enabled' => true, 'sync_interval' => 'daily'], PostSettings::sanitize(['sync_interval' => 'minutely']));
    }

    public function testAnUnknownStoredIntervalFallsBackToTheDefault(): void
    {
        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => '1', 'sync_interval' => 'yearly', 'fremd' => 'x']);

        $this->assertSame(['enabled' => true, 'sync_interval' => 'hourly'], PostSettings::get());
    }

    /** Ein- und Ausschalten plant neu und laeuft sofort - beim Ausschalten raeumt der Lauf ab. */
    public function testSwitchingPlansAndRunsAtOnce(): void
    {
        $this->assertSame(
            ['reschedule' => true, 'sync_now' => true],
            Installer::postSettingsChange(['enabled' => false, 'sync_interval' => 'hourly'], ['enabled' => true, 'sync_interval' => 'hourly'])
        );
        $this->assertSame(
            ['reschedule' => true, 'sync_now' => true],
            Installer::postSettingsChange(['enabled' => true, 'sync_interval' => 'hourly'], ['enabled' => false, 'sync_interval' => 'hourly'])
        );
        $this->assertSame(
            ['reschedule' => true, 'sync_now' => true],
            Installer::postSettingsChange(null, ['enabled' => true, 'sync_interval' => 'hourly']),
            'Beim allerersten Speichern gibt es noch keinen alten Wert.'
        );
    }

    public function testChangingTheIntervalPlansAnewWithoutAnExtraRun(): void
    {
        $this->assertSame(
            ['reschedule' => true, 'sync_now' => false],
            Installer::postSettingsChange(['enabled' => true, 'sync_interval' => 'hourly'], ['enabled' => true, 'sync_interval' => 'daily'])
        );
        $this->assertSame(
            ['reschedule' => false, 'sync_now' => false],
            Installer::postSettingsChange(['enabled' => true, 'sync_interval' => 'daily'], ['enabled' => true, 'sync_interval' => 'daily'])
        );
    }
}
