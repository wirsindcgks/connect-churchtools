<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Settings;

/**
 * Der Reiter „Events" im Bereich „Events": die gespeicherten Termine als
 * Tabelle mit Filter und Seiten, Serien zusammengefasst, und der
 * Detailblick auf einen einzelnen Termin.
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan). Das Gegenstueck fuer Gruppen ist
 * GroupsTab::renderList().
 */
final class EventsTab
{
    /** Rows per page in the Events tab's table (see render()). */
    private const EVENTS_PER_PAGE = 25;

    /**
     * The Events tab's current filter state, read straight off the query
     * string. Every value is whitelisted/clamped here rather than in the
     * template below, so the same normalized array can drive both the query
     * and the "keep my filters" links in the pager.
     *
     * @return array{scope: string, calendar_id: int, search: string, paged: int, view: 'series'|'occurrences'}
     */
    private static function eventsFilters(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation state (which slice of the table to display), not a state change; same pattern as currentTab()'s $_GET['tab'] read.
        $scope = isset($_GET['scope']) ? sanitize_key(wp_unslash($_GET['scope'])) : 'upcoming';
        $calendarId = isset($_GET['calendar_id']) ? absint($_GET['calendar_id']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'series';

        return [
            'scope' => in_array($scope, ['upcoming', 'past', 'all'], true) ? $scope : 'upcoming',
            'calendar_id' => $calendarId,
            'search' => $search,
            'paged' => max(1, $paged),
            // Series is the default: a recurring "Gottesdienst" occupying 50
            // near-identical rows is noise when you are looking for one specific
            // appointment. Occurrence view stays one click away.
            'view' => $view === 'occurrences' ? 'occurrences' : 'series',
        ];
    }

    /**
     * Read-only overview of the actually synced wp_ctp_events rows, so an admin
     * can verify the sync really pulled the right appointments without needing
     * DB access.
     *
     * Was a flat "next 200 upcoming rows" dump, which stopped being usable once
     * a handful of weekly series filled the sync horizon with several hundred
     * occurrences: no way to find one specific appointment, no way to look at a
     * single calendar, no way to see anything past the 200th row, and a footer
     * count that reported *all* stored events while the table only ever showed
     * upcoming ones. Now: headline counts, a filter bar (Zeitraum/Kalender/
     * Suche), month-grouped rows and real paging — all server-side, so it
     * works on a table of any size.
     */
    public static function render(): void
    {
        $repository = new EventRepository();
        $filters = self::eventsFilters();
        $stats = $repository->stats();
        $calendars = Settings::get()['calendars'];

        $isSeriesView = $filters['view'] === 'series';
        $totalMatching = $isSeriesView
            ? $repository->countSeriesForAdmin($filters)
            : $repository->countForAdmin($filters);
        $lastPage = max(1, (int) ceil($totalMatching / self::EVENTS_PER_PAGE));
        // A filter change can leave "paged" pointing past the end of the new
        // result set — clamp rather than render an empty table with a pager
        // that offers no way back.
        $paged = min($filters['paged'], $lastPage);
        $offset = ($paged - 1) * self::EVENTS_PER_PAGE;
        $events = $isSeriesView
            ? $repository->findSeriesForAdmin($filters, self::EVENTS_PER_PAGE, $offset)
            : $repository->findForAdmin($filters, self::EVENTS_PER_PAGE, $offset);

        // Dieselbe Statuszeile wie auf jedem anderen Tab, aus derselben
        // Funktion und an derselben Stelle: ueber dem Panel, nicht darin.
        SettingsPage::renderStatStrip([
            [
                'icon' => 'database',
                'value' => (string) $stats['total'],
                'label' => __('Gesamt', 'churchtools-plugin'),
            ],
            [
                'icon' => 'calendar-alt',
                'value' => (string) $stats['upcoming'],
                'label' => __('Kommend', 'churchtools-plugin'),
            ],
            [
                'icon' => 'backup',
                'value' => (string) $stats['past'],
                'label' => __('Vergangen (in Aufbewahrung)', 'churchtools-plugin'),
            ],
            [
                'icon' => 'format-image',
                'value' => (string) $stats['with_image'],
                'label' => __('Mit importiertem Bild', 'churchtools-plugin'),
            ],
        ]);
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Gespeicherte Termine', 'churchtools-plugin'); ?></h2>
            <?php self::renderEventsFilterBar($filters, $calendars); ?>

            <?php if ($events === []) : ?>
                <p class="ctp-empty-state">
                    <?php if ($stats['total'] === 0) : ?>
                        <?php esc_html_e('Noch keine Termine synchronisiert.', 'churchtools-plugin'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Keine Termine passen zu diesem Filter.', 'churchtools-plugin'); ?>
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <table class="widefat striped ctp-borderless ctp-events-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Titel', 'churchtools-plugin'); ?></th>
                            <th>
                                <?php
                                echo $isSeriesView
                                    ? esc_html__('Termine der Serie', 'churchtools-plugin')
                                    : esc_html__('Zeitraum', 'churchtools-plugin');
                                ?>
                            </th>
                            <th><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($isSeriesView) : ?>
                            <?php foreach ($events as $series) : ?>
                                <?php self::renderSeriesOverviewRow($series, $calendars); ?>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <?php $currentMonth = null; ?>
                            <?php foreach ($events as $event) : ?>
                                <?php $month = mysql2date('Y-m', $event['start_date']); ?>
                                <?php if ($month !== $currentMonth) : ?>
                                    <?php $currentMonth = $month; ?>
                                    <tr class="ctp-events-table__month">
                                        <th colspan="3" scope="colgroup">
                                            <?php echo esc_html(date_i18n('F Y', (int) mysql2date('U', $event['start_date']))); ?>
                                        </th>
                                    </tr>
                                <?php endif; ?>
                                <?php self::renderEventOverviewRow($event, $calendars); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php self::renderEventsPager($filters, $paged, $lastPage, $totalMatching); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Zeitraum / Kalender / Suche, as a plain GET form — no AJAX, so the
     * resulting URL is bookmarkable and the browser's back button works.
     * The calendar select lists every calendar known from the last fetch, not
     * just the enabled ones: rows for a calendar that was switched off are
     * still in the table until retention removes them, and being unable to
     * look at exactly those would defeat the point of this screen.
     */
    private static function renderEventsFilterBar(array $filters, array $calendars): void
    {
        $scopes = [
            'upcoming' => __('Kommende', 'churchtools-plugin'),
            'past' => __('Vergangene', 'churchtools-plugin'),
            'all' => __('Alle', 'churchtools-plugin'),
        ];
        uasort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="ctp-events-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(SettingsPage::areaSlug('events')); ?>" />
            <input type="hidden" name="tab" value="events" />

            <label class="screen-reader-text" for="ctp-events-scope"><?php esc_html_e('Zeitraum', 'churchtools-plugin'); ?></label>
            <select id="ctp-events-scope" name="scope">
                <?php foreach ($scopes as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['scope'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="ctp-events-calendar"><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></label>
            <select id="ctp-events-calendar" name="calendar_id">
                <option value="0"><?php esc_html_e('Alle Kalender', 'churchtools-plugin'); ?></option>
                <?php foreach ($calendars as $id => $calendar) : ?>
                    <option value="<?php echo esc_attr((string) $id); ?>" <?php selected($filters['calendar_id'], (int) $id); ?>>
                        <?php echo esc_html($calendar['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="ctp-events-search"><?php esc_html_e('Termine durchsuchen', 'churchtools-plugin'); ?></label>
            <input
                type="search"
                id="ctp-events-search"
                name="s"
                value="<?php echo esc_attr($filters['search']); ?>"
                placeholder="<?php esc_attr_e('Titel, Untertitel oder Ort …', 'churchtools-plugin'); ?>"
            />

            <label class="screen-reader-text" for="ctp-events-view"><?php esc_html_e('Ansicht', 'churchtools-plugin'); ?></label>
            <select id="ctp-events-view" name="view">
                <option value="series" <?php selected($filters['view'], 'series'); ?>>
                    <?php esc_html_e('Serien zusammengefasst', 'churchtools-plugin'); ?>
                </option>
                <option value="occurrences" <?php selected($filters['view'], 'occurrences'); ?>>
                    <?php esc_html_e('Einzeltermine', 'churchtools-plugin'); ?>
                </option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filtern', 'churchtools-plugin'); ?></button>
            <?php if ($filters['scope'] !== 'upcoming' || $filters['calendar_id'] > 0 || $filters['search'] !== '' || $filters['view'] !== 'series') : ?>
                <a class="button-link" href="<?php echo esc_url(self::eventsOverviewUrl()); ?>">
                    <?php esc_html_e('Zurücksetzen', 'churchtools-plugin'); ?>
                </a>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Prev/next plus "Seite X von Y", carrying the current filters through so
     * paging never silently resets them.
     */
    private static function renderEventsPager(array $filters, int $paged, int $lastPage, int $total): void
    {
        $pageUrl = static fn (int $page): string => self::eventsOverviewUrl([
            'scope' => $filters['scope'],
            'calendar_id' => $filters['calendar_id'] ?: null,
            's' => $filters['search'] !== '' ? $filters['search'] : null,
            'view' => $filters['view'] !== 'series' ? $filters['view'] : null,
            'paged' => $page > 1 ? $page : null,
        ]);
        ?>
        <div class="ctp-events-pager">
            <span class="ctp-muted-text">
                <?php
                printf(
                    /* translators: 1: current page number, 2: total number of pages, 3: number of matching rows, 4: what those rows are ("Serien" or "Termine") */
                    esc_html__('Seite %1$d von %2$d – %3$d %4$s', 'churchtools-plugin'),
                    (int) $paged,
                    (int) $lastPage,
                    (int) $total,
                    $filters['view'] === 'series'
                        ? esc_html__('Serien', 'churchtools-plugin')
                        : esc_html__('Termine', 'churchtools-plugin')
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

    private static function renderEventOverviewRow(array $event, array $calendars): void
    {
        $calendarId = (int) $event['ct_calendar_id'];
        $calendar = $calendars[$calendarId] ?? null;
        $calendarName = $calendar['name'] ?? sprintf('#%d', $calendarId);
        $isPast = $event['end_date'] < current_time('mysql');
        ?>
        <tr<?php echo $isPast ? ' class="ctp-events-table__row--past"' : ''; ?>>
            <td>
                <a href="<?php echo esc_url(self::eventDetailUrl((int) $event['id'])); ?>">
                    <?php echo esc_html($event['title']); ?>
                </a>
                <?php if (!empty($event['attachment_id'])) : ?>
                    <span class="dashicons dashicons-format-image ctp-row-icon" title="<?php esc_attr_e('Bild importiert', 'churchtools-plugin'); ?>"></span>
                <?php endif; ?>
                <?php if ($event['subtitle'] !== '') : ?>
                    <br /><span class="ctp-muted-text"><?php echo esc_html($event['subtitle']); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($event['all_day'])) : ?>
                    <?php echo esc_html(mysql2date(get_option('date_format'), $event['start_date'])); ?>
                    (<?php esc_html_e('ganztägig', 'churchtools-plugin'); ?>)
                <?php else : ?>
                    <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $event['start_date'])); ?>
                    &ndash;
                    <?php echo esc_html(mysql2date(get_option('time_format'), $event['end_date'])); ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($calendar['color'])) : ?>
                    <span class="ctp-cal-dot" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>
                <?php endif; ?>
                <?php echo esc_html($calendarName); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * One collapsed series: how many occurrences, the span they cover, and a
     * link into the occurrence view filtered to exactly this series — so
     * "collapsed" never means "unreachable".
     */
    private static function renderSeriesOverviewRow(array $series, array $calendars): void
    {
        $calendarId = (int) $series['ct_calendar_id'];
        $calendar = $calendars[$calendarId] ?? null;
        $calendarName = $calendar['name'] ?? sprintf('#%d', $calendarId);
        $count = (int) $series['occurrences'];
        $dateFormat = get_option('date_format');
        ?>
        <tr>
            <td>
                <a href="<?php echo esc_url(self::eventDetailUrl((int) $series['sample_id'])); ?>">
                    <?php echo esc_html($series['title']); ?>
                </a>
                <?php if (!empty($series['attachment_id'])) : ?>
                    <span class="dashicons dashicons-format-image ctp-row-icon" title="<?php esc_attr_e('Bild importiert', 'churchtools-plugin'); ?>"></span>
                <?php endif; ?>
                <?php if ($series['subtitle'] !== '' && $series['subtitle'] !== null) : ?>
                    <br /><span class="ctp-muted-text"><?php echo esc_html($series['subtitle']); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($count === 1) : ?>
                    <?php echo esc_html(mysql2date($dateFormat, $series['first_start'])); ?>
                <?php else : ?>
                    <?php
                    printf(
                        /* translators: 1: number of occurrences, 2: first date, 3: last date */
                        esc_html__('%1$d Termine, %2$s bis %3$s', 'churchtools-plugin'),
                        (int) $count,
                        esc_html(mysql2date($dateFormat, $series['first_start'])),
                        esc_html(mysql2date($dateFormat, $series['last_start']))
                    );
                    ?>
                    <br />
                    <a class="ctp-muted-text" href="<?php echo esc_url(self::eventsOverviewUrl(['view' => 'occurrences', 's' => $series['title']])); ?>">
                        <?php esc_html_e('Einzeltermine anzeigen', 'churchtools-plugin'); ?>
                    </a>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($calendar['color'])) : ?>
                    <span class="ctp-cal-dot" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>
                <?php endif; ?>
                <?php echo esc_html($calendarName); ?>
            </td>
        </tr>
        <?php
    }

    private static function eventDetailUrl(int $id): string
    {
        return SettingsPage::tabUrl('events', ['event_id' => $id]);
    }

    /**
     * @param array<string, string|int|null> $extra Filter state to carry along;
     *        null entries are dropped so a default-valued filter doesn't end up
     *        in the URL.
     */
    public static function eventsOverviewUrl(array $extra = []): string
    {
        $args = ['page' => SettingsPage::areaSlug('events'), 'tab' => 'events'];

        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== '') {
                $args[$key] = $value;
            }
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Detail view for a single synced occurrence, reached by clicking a title in
     * render(). Shows every field the sync stores, including ones the
     * public frontend template deliberately leaves out (description, raw image_url)
     * — this is an admin-only, manage_options-gated view, not public output.
     */
    public static function renderEventDetail(int $id): void
    {
        $event = (new EventRepository())->find($id);
        $backUrl = self::eventsOverviewUrl();

        if ($event === null) {
            printf('<div class="ctp-panel"><p>%s</p>', esc_html__('Termin nicht gefunden.', 'churchtools-plugin'));
            printf(
                '<p class="ctp-back-link"><a href="%s"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>%s</a></p></div>',
                esc_url($backUrl),
                esc_html__('Zurück zur Übersicht', 'churchtools-plugin')
            );

            return;
        }

        $calendarId = (int) $event['ct_calendar_id'];
        $calendar = Settings::get()['calendars'][$calendarId] ?? null;

        // Prefer the imported WP attachment over the raw ChurchTools image_url — see
        // EventListRenderer::withCalendarMeta() for why (avoids hotlinking the
        // ChurchTools domain from the admin's browser too).
        $attachmentId = (int) ($event['attachment_id'] ?? 0);
        $displayImageUrl = $attachmentId > 0 ? wp_get_attachment_image_url($attachmentId, 'large') : false;
        if ($displayImageUrl === false) {
            $displayImageUrl = $event['image_url'];
        }
        ?>
        <p class="ctp-back-link">
            <a href="<?php echo esc_url($backUrl); ?>">
                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                <?php esc_html_e('Zurück zur Übersicht', 'churchtools-plugin'); ?>
            </a>
        </p>
        <div class="ctp-panel">
            <h2 class="ctp-event-detail-title">
                <?php echo esc_html($event['title']); ?>
                <?php if ($event['subtitle'] !== '') : ?>
                    <span class="ctp-event-detail-subtitle">
                        <?php echo esc_html($event['subtitle']); ?>
                    </span>
                <?php endif; ?>
            </h2>

            <?php if ($displayImageUrl !== '') : ?>
                <p>
                    <img
                        src="<?php echo esc_url($displayImageUrl); ?>"
                        alt=""
                        class="ctp-event-detail-image"
                    />
                </p>
            <?php endif; ?>

            <table class="widefat striped ctp-borderless ctp-keyvalue-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Zeitraum', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php if (!empty($event['all_day'])) : ?>
                                <?php echo esc_html(mysql2date(get_option('date_format'), $event['start_date'])); ?>
                                (<?php esc_html_e('ganztägig', 'churchtools-plugin'); ?>)
                            <?php else : ?>
                                <?php
                                $dateTimeFormat = get_option('date_format') . ' ' . get_option('time_format');
                                echo esc_html(mysql2date($dateTimeFormat, $event['start_date']));
                                ?>
                                &ndash;
                                <?php echo esc_html(mysql2date($dateTimeFormat, $event['end_date'])); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Kalender', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php if (!empty($calendar['color'])) : ?>
                                <span class="ctp-cal-dot" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php echo esc_html($calendar['name'] ?? sprintf('#%d', $calendarId)); ?>
                        </td>
                    </tr>
                    <?php if ($event['location'] !== '') : ?>
                        <tr>
                            <th><?php esc_html_e('Ort', 'churchtools-plugin'); ?></th>
                            <td><?php echo esc_html($event['location']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($event['description'] !== '') : ?>
                        <tr>
                            <th><?php esc_html_e('Beschreibung', 'churchtools-plugin'); ?></th>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EventFormatter::descriptionHtml() runs the raw value through wp_kses() with its own allowlist before adding any markup of its own (see its docblock). ?>
                            <td><?php echo EventFormatter::descriptionHtml($event['description']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('ChurchTools-ID', 'churchtools-plugin'); ?></th>
                        <td><?php echo (int) $event['ct_event_id']; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
