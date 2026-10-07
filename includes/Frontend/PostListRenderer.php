<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

use ChurchToolsPlugin\Posts\PostSync;
use ChurchToolsPlugin\Settings;

/**
 * Beitraege als Kacheln, fuer [ctp_posts], den Block und das WPBakery-Element.
 *
 * Gebaut nach den Gruppenkacheln (Nutzerwunsch 2026-10-07: „vom Layout an
 * das Design der Gruppenkacheln"): dieselben Klassen, damit Vorlage, Farben,
 * Ecken, Bildformat und Reihenfolge aus „Einstellungen → Design" ohne eigene
 * Einstellungen greifen, dieselben zwei Darstellungen (`grid`, `featured`),
 * derselbe Weg zum ganzen Text (Klick auf die Kachel oeffnet das Popup).
 *
 * Von den ausblendbaren Feldern gelten die, die es an einem Beitrag gibt:
 * Bild, Datum (das Veroeffentlichungsdatum), Auszug - und „Kalendername"
 * fuer den Namen der Gruppe, aus demselben Grund wie die Zielgruppe bei den
 * Gruppen: Er sagt, in welche Sparte etwas gehoert.
 *
 * Einen Absprung nach ChurchTools gibt es als Button nicht: Ein Beitrag hat
 * dort keine oeffentliche Seite, die Beitragsansicht verlangt eine Anmeldung.
 * Ohne JavaScript fuehrt der Ausloeser der Kachel deshalb zur oeffentlichen
 * Seite der Gruppe, aus der der Beitrag stammt.
 */
final class PostListRenderer
{
    private const DEFAULT_COLUMNS = 3;
    private const MIN_COLUMNS = 2;
    private const MAX_COLUMNS = 6;

    /**
     * Wie viele Beitraege ohne Angabe erscheinen. Anders als bei Terminen
     * (Zeitfenster) und Gruppen (eine Homepage ist endlich) gibt es hier keine
     * natuerliche Grenze, und News auf einer Startseite sind wenige: zwei
     * Reihen zu drei Kacheln.
     */
    public const DEFAULT_LIMIT = 6;

    public const LAYOUTS = ['grid', 'featured'];

