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
