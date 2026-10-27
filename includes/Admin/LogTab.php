<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Db\LogRepository;
use ChurchToolsPlugin\Log;

/**
 * Der Reiter „Protokoll" unter „Einstellungen": Filter, Tabelle, Seiten.
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan). Den Rahmen - Reiterreihe, Statuszeile - liefert
 * weiter SettingsPage.
 */
final class LogTab
{
    /**
     * Der Reiter „Protokoll" (seit 1.35.0, siehe Log/Db\LogRepository).
     * Bewusst schlank: eine Liste mit Filter, kein Export, keine einstellbare
     * Aufbewahrung (Nutzerentscheidung 2026-09-16) - die Fehlersuche auf der
     * eigenen Seite braucht nicht mehr.
     *
     * Rein lesend wie die Terminliste (EventsTab::render()) und nach
     * demselben Muster: eigene Filter aus $_GET, serverseitige Seiten.
     */
    public static function render(): void
    {
        $filters = self::logFilters();
        $repository = new LogRepository();
        $queryFilters = array_filter(['level' => $filters['level'], 'area' => $filters['area']]);

        $total = $repository->count($queryFilters);
        $lastPage = max(1, (int) ceil($total / LogRepository::PAGE_SIZE));
        // Derselbe Schutz wie bei den Terminen: ein Filterwechsel darf nicht
        // auf einer Seite jenseits des neuen Ergebnisses stehen bleiben.
        $paged = min($filters['paged'], $lastPage);
        $rows = $repository->find($queryFilters, $paged);
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Protokoll', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Was Migrationen, Synchronisation und Bild-Importe wirklich getan haben – Fehler, Warnungen und eine Zusammenfassung je Lauf. Aufbewahrt werden höchstens 30 Tage oder 1000 Einträge.', 'churchtools-plugin'); ?>
            </p>

            <?php self::renderLogFilterBar($filters); ?>

