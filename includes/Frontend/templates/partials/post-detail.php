<?php

/**
 * Inhalt des Beitrags-Popups (post-grid.php, post-featured.php) - aufgebaut
 * wie partials/group-detail.php, mit denselben Klassen wie die Detailansicht
 * eines Termins. Unter dem Text stehen die weiteren Bilder des Beitrags; das
 * erste steht oben wie auf der Kachel.
 *
 * Die Kennung des Titels bekommt einen Zusatz: Das Template landet als Kopie
 * im Dialog, waehrend die Kachel mit ihrer eigenen Kennung stehen bleibt.
 *
 * @var array  $ctpPost
 * @var string $titleId
 */

use ChurchToolsPlugin\Frontend\CardImage;
use ChurchToolsPlugin\Frontend\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$ctpDetailTitleId = $titleId . '-detail';
?>
<div class="ctp-events__detail ctp-groups__detail ctp-posts__detail<?php echo $ctpPost['image_src'] === '' ? ' ctp-events__detail--no-media' : ''; ?>">
    <?php if ($ctpPost['image_src'] !== '') : ?>
        <div class="ctp-events__detail-media">
            <div class="ctp-events__detail-media-frame">
                <img
                    src="<?php echo esc_url($ctpPost['image_src']); ?>"
                    <?php if ($ctpPost['image_srcset_full'] !== '') : ?>
                        srcset="<?php echo esc_attr($ctpPost['image_srcset_full']); ?>"
                        sizes="<?php echo esc_attr(CardImage::detailSizes()); ?>"
                    <?php endif; ?>
                    alt=""
                    class="skip-lazy"
                    data-no-lazy="1"
                    loading="eager"
                />
            </div>
        </div>
    <?php endif; ?>
    <div class="ctp-events__detail-heading">
        <h2 class="ctp-events__detail-title" id="<?php echo esc_attr($ctpDetailTitleId); ?>">
            <?php echo esc_html($ctpPost['title']); ?>
        </h2>
    </div>
    <?php if ($ctpPost['date_label'] !== '') : ?>
        <p class="ctp-events__meta-item ctp-events__meta-item--date">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
            <?php echo Icons::calendar(); ?>
            <?php echo esc_html($ctpPost['date_label']); ?>
        </p>
    <?php endif; ?>
    <?php if ($ctpPost['group_label'] !== '') : ?>
        <p class="ctp-events__meta-item ctp-events__meta-item--group">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
            <?php echo Icons::person(); ?>
            <?php echo esc_html($ctpPost['group_label']); ?>
        </p>
    <?php endif; ?>
    <?php if ($ctpPost['description_html'] !== '') : ?>
        <div class="ctp-events__detail-description">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EventFormatter::descriptionHtml() runs the raw value through wp_kses() with its own allowlist before adding any markup of its own (see its docblock). ?>
            <?php echo $ctpPost['description_html']; ?>
        </div>
    <?php endif; ?>
    <?php if ($ctpPost['gallery'] !== []) : ?>
        <div class="ctp-posts__gallery">
            <?php foreach ($ctpPost['gallery'] as $image) : ?>
                <img
                    src="<?php echo esc_url($image['src']); ?>"
                    <?php if ($image['srcset'] !== '') : ?>
                        srcset="<?php echo esc_attr($image['srcset']); ?>"
                        sizes="<?php echo esc_attr(CardImage::detailSizes()); ?>"
                    <?php endif; ?>
                    alt=""
                    class="skip-lazy"
                    data-no-lazy="1"
                    loading="eager"
                />
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
