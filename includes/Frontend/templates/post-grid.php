<?php

/**
 * Kachelraster der Beitraege ([ctp_posts], Block, WPBakery). Ueberschreibbar
 * durch eine Kopie unter yourtheme/churchtools-plugin/post-grid.php.
 *
 * Aufgebaut wie group-grid.php, mit denselben Klassen, damit die
 * Einstellungen des Design-Tabs greifen; `ctp-posts` steht zusaetzlich daneben,
 * fuer eigene Regeln eines Themes. Die Zusatzfelder jedes Beitrags (image_src,
 * show_media, date_label, group_label, excerpt_html, description_html,
 * gallery) rechnet PostListRenderer::preparePosts() vor.
 *
 * Ein Klick auf die Kachel oeffnet den ganzen Beitrag im Popup
 * (partials/post-detail.php, partials/modal.php).
 *
 * @var array $posts
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="ctp-events ctp-events--grid ctp-groups ctp-posts <?php echo esc_attr($args['design_class']); ?>"
    style="--ctp-columns:<?php echo (int) $args['columns']; ?>;<?php echo esc_attr($args['design_style']); ?>"
>
    <?php if (empty($posts)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Zurzeit gibt es keine Beiträge.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <div class="ctp-events__list" role="list">
            <?php foreach ($posts as $index => $ctpPost) : ?>
                <?php $titleId = $args['instance'] . '-' . (int) $index; ?>
                <div class="ctp-events__cell" role="listitem">
                    <article class="ctp-events__card ctp-events__card--clickable ctp-groups__card ctp-posts__card">
                        <?php if ($ctpPost['show_media']) : ?>
                            <div class="ctp-events__media">
                                <?php if ($ctpPost['image_src'] !== '') : ?>
                                <img
                                    src="<?php echo esc_url($ctpPost['image_src']); ?>"
                                    <?php if ($ctpPost['image_srcset'] !== '') : ?>
                                        srcset="<?php echo esc_attr($ctpPost['image_srcset']); ?>"
                                        sizes="<?php echo esc_attr(CardImage::gridSizes((int) $args['columns'])); ?>"
                                    <?php endif; ?>
                                    alt=""
                                    loading="lazy"
                                />
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="ctp-events__content">
                            <span class="ctp-events__title">
                                <?php
                                // Ausloeser des Popups ueber die ganze Kachel. Ein Verweis und kein
                                // Knopf, wie bei den Gruppen: Ohne JavaScript fuehrt er zur Gruppe in
                                // ChurchTools - der Beitrag selbst hat dort keine oeffentliche Seite.
                                ?>
                                <a class="ctp-events__card-trigger" data-ctp-modal="1" href="<?php echo esc_url($ctpPost['group_url']); ?>">
                                    <span id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html($ctpPost['title']); ?></span>
                                </a>
                            </span>
                            <?php if ($ctpPost['date_label'] !== '') : ?>
                                <span class="ctp-events__meta-item ctp-events__meta-item--date">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                                    <?php echo Icons::calendar(); ?>
                                    <?php echo esc_html($ctpPost['date_label']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($ctpPost['group_label'] !== '') : ?>
                                <span class="ctp-events__meta-item ctp-events__meta-item--group">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::person(); ?>
                                    <?php echo esc_html($ctpPost['group_label']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($ctpPost['excerpt_html'] !== '') : ?>
                                <div class="ctp-events__excerpt ctp-groups__excerpt">
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- GroupListRenderer::excerptHtml() runs the text through EventFormatter::descriptionHtml() (wp_kses() with its own allowlist) or esc_html(). ?>
                                    <?php echo $ctpPost['excerpt_html']; ?>
                                </div>
                            <?php endif; ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup. ?>
                            <?php echo $args['design_separators']; ?>
                        </div>
                    </article>
                    <template class="ctp-events__detail-template"><?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/post-detail.php'; ?></template>
                </div>
            <?php endforeach; ?>
        </div>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/modal.php'; ?>
    <?php endif; ?>
</div>