            <?php if ($rows === []) : ?>
                <p class="ctp-empty-state">
                    <?php // $total ist bereits gefiltert - eine leere Antwort auf
                    // einen aktiven Filter sagt nichts darueber, ob es ueberhaupt
                    // Eintraege gibt (derselbe Unterschied wie bei $stats['total']
                    // gegen $totalMatching in EventsTab::render()). ?>
                    <?php if ($queryFilters === [] || $repository->count() === 0) : ?>
                        <?php esc_html_e('Noch keine Protokolleinträge.', 'churchtools-plugin'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Keine Einträge passen zu diesem Filter.', 'churchtools-plugin'); ?>
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <table class="widefat striped ctp-borderless ctp-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Zeit', 'churchtools-plugin'); ?></th>
                            <th><?php esc_html_e('Stufe', 'churchtools-plugin'); ?></th>
                            <th><?php esc_html_e('Bereich', 'churchtools-plugin'); ?></th>
                            <th><?php esc_html_e('Meldung', 'churchtools-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <?php self::renderLogRow($row); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php self::renderLogPager($filters, $paged, $lastPage, $total); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array{level: string, area: string, paged: int}
     */
    private static function logFilters(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation state (which slice of the log to display), not a state change; same pattern as eventsFilters().
        $level = isset($_GET['level']) ? sanitize_key(wp_unslash($_GET['level'])) : '';
        $area = isset($_GET['area']) ? sanitize_key(wp_unslash($_GET['area'])) : '';
        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return [
            'level' => in_array($level, self::logLevels(), true) ? $level : '',
            'area' => in_array($area, self::logAreas(), true) ? $area : '',
            'paged' => max(1, $paged),
        ];
    }

    /** @return array<int, string> */
    private static function logLevels(): array
    {
        return [Log::LEVEL_ERROR, Log::LEVEL_WARNING, Log::LEVEL_INFO];
    }

    /** @return array<int, string> */
    private static function logAreas(): array
    {
        return [Log::AREA_EVENTS, Log::AREA_GROUPS, Log::AREA_POSTS, Log::AREA_IMAGES, Log::AREA_MIGRATION];
    }

    /** Deutsche Beschriftung einer Stufe - fuer Filter und Tabelle dieselbe Quelle. */
    private static function logLevelLabel(string $level): string
    {
        $labels = [
            Log::LEVEL_ERROR => __('Fehler', 'churchtools-plugin'),
            Log::LEVEL_WARNING => __('Warnung', 'churchtools-plugin'),
            Log::LEVEL_INFO => __('Info', 'churchtools-plugin'),
        ];

        return $labels[$level] ?? $level;
    }

    /** Deutsche Beschriftung eines Bereichs - fuer Filter und Tabelle dieselbe Quelle. */
    private static function logAreaLabel(string $area): string
    {
        $labels = [
            Log::AREA_EVENTS => __('Termine', 'churchtools-plugin'),
            Log::AREA_GROUPS => __('Gruppen', 'churchtools-plugin'),
            Log::AREA_POSTS => __('Beiträge', 'churchtools-plugin'),
            Log::AREA_IMAGES => __('Bilder', 'churchtools-plugin'),
            Log::AREA_MIGRATION => __('Migration', 'churchtools-plugin'),
        ];

        return $labels[$area] ?? $area;
    }

    /**
     * @param array{level: string, area: string, paged: int} $filters
     */
    private static function renderLogFilterBar(array $filters): void
    {
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="ctp-events-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(SettingsPage::areaSlug('settings')); ?>" />
            <input type="hidden" name="tab" value="log" />

            <label class="screen-reader-text" for="ctp-log-level"><?php esc_html_e('Stufe', 'churchtools-plugin'); ?></label>
            <select id="ctp-log-level" name="level">
                <option value=""><?php esc_html_e('Alle Stufen', 'churchtools-plugin'); ?></option>
                <?php foreach (self::logLevels() as $level) : ?>
                    <option value="<?php echo esc_attr($level); ?>" <?php selected($filters['level'], $level); ?>>
                        <?php echo esc_html(self::logLevelLabel($level)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="ctp-log-area"><?php esc_html_e('Bereich', 'churchtools-plugin'); ?></label>
            <select id="ctp-log-area" name="area">
                <option value=""><?php esc_html_e('Alle Bereiche', 'churchtools-plugin'); ?></option>
                <?php foreach (self::logAreas() as $area) : ?>
                    <option value="<?php echo esc_attr($area); ?>" <?php selected($filters['area'], $area); ?>>
                        <?php echo esc_html(self::logAreaLabel($area)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filtern', 'churchtools-plugin'); ?></button>
            <?php if ($filters['level'] !== '' || $filters['area'] !== '') : ?>
                <a class="button-link" href="<?php echo esc_url(SettingsPage::tabUrl('log')); ?>"><?php esc_html_e('Filter zurücksetzen', 'churchtools-plugin'); ?></a>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * @param array{id: int, logged_at: string, level: string, area: string, message: string, context: array<string, mixed>} $row
     */
    private static function renderLogRow(array $row): void
    {
        ?>
        <tr>
            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['logged_at'])); ?></td>
            <td>
                <span class="ctp-log-level ctp-log-level--<?php echo esc_attr($row['level']); ?>">
                    <?php echo esc_html(self::logLevelLabel($row['level'])); ?>
                </span>
            </td>
            <td><?php echo esc_html(self::logAreaLabel($row['area'])); ?></td>
            <td>
                <?php echo esc_html($row['message']); ?>
                <?php if ($row['context'] !== []) : ?>
                    <details class="ctp-log-context">
                        <summary><?php esc_html_e('Kontext', 'churchtools-plugin'); ?></summary>
                        <pre><?php echo esc_html((string) wp_json_encode($row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                    </details>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array{level: string, area: string, paged: int} $filters
     */
    private static function renderLogPager(array $filters, int $paged, int $lastPage, int $total): void
    {
        $pageUrl = static fn (int $page): string => SettingsPage::tabUrl('log', array_filter([
            'level' => $filters['level'],
            'area' => $filters['area'],
            'paged' => $page > 1 ? $page : null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
        ?>
        <div class="ctp-events-pager">
            <span class="ctp-muted-text">
                <?php
                printf(
                    /* translators: 1: current page number, 2: total number of pages, 3: number of matching entries */
                    esc_html__('Seite %1$d von %2$d – %3$d Einträge', 'churchtools-plugin'),
                    (int) $paged,
                    (int) $lastPage,
                    (int) $total
                );
                ?>
            </span>
            <?php if ($lastPage > 1) : ?>
                <span class="ctp-events-pager__links">
                    <?php if ($paged > 1) : ?>
                        <a class="button" href="<?php echo esc_url($pageUrl($paged - 1)); ?>">&larr; <?php esc_html_e('Zurück', 'churchtools-plugin'); ?></a>
                    <?php endif; ?>
                    <?php if ($paged < $lastPage) : ?>
                        <a class="button" href="<?php echo esc_url($pageUrl($paged + 1)); ?>"><?php esc_html_e('Weiter', 'churchtools-plugin'); ?> &rarr;</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }
}
