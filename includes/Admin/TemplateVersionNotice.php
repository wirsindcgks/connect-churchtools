<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

/**
 * Hinweis auf den Seiten des Plugins, wenn ein Theme eine aeltere Kopie einer
 * Vorlage benutzt (siehe TemplateVersions).
 *
 * Nicht wegklickbar: Er verschwindet, sobald die Kopie abgeglichen und ihre
 * Zeile `@version` angehoben ist - genau das soll er ausloesen. Nur auf den
 * Seiten des Plugins, weil dort sitzt, wer es betreut; auf dem Dashboard
 * staende er jedem Redakteur mit Adminrechten im Weg.
 */
final class TemplateVersionNotice
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options') || !self::isPluginScreen()) {
            return;
        }

        $outdated = TemplateVersions::outdated();

        if ($outdated === []) {
            return;
        }

        $items = array_map(static function (array $entry): string {
            return $entry['theme'] === null
                ? sprintf(
                    /* translators: 1: template file name, 2: plugin template version */
                    __('%1$s (Kopie ohne Versionsangabe, Plugin %2$s)', 'churchtools-plugin'),
                    $entry['file'],
                    $entry['plugin']
                )
                : sprintf(
                    /* translators: 1: template file name, 2: version of the theme copy, 3: plugin template version */
                    __('%1$s (Kopie %2$s, Plugin %3$s)', 'churchtools-plugin'),
                    $entry['file'],
                    $entry['theme'],
                    $entry['plugin']
                );
        }, $outdated);

        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
            esc_html__('Veraltete Vorlagen im Theme:', 'churchtools-plugin'),
            esc_html(implode(', ', $items)),
            esc_html__('Die Kopien unter churchtools-plugin/ im Theme mit den Originalen in includes/Frontend/templates/ des Plugins vergleichen und danach die Zeile @version in der Kopie anheben. Bis dahin fehlt auf der Website womöglich, was neuere Versionen über die Vorlage bringen.', 'churchtools-plugin')
        );
    }

    private static function isPluginScreen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen !== null && str_contains($screen->id, 'churchtools-plugin');
    }
}
