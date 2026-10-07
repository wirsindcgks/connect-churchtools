# Beiträge anzeigen

Zurück zur [Übersicht](../README.md).

Beiträge aus ChurchTools als Neuigkeiten auf der Website: Titel, Datum, Gruppe, ein kurzer Auszug und das erste Bild als Kachel, in derselben Optik wie die Gruppen. Ein Klick auf die Kachel öffnet den ganzen Beitrag mit allen Bildern im Popup.

<img src="screenshots/beitraege.png" width="600" alt="Drei Beitragskacheln mit Bild, Titel, Auszug, Datum und Gruppe">

## Was übernommen wird

**Nur Beiträge öffentlicher Gruppen**, und von denen nur die, die in ChurchTools für alle sichtbar sind, die die Gruppe sehen. Beiträge nur für Gruppenmitglieder und Beiträge aus internen, eingeschränkten oder versteckten Gruppen kommen nicht auf die Website, auch wenn der API-Key sie sehen darf. Dazu fallen heraus: abgelaufene Beiträge (Ablaufdatum in ChurchTools), gesperrte Beiträge und Beiträge, die aus einer anderen ChurchTools-Instanz eingebunden sind.

Übernommen werden Titel, Text, Veröffentlichungsdatum, Ablaufdatum, Gruppe und bis zu vier Bilder je Beitrag. **Keine Verfasser, Kommentare oder Reaktionen**: Sie nennen Personen.

Vorgehalten werden die neuesten 30 Beiträge.

## Einschalten

Beiträge sind ausgeschaltet, bis jemand sie einschaltet. Solange fragt das Plugin ChurchTools nicht nach Beiträgen.

1. *ChurchTools → Beiträge → Synchronisation*: **Beiträge öffentlicher Gruppen aus ChurchTools übernehmen** anhaken, Intervall wählen (standardmäßig stündlich) und speichern. Der erste Abgleich startet danach von selbst.
2. Unter *Beiträge → Beitragsliste* steht, was übernommen ist, mit der ID der Gruppe für den Shortcode.

Abgefragt wird mit dem API-Key aus *Einstellungen → Verbindung*. Der Key braucht für Beiträge keine eigenen Rechte. Den Benutzer dahinter am besten **in keine Gruppe aufnehmen**: Als Mitglied sähe er auch Beiträge nur für die Gruppe. Das Plugin filtert sie heraus, aber was der Key gar nicht sieht, kann auch nicht durchrutschen. Wer wieder ausschaltet, entfernt mit dem nächsten Lauf die übernommenen Beiträge samt Bildern. Auf der Website verschwinden sie schon sofort.

## Einbinden

**Block oder WPBakery:** „ChurchTools Beiträge“ einfügen. Ohne Haken bei den Gruppen erscheinen die Beiträge aller öffentlichen Gruppen. Dazu kommen die Ansicht „Raster“ oder „Hervorgehoben“, die Spaltenzahl und die Anzahl der Beiträge.

**Shortcode:**

```
[ctp_posts]
[ctp_posts layout="featured" limit="1"]
[ctp_posts groups="31,44" columns="2" limit="4"]
```

| Attribut | Bedeutung | Standard |
| --- | --- | --- |
| `groups` | Nur Beiträge dieser Gruppen, nach ID, kommagetrennt. Leer = alle öffentlichen Gruppen. | – |
| `limit` | Wie viele Beiträge erscheinen, die neuesten zuerst. `0` = alle gespeicherten. | `6` |
| `layout` | `grid` (Kachelraster mit Auszug) oder `featured` (je Beitrag eine große Kachel, Bild daneben) | `grid` |
| `columns` | Höchstens so viele Spalten (2–6), wie in den Inhaltsbereich passen. Nur bei `grid`. | `3` |

Fertige Beispiele mit den Gruppen der eigenen Instanz stehen unter *Beiträge → Einbinden*.

## Gut zu wissen

- **Aussehen wie Gruppen und Termine:** Vorlage, Farben, Ecken, Bildformat und Reihenfolge aus *Einstellungen → Design* gelten auch hier. Das Datum steht mit Kalendersymbol, die Gruppe darunter. Wer unter *Kachel* das Datum ausblendet, blendet das Veröffentlichungsdatum aus. Wer den Kalendernamen ausblendet, blendet die Gruppe aus.
- **Kein Button nach ChurchTools:** Ein Beitrag hat in ChurchTools keine öffentliche Seite, die Beitragsansicht dort verlangt eine Anmeldung. Ohne JavaScript führt die Kachel zur öffentlichen Seite der Gruppe.
- **Neue Beiträge** erscheinen spätestens nach dem eingestellten Intervall. *Jetzt synchronisieren* holt sie sofort.
- **Die Bilder sind Kopien** in der Mediathek, wie bei Terminen und Gruppen. Besucher laden nichts von ChurchTools.
- **Eine kurze Störung nimmt der Website nicht die Beiträge:** Liefert ChurchTools plötzlich gar keine Beiträge mehr, bleiben die zuletzt geladenen drei Läufe lang stehen.