    /**
     * @param array{groups?: string, layout?: string, columns?: int|string, limit?: int|string} $args
     */
    public function render(array $args): string
    {
        $args = wp_parse_args($args, ['groups' => '', 'layout' => 'grid', 'columns' => self::DEFAULT_COLUMNS, 'limit' => self::DEFAULT_LIMIT]);

        $args['columns'] = min(self::MAX_COLUMNS, max(self::MIN_COLUMNS, (int) $args['columns']));
        $args['layout'] = in_array($args['layout'], self::LAYOUTS, true) ? $args['layout'] : 'grid';
        $args['limit'] = self::normalizeLimit($args['limit']);
        $args['instance'] = wp_unique_id('ctp-posts-');
        $args = array_merge($args, EventListRenderer::designArgs(Settings::get()));

        $posts = self::preparePosts(
            self::selectPosts((string) $args['groups'], $args['limit']),
            PostSync::imageMap(),
            $args['hidden_elements'],
            $args['layout'] === 'featured'
        );

        $file = $args['layout'] === 'featured' ? 'post-featured.php' : 'post-grid.php';
        $template = locate_template('churchtools-plugin/' . $file);
        if ($template === '') {
            $template = CTP_PLUGIN_DIR . 'includes/Frontend/templates/' . $file;
        }

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * 0 heisst „alle gespeicherten" wie `limit="0"` bei den Terminen; eine
     * leere oder unlesbare Angabe faellt auf DEFAULT_LIMIT zurueck.
     *
     * @param mixed $limit
     */
    public static function normalizeLimit($limit): int
    {
        if (is_string($limit) && trim($limit) === '') {
            return self::DEFAULT_LIMIT;
        }

        if (!is_numeric($limit) || (int) $limit < 0) {
            return self::DEFAULT_LIMIT;
        }

        return min((int) $limit, PostSync::MAX_POSTS);
    }

    /**
     * Die Beitraege der genannten Gruppen (IDs, wie `groups` bei [ctp_groups]),
     * ohne Angabe die aller Gruppen. Eine Angabe, aus der keine einzige ID
     * wird („abc"), ist eine misslungene Auswahl und nicht „alle".
     *
     * @return list<array>
     */
    public static function selectPosts(string $groups, int $limit): array
    {
        $ids = self::parseIds($groups);

        if ($ids === [] && trim($groups) !== '') {
            return [];
        }

        $posts = PostSync::postsFor($ids);

        return $limit > 0 ? array_slice($posts, 0, $limit) : $posts;
    }

    /**
     * „31, 44,abc" → [31, 44] - wie GroupSync::parseIds().
     *
     * @return int[]
     */
    public static function parseIds(string $raw): array
    {
        $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw) ?: []), static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }

    /**
     * Ergaenzt, was das Template anzeigt - wie GroupListRenderer::prepareGroups().
     *
     * Die Kachel zeigt das erste Bild, das Popup alle importierten. Die
     * Bildflaeche steht an jeder Kachel, sobald ein Beitrag der Liste ein Bild
     * hat, und an keiner, wenn keiner eins hat - dieselbe Regel wie bei den
     * Gruppen, damit die Reihen fluchten.
     *
     * @param list<array>        $posts
     * @param array<string, int> $imageMap Bildadresse => Attachment-ID
     *
     * @return list<array>
     */
    public static function preparePosts(array $posts, array $imageMap, array $hiddenElements = [], bool $featured = false): array
    {
        $prepared = [];
        $showImages = !in_array('media', $hiddenElements, true);

        foreach ($posts as $post) {
            $gallery = [];

            foreach ((array) ($post['images'] ?? []) as $url) {
                $attachmentId = is_string($url) ? (int) ($imageMap[$url] ?? 0) : 0;
                $src = $attachmentId > 0 && $showImages ? (string) wp_get_attachment_image_url($attachmentId, 'large') : '';

                if ($src !== '') {
                    $gallery[] = ['id' => $attachmentId, 'src' => $src, 'srcset' => CardImage::srcsetFor($attachmentId)];
                }
            }

            $first = $gallery[0] ?? null;
            $content = (string) ($post['content'] ?? '');

            $post['image_src'] = $first['src'] ?? '';
            $post['image_srcset'] = $first !== null
                ? CardImage::srcsetFor($first['id'], CardImage::CARD_MAX_SRCSET_WIDTH, CardImage::CARD_REFERENCE_SIZE)
                : '';
            $post['image_srcset_full'] = $first['srcset'] ?? '';
            // Die weiteren Bilder fuer das Popup; das erste steht dort schon oben.
            $post['gallery'] = array_slice($gallery, 1);
            $post['date_label'] = in_array('date', $hiddenElements, true) || (string) ($post['published'] ?? '') === ''
                ? ''
                : EventFormatter::shortDate((string) $post['published']);
            $post['group_label'] = in_array('calendar', $hiddenElements, true) ? '' : trim((string) ($post['group_name'] ?? ''));
            $post['excerpt'] = in_array('excerpt', $hiddenElements, true) || $content === ''
                ? ''
                : EventFormatter::excerpt($content, 24);
            $post['excerpt_html'] = $post['excerpt'] === '' || $featured ? '' : GroupListRenderer::excerptHtml($content);
            $post['feature_excerpt_html'] = $post['excerpt'] === '' || !$featured ? '' : GroupListRenderer::featureExcerptHtml($content);
            // Der volle Text fuer das Popup, auch wenn der Auszug auf der
            // Kachel ausgeblendet ist - dieselbe Aufbereitung wie eine
            // Terminbeschreibung (enge kses-Liste, klickbare Links,
            // verschleierte E-Mail-Adressen).
            $post['description_html'] = $content !== '' ? EventFormatter::descriptionHtml($content) : '';

            $prepared[] = $post;
        }

        $showMedia = $showImages && array_filter($prepared, static fn (array $post): bool => $post['image_src'] !== '') !== [];

        foreach ($prepared as &$post) {
            $post['show_media'] = $showMedia;
        }
        unset($post);

        return $prepared;
    }
}
