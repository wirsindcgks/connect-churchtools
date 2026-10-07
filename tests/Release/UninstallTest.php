<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Release;

use ChurchToolsPlugin\Security\Crypto;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * uninstall.php laeuft genau einmal im Leben einer Installation und ist danach
 * nicht mehr zu korrigieren - was es liegen laesst, liegt fuer immer.
 */
final class UninstallTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_deleted_attachments();
        ctp_test_install_wpdb();

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'churchtools-plugin/churchtools-plugin.php');
        }
    }

    /** „Daten behalten" behaelt Termine und Einstellungen, nicht den API-Key. */
    public function testKeepingDataStillRemovesTheApiKey(): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => Crypto::encrypt('token'), 'keep_data_on_uninstall' => true]);
        ctp_test_set_option('ctp_church_address', ['name' => 'Gemeindehaus']);

        require dirname(__DIR__, 2) . '/uninstall.php';

        $settings = get_option('ctp_settings');
        $this->assertArrayNotHasKey('api_key', $settings);
        $this->assertSame('musterkirche', $settings['instance']);
        $this->assertSame(['name' => 'Gemeindehaus'], get_option('ctp_church_address'));
    }

    public function testRemovingDataLeavesNoOptionOfThisPluginBehind(): void
    {
        foreach (['ctp_settings', 'ctp_church_address', 'ctp_resources_fetched', 'ctp_lock_events', 'ctp_lock_groups', 'ctp_lock_posts', 'ctp_groups', 'ctp_group_settings', 'ctp_posts', 'ctp_post_settings', 'ctp_post_sync_error', 'ctp_post_image_warning', 'ctp_post_last_sync'] as $option) {
            ctp_test_set_option($option, ['x']);
        }

        require dirname(__DIR__, 2) . '/uninstall.php';

        $left = array_filter(array_keys($GLOBALS['ctp_test_options']), static fn (string $name): bool => str_starts_with($name, 'ctp_'));
        $this->assertSame([], array_values($left));
    }

    /** Die Bilder der Beitraege stehen in keiner Tabelle - nur die Zuordnung kennt sie. */
    public function testRemovingDataDeletesThePostImages(): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'keep_data_on_uninstall' => false]);
        ctp_test_set_option('ctp_post_images', ['https://musterkirche.church.tools/images/1/a' => 401]);

        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertContains(401, ctp_test_deleted_attachments());
        $this->assertFalse(get_option('ctp_post_images'));
    }

    /** Die Liste in uninstall.php muss die der Klasse sein - die Klasse ist dort nicht geladen. */
    public function testUninstallKnowsEveryOptionOfThePosts(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');

        foreach (\ChurchToolsPlugin\Posts\PostSync::optionNames() as $option) {
            $this->assertStringContainsString("delete_option('" . $option . "')", $source);
        }
    }

    /**
     * Das Protokoll ist Betriebsspur, kein Datenbestand wie Termine oder
     * Gruppen - es geht wie die Sperren (Sync\RunLock) in jedem Fall, auch
     * bei "Daten beim Deinstallieren behalten" (siehe
     * testDropsTheLogTableEvenWhenKeepingData() unten).
     */
    public function testRemovingDataDropsTheLogTable(): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'keep_data_on_uninstall' => false]);
        $wpdb = ctp_test_install_wpdb();

        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->expectException(PDOException::class);
        $wpdb->countLogRows();
    }

    public function testDropsTheLogTableEvenWhenKeepingData(): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'keep_data_on_uninstall' => true]);
        $wpdb = ctp_test_install_wpdb();

        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->expectException(PDOException::class);
        $wpdb->countLogRows();
    }
}
