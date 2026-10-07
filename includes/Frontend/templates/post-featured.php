<?php

/**
 * Hervorgehobene Beitraege ([ctp_posts layout="featured"]): je Beitrag eine
 * grosse Kachel, Bild neben dem Text - aufgebaut wie group-featured.php, mit
 * denselben Klassen. Gedacht fuer die neuesten ein, zwei Beitraege oben auf
 * einer Startseite. Ueberschreibbar durch eine Kopie unter
 * yourtheme/churchtools-plugin/post-featured.php.
 *
 * @var array $posts
 * @var array $args
 */

use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="ctp-events ctp-groups ctp-groups--featured ctp-posts ctp-posts--featured <?php echo esc_attr($args['design_class']); ?>"
    style="<?php echo esc_attr($args['design_style']); ?>"
>
    <?php if (empty($posts)) : ?>
        <p class="ctp-events__empty"><?php esc_html_e('Zurzeit gibt es keine Beiträge.', 'churchtools-plugin'); ?></p>
    <?php else : ?>
        <div class="ctp-groups__features" role="list">
            <?php foreach ($posts as $index => $ctpPost) : ?>
                <?php $titleId = $args['instance'] . '-' . (int) $index; ?>
                <div class="ctp-events__cell" role="listitem">
                <article class="ctp-events__card ctp-events__hero--clickable ctp-groups__feature ctp-posts__feature<?php echo $ctpPost['image_src'] === '' ? ' ctp-groups__feature--no-media' : ''; ?>">
                    <?php if ($ctpPost['image_src'] !== '') : ?>
                        <div class="ctp-events__media ctp-groups__feature-media">
                            <img
                                src="<?php echo esc_url($ctpPost['image_src']); ?>"
                                <?php if ($ctpPost['image_srcset'] !== '') : ?>
                                    srcset="<?php echo esc_attr($ctpPost['image_srcset']); ?>"
                                    sizes="(min-width: 46rem) 40vw, 100vw"
                                <?php endif; ?>
                                alt=""
                                loading="lazy"
                            />
                        </div>
                    <?php endif; ?>
                    <div class="ctp-events__content ctp-groups__feature-content">
                        <h3 class="ctp-events__title ctp-groups__feature-title">
                            <?php // Ein Verweis und kein Knopf: Ohne JavaScript fuehrt er zur Gruppe in ChurchTools, siehe post-grid.php. ?>
                            <a class="ctp-events__card-trigger" data-ctp-modal="1" href="<?php echo esc_url($ctpPost['group_url']); ?>">
                                <span id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html($ctpPost['title']); ?></span>
                            </a>
                        </h3>
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
                        <?php if ($ctpPost['feature_excerpt_html'] !== '') : ?>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- GroupListRenderer::featureExcerptHtml() runs the text through EventFormatter::descriptionHtml() (wp_kses() with its own allowlist, obfuscated mail addresses). ?>
                            <p class="ctp-events__excerpt"><?php echo $ctpPost['feature_excerpt_html']; ?></p>
                        <?php endif; ?>
                    </div>
                </article>
                <template class="ctp-events__detail-template"><?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/post-detail.php'; ?></template>
                </div>
            <?php endforeach; ?>
        </div>
        <?php require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/modal.php'; ?>
    <?php endif; ?>
</div>
