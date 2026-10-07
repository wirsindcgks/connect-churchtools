<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Frontend\PostListRenderer;
use ChurchToolsPlugin\Posts\PostSettings;
use ChurchToolsPlugin\Posts\PostSync;
use Throwable;

/**
 * Der Bereich „Beiträge" im Backend: Beitragsliste, Synchronisation,
 * Einbinden - dazu das Beitrags-Panel der Uebersicht.
 *
 * Aufgebaut wie der Bereich „Gruppen" (GroupsTab), nach derselben Regel
 * („Das Plugin soll sich egal ob Events oder Gruppen gleich verhalten",
 * 2026-09-15): dieselben Kacheln, Knoepfe, Hinweise und Leer-Meldungen. Ein
 * Reiter fuer die Auswahl fehlt, weil es nichts auszuwaehlen gibt - an seiner
 * Stelle steht der Schalter im Reiter „Synchronisation".
 */
final class PostsTab
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('wp_ajax_ctp_run_post_sync', [$this, 'ajaxRunSync']);
    }

    public function registerSetting(): void
    {
        register_setting(PostSettings::OPTION_GROUP, PostSettings::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [PostSettings::class, 'sanitize'],
            'default' => PostSettings::defaults(),
        ]);
    }

    /**
     * Was Statuszeilen und Uebersicht gemeinsam brauchen - das Gegenstueck zu
     * GroupsTab::facts().
     *
     * @return array{settings: array, enabled: bool, posts: list<array>, post_count: int, group_count: int, image_count: int, last_sync: string, last_sync_label: string, next_sync_label: string, next_sync_scheduled: bool, failed: bool, date_format: string}
     */
    private static function facts(): array
    {
        $settings = PostSettings::get();
        $posts = PostSync::visiblePosts();
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');
        $lastSync = (string) get_option(PostSync::LAST_SYNC_OPTION, '');
        $next = wp_next_scheduled(PostSync::HOOK);
        $imported = array_keys(PostSync::imageMap());
        $withImage = array_filter($posts, static fn (array $post): bool => array_intersect((array) ($post['images'] ?? []), $imported) !== []);

        return [
            'settings' => $settings,
            'enabled' => $settings['enabled'],
            'posts' => $posts,
            'post_count' => count($posts),
            'group_count' => count(PostSync::selectableGroups()),
            'image_count' => count($withImage),
            'last_sync' => $lastSync,
            'last_sync_label' => $lastSync !== ''
                ? (string) mysql2date($dateFormat, $lastSync)
                : __('noch nie', 'churchtools-plugin'),
            'next_sync_label' => $next !== false
                ? (string) wp_date($dateFormat, $next)
                : __('nicht geplant', 'churchtools-plugin'),
            'next_sync_scheduled' => $next !== false,
            'failed' => PostSync::getLastError() !== null,
            'date_format' => $dateFormat,
        ];
    }

    /** Gespeicherte, gerade sichtbare Beitraege - fuer die Kachel der Uebersicht. */
    public static function storedPostCount(): int
    {
        return count(PostSync::visiblePosts());
    }

    /**
     * Statuszeile der Beitragsliste - wie die der Gruppenliste.
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    public static function listCards(): array
    {
        $facts = self::facts();

        return [
            [
                'icon' => 'database',
                'value' => (string) $facts['post_count'],
                'label' => __('Gesamt', 'churchtools-plugin'),
            ],
            [
                'icon' => 'groups',
                'value' => (string) $facts['group_count'],
                'label' => __('Aus Gruppen', 'churchtools-plugin'),
            ],
            [
                'icon' => 'format-image',
                'value' => (string) $facts['image_count'],
                'label' => __('Mit importiertem Bild', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Statuszeile des Reiters „Synchronisation" - wie die der Gruppen, mit
     * dem Schalter vorn, weil er hier die Auswahl ersetzt.
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    public static function syncCards(): array
    {
        $facts = self::facts();

        return [
            [
                'icon' => 'yes-alt',
                'value' => $facts['enabled'] ? __('An', 'churchtools-plugin') : __('Aus', 'churchtools-plugin'),
                'label' => __('Synchronisation der Beiträge', 'churchtools-plugin'),
                // Nicht gelb, wenn aus: Die meisten Installationen zeigen keine
                // Beitraege, und das ist kein Fehler (wie bei den Homepages).
                'tone' => $facts['enabled'] ? 'ok' : '',
            ],
            [
                'icon' => 'update',
                'value' => $facts['last_sync_label'],
                'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                'tone' => $facts['enabled'] ? SettingsPage::lastSyncTone($facts['failed'], $facts['last_sync']) : '',
            ],
            [
                'icon' => 'clock',
                'value' => $facts['next_sync_label'],
                'label' => sprintf(
                    /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                    __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                    SettingsPage::syncIntervalLabels()[$facts['settings']['sync_interval']] ?? $facts['settings']['sync_interval']
                ),
                // Ausgeschaltet gibt es bewusst keinen Zeitplan (siehe
                // Installer::ensureSchedules()) - das ist dann nicht rot.
                'tone' => $facts['next_sync_scheduled'] || !$facts['enabled'] ? '' : 'error',
            ],
            [
                'icon' => 'list-view',
                'value' => (string) $facts['post_count'],
                'label' => __('Gespeicherte Beiträge', 'churchtools-plugin'),
            ],
        ];
    }

    /** Der Satz, der ueberall steht, solange der Abgleich aus ist. */
    private static function renderDisabledHint(): void
    {
        printf(
            /* translators: %s: link to the "Synchronisation" tab of the posts area */
            esc_html__('Es werden keine Beiträge übernommen. Unter %s lässt sich die Synchronisation einschalten.', 'churchtools-plugin'),
            '<a href="' . esc_url(SettingsPage::tabUrl('post_sync')) . '">' . esc_html__('Beiträge → Synchronisation', 'churchtools-plugin') . '</a>'
        );
    }

    /**
     * Der Reiter „Synchronisation": Schalter, Intervall, Knopf und Befund -
     * aufgebaut wie GroupsTab::renderSync(), mit dem Schalter als erstem Feld.
     */
    public static function renderSync(): void
    {
        $settings = PostSettings::get();
        $fieldName = PostSettings::OPTION_KEY . '[enabled]';
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
                <?php settings_fields(PostSettings::OPTION_GROUP); ?>
                <h2><?php esc_html_e('Sync-Einstellungen', 'churchtools-plugin'); ?></h2>

                <p class="description">
                    <?php esc_html_e('Übernimmt die Beiträge aus ChurchTools als Neuigkeiten für die Website – aber nur Beiträge öffentlicher Gruppen, die dort für alle sichtbar sind, die die Gruppe sehen. Übernommen werden Titel, Text, Datum, Gruppe und Bilder – keine Verfasser, Kommentare oder Reaktionen.', 'churchtools-plugin'); ?>
                </p>

                <?php if ($settings['enabled']) : ?>
                    <?php
                    SettingsPage::renderSyncHead(
                        'ctp-run-post-sync',
                        SyncHealthNotice::postProblem(),
                        PostSync::getImageWarning(),
                        'posts'
                    );
                    ?>
                <?php endif; ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Beiträge synchronisieren', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php // Verstecktes Feld vor dem Kaestchen, damit das Ausschalten speicherbar ist (siehe Reiter „Räume"). ?>
                            <input type="hidden" name="<?php echo esc_attr($fieldName); ?>" value="0">
                            <label for="ctp-post-sync-enabled">
                                <input type="checkbox" id="ctp-post-sync-enabled" name="<?php echo esc_attr($fieldName); ?>" value="1" <?php checked($settings['enabled']); ?>>
                                <?php esc_html_e('Beiträge öffentlicher Gruppen aus ChurchTools übernehmen', 'churchtools-plugin'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Ausgeschaltet fragt das Plugin ChurchTools nicht nach Beiträgen. Wer ausschaltet, entfernt mit dem nächsten Lauf die übernommenen Beiträge samt Bildern.', 'churchtools-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Sync-Intervall', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php
                            SettingsPage::renderIntervalSelect(
                                PostSettings::OPTION_KEY . '[sync_interval]',
                                $settings['sync_interval'],
                                sprintf(
                                    /* translators: %d: maximum number of posts kept (30) */
                                    __('Neue Beiträge erscheinen spätestens nach diesem Intervall auf der Website. Vorgehalten werden die neuesten %d.', 'churchtools-plugin'),
                                    PostSync::MAX_POSTS
                                )
                            );
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Der Reiter „Beitragsliste": was gerade gespeichert ist und auf der
     * Website erscheinen kann - das Gegenstueck zur Gruppenliste. Nur Anzeige;
     * geaendert wird ein Beitrag in ChurchTools.
     */
    public static function renderList(): void
    {
        $facts = self::facts();
        $stored = PostSync::storedData();
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Gespeicherte Beiträge', 'churchtools-plugin'); ?></h2>
            <?php if (!$facts['enabled']) : ?>
                <p class="ctp-empty-state"><?php self::renderDisabledHint(); ?></p>
            <?php else : ?>
                <p class="description">
                    <?php if ((string) ($stored['fetched'] ?? '') === '') : ?>
                        <?php esc_html_e('Noch nicht synchronisiert.', 'churchtools-plugin'); ?>
                    <?php else : ?>
                        <?php
                        printf(
                            // „Beiträge: 1" statt „1 Beiträge" - bin/make-pot.php kennt keine Plurale.
                            /* translators: 1: number of posts, 2: date and time of the last successful fetch */
                            esc_html__('Beiträge: %1$d, Stand %2$s.', 'churchtools-plugin'),
                            (int) $facts['post_count'],
                            esc_html(mysql2date($facts['date_format'], (string) $stored['fetched']))
                        );
                        ?>
                    <?php endif; ?>
                    <?php if ((int) ($stored['empty_runs'] ?? 0) > 0) : ?>
                        <?php esc_html_e('ChurchTools liefert zurzeit keine Beiträge; die zuletzt geladenen bleiben vorerst stehen.', 'churchtools-plugin'); ?>
                    <?php endif; ?>
                </p>

                <?php $posts = PostListRenderer::preparePosts($facts['posts'], PostSync::imageMap()); ?>
                <?php if ($posts === []) : ?>
                    <p class="ctp-empty-state"><?php esc_html_e('ChurchTools hat keine öffentlichen Beiträge geliefert.', 'churchtools-plugin'); ?></p>
                <?php else : ?>
                    <table class="widefat striped ctp-borderless ctp-events-table ctp-group-list">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Datum', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Beitrag', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Gruppe', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Läuft ab', 'churchtools-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts as $post) : ?>
                                <tr>
                                    <td><?php echo esc_html($post['date_label']); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($post['title']); ?></strong>
                                        <?php if ($post['image_src'] !== '') : ?>
                                            <span class="dashicons dashicons-format-image ctp-row-icon" title="<?php esc_attr_e('Bild importiert', 'churchtools-plugin'); ?>"></span>
                                        <?php endif; ?>
                                        <?php if ($post['excerpt'] !== '') : ?>
                                            <br /><span class="ctp-muted-text"><?php echo esc_html($post['excerpt']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php // Die ID ist das, was `groups` im Shortcode erwartet - hier liest man sie ab. ?>
                                        <a href="<?php echo esc_url($post['group_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($post['group_name']); ?></a>
                                        <code><?php echo (int) $post['group_id']; ?></code>
                                    </td>
                                    <td>
                                        <?php echo ($post['expires'] ?? null) !== null ? esc_html(wp_date(get_option('date_format'), (int) $post['expires'])) : '&ndash;'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Das Panel „Beiträge" auf der Uebersicht - wie GroupsTab::renderOverviewPanel():
     * ausgeschaltet ein Satz und ein Verweis, sonst Knopf, Befund, Zahlen, Links.
     */
    public static function renderOverviewPanel(): void
    {
        $facts = self::facts();
        $problem = SyncHealthNotice::postProblem();
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Beiträge', 'churchtools-plugin'); ?></h2>
            <?php if (!$facts['enabled']) : ?>
                <p class="description"><?php self::renderDisabledHint(); ?></p>
            <?php else : ?>
                <?php
                SettingsPage::renderActionBar(
                    'ctp-run-post-sync',
                    __('Jetzt synchronisieren', 'churchtools-plugin'),
                    __('Holt die Beiträge öffentlicher Gruppen sofort, unabhängig vom Intervall.', 'churchtools-plugin')
                );
                ?>
                <?php if ($problem !== null) : ?>
                    <div class="notice notice-<?php echo esc_attr($problem['type']); ?> inline">
                        <p><?php echo esc_html($problem['message']); ?></p>
                    </div>
                <?php endif; ?>
                <?php SettingsPage::renderImageWarning(PostSync::getImageWarning(), 'posts'); ?>
                <?php
                SettingsPage::renderOverviewRows([
                    [
                        'label' => __('Gespeicherte Beiträge', 'churchtools-plugin'),
                        'value' => (string) $facts['post_count'],
                    ],
                    [
                        'label' => __('Aus Gruppen', 'churchtools-plugin'),
                        'value' => (string) $facts['group_count'],
                    ],
                    [
                        'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                        'value' => $facts['last_sync_label'],
                    ],
                    [
                        'label' => sprintf(
                            /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                            __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                            SettingsPage::syncIntervalLabels()[$facts['settings']['sync_interval']] ?? $facts['settings']['sync_interval']
                        ),
                        'value' => $facts['next_sync_label'],
                    ],
                ]);

                SettingsPage::renderQuicklinks([
                    ['url' => SettingsPage::tabUrl('post_list'), 'icon' => 'list-view', 'label' => __('Gespeicherte Beiträge ansehen', 'churchtools-plugin')],
                    ['url' => SettingsPage::tabUrl('post_sync'), 'icon' => 'update', 'label' => __('Sync-Einstellungen', 'churchtools-plugin')],
                    ['url' => SettingsPage::tabUrl('post_embed'), 'icon' => 'editor-code', 'label' => __('Beiträge einbinden', 'churchtools-plugin')],
                ]);
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Der Reiter „Einbinden" - aufgebaut wie der der Gruppen: erst die Wege,
     * dann fertige Shortcodes mit echten IDs dieser Instanz, dann alle
     * Attribute.
     */
    public static function renderEmbed(): void
    {
        $groups = PostSync::selectableGroups();
        $examples = [];

        if (PostSettings::isEnabled()) {
            $examples[] = ['label' => __('Die neuesten Beiträge aller öffentlichen Gruppen', 'churchtools-plugin'), 'code' => '[ctp_posts]'];
            $examples[] = ['label' => __('Der neueste Beitrag, groß', 'churchtools-plugin'), 'code' => '[ctp_posts layout="featured" limit="1"]'];

            $firstId = array_key_first($groups);

            if ($firstId !== null) {
                $examples[] = [
                    /* translators: %s: name of a ChurchTools group */
                    'label' => sprintf(__('Nur Beiträge der Gruppe „%s“', 'churchtools-plugin'), $groups[$firstId]),
                    'code' => sprintf('[ctp_posts groups="%d" columns="2"]', (int) $firstId),
                ];
            }
        }
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Drei Wege, dieselbe Darstellung', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Beiträge lassen sich per Shortcode, über den Gutenberg-Block „ChurchTools Beiträge“ oder über das WPBakery-Element „ChurchTools Beiträge“ einbinden. Alle drei zeigen dieselben Kacheln wie die Gruppen; Vorlage, Farben und Ecken kommen aus „Einstellungen → Design“.', 'churchtools-plugin'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Ein Klick auf die Kachel öffnet den ganzen Beitrag mit allen Bildern im Popup. In ChurchTools selbst hat ein Beitrag keine öffentliche Seite; einen Button dorthin gibt es deshalb nicht.', 'churchtools-plugin'); ?>
            </p>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Beispiele zum Kopieren', 'churchtools-plugin'); ?></h2>
            <?php if ($examples === []) : ?>
                <div class="notice notice-info inline">
                    <p><?php self::renderDisabledHint(); ?></p>
                </div>
            <?php else : ?>
                <ul class="ctp-shortcode-examples">
                    <?php foreach ($examples as $example) : ?>
                        <li>
                            <span class="ctp-shortcode-label"><?php echo esc_html($example['label']); ?></span>
                            <code><?php echo esc_html($example['code']); ?></code>
                            <button type="button" class="button button-small ctp-copy-shortcode" data-shortcode="<?php echo esc_attr($example['code']); ?>">
                                <?php esc_html_e('Kopieren', 'churchtools-plugin'); ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Alle Attribute', 'churchtools-plugin'); ?></h2>
            <table class="widefat striped ctp-borderless">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Attribut', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Beschreibung', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Standard', 'churchtools-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>groups</code></td>
                        <td><?php esc_html_e('Nur Beiträge dieser Gruppen, nach ID, kommagetrennt (IDs stehen unter „Beiträge → Beitragsliste“). Leer = alle öffentlichen Gruppen.', 'churchtools-plugin'); ?></td>
                        <td>&ndash;</td>
                    </tr>
                    <tr>
                        <td><code>limit</code></td>
                        <td>
                            <?php
                            printf(
                                /* translators: %d: maximum number of posts kept (30) */
                                esc_html__('Wie viele Beiträge erscheinen, die neuesten zuerst. 0 = alle gespeicherten (höchstens %d).', 'churchtools-plugin'),
                                (int) PostSync::MAX_POSTS
                            );
                            ?>
                        </td>
                        <td><code><?php echo (int) PostListRenderer::DEFAULT_LIMIT; ?></code></td>
                    </tr>
                    <tr>
                        <td><code>layout</code></td>
                        <td><?php esc_html_e('grid: Kachelraster mit Auszug. featured: je Beitrag eine große Kachel, Bild daneben.', 'churchtools-plugin'); ?></td>
                        <td><code>grid</code></td>
                    </tr>
                    <tr>
                        <td><code>columns</code></td>
                        <td><?php esc_html_e('Höchstens so viele Spalten (2–6), wie in den Inhaltsbereich passen – je Kachel mindestens 240px. Nur bei grid.', 'churchtools-plugin'); ?></td>
                        <td><code>3</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function ajaxRunSync(): void
    {
        check_ajax_referer('ctp_run_post_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        try {
            PostSync::runNow();
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }

        wp_send_json_success();
    }
}
