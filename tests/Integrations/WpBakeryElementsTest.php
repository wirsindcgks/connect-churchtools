<?php

declare(strict_types=1);

namespace {
    if (!function_exists('vc_map')) {
        /** Faengt die Element-Definitionen ein, statt sie an WPBakery zu geben. */
        function vc_map(array $settings): void
        {
            $GLOBALS['ctp_test_vc_map'][$settings['base']] = $settings;
        }
    }

    if (!function_exists('shortcode_atts')) {
        /** Wie in WordPress: nur bekannte Attribute, sonst der Standardwert. */
        function shortcode_atts(array $pairs, $atts, string $shortcode = ''): array
        {
            $atts = (array) $atts;
            $out = [];
            foreach ($pairs as $name => $default) {
                $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
            }

            return $out;
        }
    }
}

namespace ChurchToolsPlugin\Tests\Integrations {
    use ChurchToolsPlugin\Frontend\Shortcode;
    use ChurchToolsPlugin\Groups\GroupSettings;
    use ChurchToolsPlugin\Groups\GroupSync;
    use ChurchToolsPlugin\Integrations\WpBakeryIntegration;
    use ChurchToolsPlugin\Posts\PostSettings;
    use ChurchToolsPlugin\Posts\PostSync;
    use ChurchToolsPlugin\Settings;
    use PHPUnit\Framework\TestCase;

    /**
     * Die WPBakery-Elemente „ChurchTools Events" und „ChurchTools Gruppen" -
     * einheitlich aufgebaut (Nutzerwunsch 2026-09-15). Ein echtes WPBakery
     * laeuft in den Tests nicht; geprueft wird die Definition, die die
     * Elemente an vc_map() geben, die Ausgabe der Auswahlfelder und dass der
     * Shortcode, den WPBakery daraus speichert, das Erwartete zeigt.
     */
    final class WpBakeryElementsTest extends TestCase
    {
        protected function setUp(): void
        {
            ctp_test_reset_options();
            ctp_test_reset_attachments();
            $GLOBALS['ctp_test_vc_map'] = [];
        }

        public function testTheElementOffersTheFinderInTheGridOnly(): void
        {
            (new WpBakeryIntegration())->mapShortcode();

            $params = array_column($GLOBALS['ctp_test_vc_map']['ctp_groups']['params'], null, 'param_name');

            $this->assertArrayHasKey('finder', $params);
            $this->assertSame('checkbox', $params['finder']['type']);
            $this->assertSame('Gruppenfinder anzeigen', $params['finder']['heading']);
            $this->assertSame(['Anzeigen' => '1'], $params['finder']['value']);
            $this->assertSame(['element' => 'layout', 'value' => 'grid'], $params['finder']['dependency']);
        }

        /**
         * Ohne Reiter: Mit `group` ging im WPBakery der Live-Seite eine
         * ungespeicherte Auswahl beim Wechsel des Reiters verloren (1.32.0).
         */
        public function testNeitherElementUsesTabs(): void
        {
            (new WpBakeryIntegration())->mapShortcode();

            foreach (['ctp_events', 'ctp_groups', 'ctp_posts'] as $base) {
                foreach ($GLOBALS['ctp_test_vc_map'][$base]['params'] as $param) {
                    $this->assertArrayNotHasKey('group', $param, $base . ': ' . $param['param_name']);
                }
            }
        }

        /** Die Kalender kommen in dieselbe Auswahlliste wie die Gruppen, statt als Textfeld mit IDs. */
        public function testCalendarsArePickedLikeGroups(): void
        {
            ctp_test_set_option(Settings::OPTION_KEY, ['calendars' => [
                3 => ['name' => 'Gottesdienste', 'color' => '', 'enabled' => true],
                7 => ['name' => 'Jugend', 'color' => '', 'enabled' => true],
            ]]);
            (new WpBakeryIntegration())->mapShortcode();

            $param = array_column($GLOBALS['ctp_test_vc_map']['ctp_events']['params'], null, 'param_name')['calendar'];

            $this->assertSame(WpBakeryIntegration::CALENDAR_PICKER_TYPE, $param['type']);
            $this->assertSame(['Gottesdienste' => '3', 'Jugend' => '7'], $param['ctp_choices']);
            $this->assertSame('Gottesdienste, Jugend', (new WpBakeryIntegration())->adminLabelValue('3,7', $param, $GLOBALS['ctp_test_vc_map']['ctp_events']));
        }

