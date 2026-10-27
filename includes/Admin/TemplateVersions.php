<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

/**
 * Versionsangaben der ueberschreibbaren Vorlagen: Jede mitgelieferte Vorlage
 * traegt im Kopf `@version x.y.z`, die Version, in der sie sich zuletzt so
 * geaendert hat, dass eine Kopie im Theme nachziehen sollte. Eine Kopie mit
 * kleinerer oder ohne Angabe gilt als veraltet.
 *
 * Anlass war 1.28.0: Die Gruppenkacheln bekamen ihren Button nach ChurchTools
 * ueber die Vorlage, ein Theme mit eigener group-grid.php bekam ihn nicht -
 * und nichts im Backend sagte das.
 *
 * Wer eine Vorlage aendert und Kopien betroffen sind, hebt die Zeile auf die
 * kommende Version an. Wer eine Kopie mit dem Original abgeglichen hat, hebt
 * die Zeile in der Kopie an; dann verschwindet der Hinweis.
 */
final class TemplateVersions
{
    /** Die Vorlagen, die ein Theme unter churchtools-plugin/ ueberschreiben kann. */
    public const TEMPLATES = [
        'event-list.php',
        'event-grid.php',
        'event-upcoming.php',
        'event-detail.php',
        'group-grid.php',
        'group-featured.php',
        'post-grid.php',
        'post-featured.php',
    ];

    /**
     * Die Angabe aus dem Kopf einer Vorlage, null ohne lesbare Angabe. Gelesen
     * werden nur die ersten 8 KB - die Zeile steht im Docblock ganz oben.
     */
    public static function read(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 8192);

        return preg_match('/^[ \t\/*#]*@version[ \t]+(\d+\.\d+\.\d+)[ \t]*$/m', $head, $match) === 1 ? $match[1] : null;
    }

    public static function bundledVersion(string $file): string
    {
        return (string) self::read(CTP_PLUGIN_DIR . 'includes/Frontend/templates/' . $file);
    }

    /**
     * Kopien im Theme, die aelter sind als die mitgelieferte Vorlage.
     *
     * @param callable(string): string|null $locate Zum Testen; sonst locate_template() unter churchtools-plugin/.
     * @return list<array{file: string, path: string, theme: ?string, plugin: string}>
     */
    public static function outdated(?callable $locate = null): array
    {
        $locate ??= static fn (string $file): string => locate_template('churchtools-plugin/' . $file);
        $found = [];

        foreach (self::TEMPLATES as $file) {
            $path = $locate($file);

            if ($path === '') {
                continue;
            }

            $plugin = self::bundledVersion($file);
            $theme = self::read($path);

            if ($theme === null || version_compare($theme, $plugin, '<')) {
                $found[] = ['file' => $file, 'path' => $path, 'theme' => $theme, 'plugin' => $plugin];
            }
        }

        return $found;
    }
}
