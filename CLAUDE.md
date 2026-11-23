# CLAUDE.md

Das Arbeits- und Kontextdokument dieses Repositorys ist [`plan.md`](plan.md) – dort stehen Stand, Konventionen und
Entscheidungen. Diese Datei hält nur fest, was ein Agent für wiederkehrende Abläufe braucht.

## Release

*Für den persönlichen Skill `/release` (seit 2026-11-23). Hintergrund in `plan.md`, „Konventionen“ → „Changelog“ und
„GitHub-basierte Plugin-Updates“.*

- **Konto:** `cgksmedia` (Admin der Organisation `wirsindcgks`), Repository `wirsindcgks/connect-churchtools`. Git pusht
  über den repo-lokalen Credential-Helper schon als `cgksmedia`; `gh` mit `GH_TOKEN=$(gh auth token -u cgksmedia)`.
- **Immer über einen Release-PR** (seit 2026-11-23; vorher direkte Commits „Release X.Y.Z“ auf `main`). Zweig
  `release/X.Y.Z`, Commit und PR `Release X.Y.Z`, **Squash-Merge** nach grüner CI (Ruleset „main – nur mit grüner CI“).
- **Versionsdateien** – alle im PR, schon im Endstand (`tests/Release/VersionConsistencyTest.php` hält sie zusammen):
  1. `churchtools-plugin.php`: Kopfzeile `Version:` **und** `define('CTP_VERSION', …)`.
  2. `readme.txt`: `Stable tag:`; unter `== Changelog ==` ein Abschnitt `= X.Y.Z =` mit Zeilen „* Neu: …“, „* Geändert: …“,
     „* Behoben: …“; unter `== Upgrade Notice ==` ein Abschnitt `= X.Y.Z =` **nur**, wenn Nutzer nach dem Update etwas wissen
     oder tun müssen.
  3. `languages/churchtools-plugin.pot`: `Project-Id-Version: ChurchTools Events X.Y.Z`.
  4. `update.json` – nicht von Hand: `php bin/make-update-json.php .` (liest Kopf, `CHANGELOG.md` und `readme.txt`).
- **Changelog:** `CHANGELOG.md`, Abschnitt `## [X.Y.Z] - JJJJ-MM-TT` (Bindestrich) ganz oben, Unterabschnitte `### Added`,
  `### Changed`, `### Fixed`, Einträge auf Deutsch. **Kein „Unreleased“** – der Reiter „Updates“ im Plugin liest die Datei.
- **Plan:** In `plan.md`, „Rahmendaten“, die Zeile „Aktueller Stand (…)“ auf Datum und Version.
- **Prüfen:** `composer test` (enthält die Versionsprüfung); bei Änderungen an Blöcken auch `npm run build`.
- **Tag:** `git tag vX.Y.Z` auf den Merge-Commit, pushen. `release.yml` baut `churchtools-plugin-vX.Y.Z.zip`, prüft, dass
  `update.json` auf diesen Tag zeigt, und hängt das ZIP ans Release.
- **Zügig taggen:** `update.json` auf `main` zeigt nach dem Merge schon auf das neue ZIP, und installierte Seiten lesen sie
  über `raw.githubusercontent.com`. Bis das Release steht, liefe ein Update ins Leere – also direkt nach dem Merge taggen
  und den Workflow abwarten.
- **Danach meldet der Agent:** Link aufs Release. Die Seiten holen sich das Update selbst (Tab „Updates“, „Nach Updates
  suchen“); hochladen muss niemand.
- **Nach der Auslieferung:** nichts Festes. Meldet der Nutzer, dass eine Seite die neue Version hat, genügt ein Satz in
  `plan.md`, wenn er es wünscht.

## Prüfung

*Für den persönlichen Agenten `pruefer` (seit 2026-11-23): Pflichtpunkte zusätzlich zu seinen allgemeinen Prüfungen.
Herkunft: `plan.md`, „Konventionen“ und „Sicherheits- und Datenschutz-Review vom 2026-09-14“.*