        /**
         * Von Hand geschriebene Shortcodes nennen Kalender beim Namen. Ein
         * bekannter Name wird zur ID; ein unbekannter bleibt stehen und wird
         * mitgespeichert, statt beim ersten Speichern zu verschwinden.
         */
        public function testCalendarNamesAreRecognisedAndUnknownOnesKept(): void
        {
            ctp_test_set_option(Settings::OPTION_KEY, ['calendars' => [
                3 => ['name' => 'Gottesdienste', 'color' => '', 'enabled' => true],
                7 => ['name' => 'Jugend', 'color' => '', 'enabled' => true],
            ]]);

            $html = WpBakeryIntegration::renderCalendarPicker(['param_name' => 'calendar'], 'gottesdienste, Chor, 99');

            $this->assertStringContainsString('class="wpb_vc_param_value calendar ctp_calendar_picker_field" value="3,99,Chor"', $html);
            $this->assertStringContainsString('value="3" data-name="Gottesdienste" checked="checked"', $html);
            $this->assertStringContainsString('value="7" data-name="Jugend" />', $html);
            $this->assertStringContainsString('Chor (nicht gefunden)', $html);
            $this->assertStringContainsString('data-extra="Chor"', $html);
            $this->assertStringContainsString('#99 (nicht mehr verfügbar)', $html);
            $this->assertStringContainsString('ctp-wpb-picker--unordered', $html, 'Termine stehen nach Datum - keine Reihenfolge, keine Pfeile.');
        }

        public function testAnEmptyCalendarPickerMeansAllCalendars(): void
        {
            $html = WpBakeryIntegration::renderCalendarPicker(['param_name' => 'calendar'], '');

            $this->assertStringContainsString('value="" />', $html);
            $this->assertStringContainsString('Noch keine Kalender geladen.', $html);
            $this->assertStringNotContainsString('ctp-wpb-picker__empty" hidden', $html);
        }

        /**
         * Das Beitrags-Element: dieselben Felder wie Shortcode und Block,
         * die Gruppen in derselben Auswahl wie die Kalender - ohne
         * Reihenfolge, Beitraege stehen nach Datum.
         */
        public function testThePostsElementOffersGroupsLayoutColumnsAndLimit(): void
        {
            ctp_test_set_current_time('2026-10-07 12:00:00');
            ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => true]);
            ctp_test_set_option(PostSync::DATA_OPTION, ['posts' => [
                ['id' => 1, 'title' => 'A', 'group_id' => 44, 'group_name' => 'Seniorenarbeit', 'expires' => null, 'images' => []],
                ['id' => 2, 'title' => 'B', 'group_id' => 31, 'group_name' => 'Jugend', 'expires' => null, 'images' => []],
            ]]);
            (new WpBakeryIntegration())->mapShortcode();

            $settings = $GLOBALS['ctp_test_vc_map']['ctp_posts'];
            $params = array_column($settings['params'], null, 'param_name');

            $this->assertSame(['groups', 'layout', 'columns', 'limit'], array_keys($params));
            $this->assertSame(WpBakeryIntegration::POST_GROUP_PICKER_TYPE, $params['groups']['type']);
            $this->assertSame(['Jugend' => '31', 'Seniorenarbeit' => '44'], $params['groups']['ctp_choices']);
            $this->assertSame('Seniorenarbeit, Jugend', (new WpBakeryIntegration())->adminLabelValue('44,31', $params['groups'], $settings));
            $this->assertTrue($params['limit']['save_always']);

