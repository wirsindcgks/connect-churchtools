<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SyncHealthNotice;
use ChurchToolsPlugin\Posts\PostSettings;
use ChurchToolsPlugin\Posts\PostSync;
use PHPUnit\Framework\TestCase;

/**
 * Der Befund fuer den Beitrags-Abgleich - dieselben drei Faelle wie bei den
 * Gruppen (Fehler, fehlender Zeitplan, ueberfaellig), und nichts, solange der
 * Abgleich ausgeschaltet ist.
 */
final class PostProblemTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_set_next_run(PostSync::HOOK, null);
    }

    /** Die meisten Installationen zeigen keine Beitraege - das ist kein Befund. */
    public function testSwitchedOffIsQuietEvenWithAStoredError(): void
    {
        ctp_test_set_option(PostSync::ERROR_OPTION, ['time' => '2026-10-07 12:00:00', 'message' => 'kaputt']);

        $this->assertNull(SyncHealthNotice::postProblem());
        $this->assertNull(SyncHealthNotice::postWarnings());
    }

    public function testAFailedRunIsReported(): void
    {
        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => true]);
        ctp_test_set_option(PostSync::ERROR_OPTION, ['time' => '2026-10-07 12:00:00', 'message' => 'ChurchTools API error 503']);

        $problem = SyncHealthNotice::postProblem();

        $this->assertSame('error', $problem['type']);
        $this->assertStringContainsString('Beiträge', $problem['message']);
        $this->assertStringContainsString('503', $problem['message']);
    }

    public function testAMissingScheduleIsReported(): void
    {
        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => true]);

        $problem = SyncHealthNotice::postProblem();

        $this->assertSame('error', $problem['type']);
        $this->assertStringContainsString('kein Zeitplan', $problem['message']);
    }
}