- **Was so bleiben muss** (Review 2026-09-14): SQL nur über `$wpdb->prepare()` (Bezeichner mit `%i`); jede Ausgabe
  escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`); jede Admin-Aktion mit Nonce **und** `manage_options`;
  Sanitizer als Allowlist, Name und Hash nie aus dem Formular; Hash prüfen, bevor er in einen Pfad geht; Bilder lokal
  über `download_url()` statt verlinkt; bei leerer API-Antwort den Bestand nicht leeren; Normalisierer übernehmen nur
  benannte Felder.
- **Token und Daten:** Der API-Key verlässt nie den ChurchTools-Host (keine Weiterleitungen, `'redirection' => 0`); keine
  Personen-IDs oder -Daten in Spalten, Logs oder Kontext, die niemand braucht; das Protokoll folgt den
  Datenschutzregeln der Klasse `Log`.
- **Lieferkette:** Jede installierte Kopie installiert, was `update.json` auf `main` nennt. Actions auf Commit-SHA gepinnt
  (Version als Kommentar), Workflow-Rechte so klein wie möglich, Schreibrecht nur im veröffentlichenden Job;
  `update.json` nur über `bin/make-update-json.php`; neue Abhängigkeiten (Composer, npm, Actions) begründen.
- **Kompatibilitätszusage** (`docs/COMPATIBILITY.md`, ab 2.0): Shortcodes, Attribute, Werte, Blöcke, Vorlagen und
  Hooks brechen nur in einer Hauptversion. Neue Attribute und Werte dort eintragen.
- **Vorlagen** unter `includes/Frontend/templates/` tragen `@version`; wer eine Vorlage ändert, hebt sie an.
- **Drei Wege, ein Verhalten:** Shortcode, Gutenberg-Block und WPBakery-Element nutzen dieselbe Rendering-Basis und
  bieten dieselben Optionen – eine neue Option kommt in alle drei.
- **Admin-UI:** ein eigener Reiter je Feature-Thema, mit eigenem Settings-Slug und eigenem `<form>`.
- **Texte:** übersetzbar mit der Textdomain `churchtools-plugin`; Oberfläche und Doku deutsch, Code englisch.
- **Öffentliches Repository:** keine Namen der realen Instanz, der Gemeinde oder ihrer Website, keine Keys, keine
  Instanz-Adressen – in Code, Tests, Doku und `plan.md`.
- **Tests und Doku:** neue Funktion → neuer Test; `composer lint`, `composer analyse`, `composer test` grün; Änderungen
  für Nutzer in `readme.txt` und `docs/*.md`, der Arbeitsstand in `plan.md`.

## Kommunikation

*Für den persönlichen Agenten `kommunikation` (seit 2026-11-23).*

- **Konto:** `gh` mit `GH_TOKEN=$(gh auth token -u cgksmedia)`, Repository `wirsindcgks/connect-churchtools`. Gepostet
  wird nur nach dem OK des Nutzers.
- **Sprache und Anrede:** Deutsch, „du“, mit Vornamen („Hey Josua,“); Gruß „Viele Grüße“ mit der Unterschrift „CG-KS“ –
  so sind die bisherigen Antworten.
- **Wertschätzend:** Wer etwas vorschlägt oder meldet, zeigt eine Lücke im Plugin, keinen eigenen Fehler. Erste
  Rückmeldung zeitnah, auch ohne Bauplan; wenn etwas erscheint, mit Version und Weg im Backend („Einstellungen →
  ChurchTools → …“) und dem Hinweis, dass das Update im Reiter „Updates“ ankommt.
- **Dank:** Ein umgesetzter Vorschlag bekommt im umsetzenden Commit ein `Co-authored-by` mit der noreply-Adresse
  (`gh api users/<login>`) und im Issue einen Dank. Ob auch das Changelog dankt, entscheidet der Nutzer – bisher tut es
  das nicht.
- **Vorsicht mit Zusagen:** Was die Kompatibilitätszusage betrifft (Shortcodes, Attribute, Vorlagen), nur zusagen, was
  in `docs/COMPATIBILITY.md` steht.