            $html = WpBakeryIntegration::renderPostGroupPicker(['param_name' => 'groups'], '44,99');
            $this->assertStringContainsString('ctp-wpb-picker--unordered', $html);
            $this->assertStringContainsString('#99 (nicht mehr verfügbar)', $html);
        }

        /**
         * WPBakery schreibt die Beschriftung ungefiltert in den Baustein, und
         * Gruppen- wie Homepage-Namen pflegen in ChurchTools auch Leute ohne
         * Rechte in WordPress (Sicherheits-Review 2026-10-07).
         */
        public function testTheElementLabelIsEscaped(): void
        {
            ctp_test_set_current_time('2026-10-07 12:00:00');
            ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => true]);
            ctp_test_set_option(PostSync::DATA_OPTION, ['posts' => [
                ['id' => 1, 'title' => 'A', 'group_id' => 44, 'group_name' => '<img src=x onerror=alert(1)>', 'expires' => null, 'images' => []],
            ]]);
            ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
                9 => ['name' => '<script>alert(2)</script>', 'hash' => 'AbC123', 'enabled' => true],
            ]]);
            (new WpBakeryIntegration())->mapShortcode();
            $integration = new WpBakeryIntegration();

            $posts = $GLOBALS['ctp_test_vc_map']['ctp_posts'];
            $groupsParam = array_column($posts['params'], null, 'param_name')['groups'];
            $groups = $GLOBALS['ctp_test_vc_map']['ctp_groups'];
            $homepageParam = array_column($groups['params'], null, 'param_name')['homepage'];

            $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $integration->adminLabelValue('44', $groupsParam, $posts));
            $this->assertSame('&lt;script&gt;alert(2)&lt;/script&gt;', $integration->adminLabelValue('<script>alert(2)</script>', $homepageParam, $groups));
            $this->assertSame('&lt;b&gt;', $integration->adminLabelValue('<b>', ['param_name' => 'calendar', 'ctp_choices' => []], $GLOBALS['ctp_test_vc_map']['ctp_events']));
        }

        /** Die Gruppenauswahl behaelt ihre Reihenfolge. */
        public function testTheGroupPickerStaysOrdered(): void
        {
            $this->assertStringNotContainsString('ctp-wpb-picker--unordered', WpBakeryIntegration::renderGroupPicker(['param_name' => 'groups'], ''));
        }

        /** Im Baustein steht „Ja" wie bei den Ankreuzfeldern des Termin-Elements, nicht „1". */
        public function testTheElementLabelReadsAsText(): void
        {
            (new WpBakeryIntegration())->mapShortcode();
            $settings = $GLOBALS['ctp_test_vc_map']['ctp_groups'];
            $param = array_column($settings['params'], null, 'param_name')['finder'];

            $this->assertSame('Ja', (new WpBakeryIntegration())->adminLabelValue('1', $param, $settings));
        }

        /** So speichert WPBakery das angekreuzte Feld - und so kommt der Finder an. */
        /**
         * `finder` heisst bei beiden Shortcodes gleich; bei den Terminen gilt
         * der aeltere Name `eventfinder` weiter.
         */
        public function testEventsAcceptFinderLikeGroupsAndKeepEventfinder(): void
        {
            $this->assertTrue(Shortcode::finderEnabled(['finder' => '1', 'eventfinder' => '0']));
            $this->assertTrue(Shortcode::finderEnabled(['finder' => '0', 'eventfinder' => '1']));
            $this->assertFalse(Shortcode::finderEnabled(['finder' => '0', 'eventfinder' => '0']));
        }

        public function testTheSavedShortcodeShowsTheFinder(): void
        {
            ctp_test_set_option(GroupSettings::OPTION_KEY, ['homepages' => [
                9 => ['name' => 'Kleingruppen', 'hash' => 'AbC123', 'enabled' => true],
            ]]);
            ctp_test_set_option(GroupSync::DATA_OPTION, [9 => ['fetched' => '', 'empty_runs' => 0, 'filters' => ['weekday'], 'groups' => [
                ['id' => 1, 'name' => 'Chor', 'note' => '', 'image_url' => '', 'weekday' => 'Montag', 'meeting_time' => '', 'target_group' => '', 'max_members' => null, 'free_places' => null, 'waitinglist' => false, 'url' => '#'],
                ['id' => 2, 'name' => 'Hauskreis', 'note' => '', 'image_url' => '', 'weekday' => 'Freitag', 'meeting_time' => '', 'target_group' => '', 'max_members' => null, 'free_places' => null, 'waitinglist' => false, 'url' => '#'],
            ]]]);

            $shortcode = new Shortcode();
            $with = $shortcode->renderGroups(['source' => 'homepage', 'homepage' => 'Kleingruppen', 'finder' => '1']);
            $without = $shortcode->renderGroups(['source' => 'homepage', 'homepage' => 'Kleingruppen']);

            $this->assertStringContainsString('ctp-groups__finder', $with);
            $this->assertStringContainsString('data-ctp-group-value="Freitag"', $with);
            $this->assertStringNotContainsString('ctp-groups__finder', $without);
        }
    }
}
