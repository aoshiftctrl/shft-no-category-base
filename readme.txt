=== CatRmove ===
Contributors: aoshiftctrl
Tags: kategorie, permalinks, rewrite, weiterleitung, seo
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.3.0
Requires PHP: 8.0
License: CC BY-ND 4.0
License URI: https://creativecommons.org/licenses/by-nd/4.0/

Entfernt den Kategorie-Präfix aus Kategorie-URLs (/category/aktuelles/ → /aktuelles/) und leitet alte URLs per 301 um.

== Description ==

Schlankes Plugin ohne Einstellungen. Es entfernt den Kategorie-Präfix aus allen Kategorie-Links und leitet alte URLs dauerhaft um.

* Kategorie-Links werden ohne Präfix ausgegeben.
* Je Kategoriepfad gibt es eine explizite Rewrite-Regel, inklusive Feed, Embed und Pagination. Es gibt keinen Catch-all: Seiten, Beiträge und alle anderen URLs bleiben unberührt.
* Alte URLs unter dem Kategorie-Präfix werden per 301 auf die neue URL umgeleitet, inklusive Feeds, Pagination und Query-String.
* Bei der Permalink-Struktur `/%category%/%postname%/` werden alte Beitrags-URLs (/category/aktuelles/mein-beitrag/) auf den kanonischen Permalink umgeleitet.
* Funktioniert mit eigenem Kategorie-Präfix, mit `/index.php/`-Permalinks und mit Slugs in nicht-lateinischer Schrift (z. B. /новости/).

= Schutz vor Kollisionen =

Ist die URL ohne Präfix schon vergeben, gewinnt der bestehende Inhalt. Die Kategorie behält dann ihren Präfix (z. B. /category/kontakt/) und bleibt darüber erreichbar. Ihr Slug wird nicht verändert. Das gilt in diesen Fällen:

* Eine veröffentlichte Seite oder ein veröffentlichter Beitrag hat denselben Permalink.
* Das erste Pfadsegment gehört WordPress: Feeds, `embed`, `page`, `comments`, `search`, `author`, `wp-json`, andere Taxonomien (z. B. `tag`), Archive von Custom Post Types, der Kategorie-Präfix selbst oder rein numerische Pfade. Das gilt dann auch für alle Unterkategorien.

Geprüft wird bei jedem Aufruf, nicht beim Speichern. Es spielt also keine Rolle, ob zuerst die Kategorie oder die Seite da war. Entwürfe und private Inhalte zählen nicht. Welche Kategorien ihren Präfix behalten, steht unter *Beiträge → Kategorien* und *Einstellungen → Permalinks*.

= Technik =

* Filter `category_rewrite_rules` (Priorität 20): In Cores Kategorie-Regeln wird der Platzhalter `category/(.+?)` durch eine Regel je Kategoriepfad ersetzt. Änderungen anderer Plugins an diesen Regeln bleiben erhalten, z. B. Endpoints.
* Redirect-Regel `category/(.+)$` mit dem internen Parameter `shft_category_redirect`. Er ist kein öffentlicher Query-Var und lässt sich nicht per GET/POST setzen.
* Umgeleitet wird nur bei GET/HEAD im Frontend. Bei POST wird die Kategorie direkt ausgeliefert.
* Kollisionsprüfung zur Laufzeit: eine Abfrage nach veröffentlichten Seiten und Beiträgen, deren Slug dem letzten Segment eines Kategoriepfads entspricht. Verglichen wird der Permalink. Das Ergebnis liegt im Object Cache (Gruppe `shft_ncb`) und wird nach Änderungen an Beiträgen oder Kategorien neu berechnet.
* Trifft eine Kategorie-Regel die URL einer Seite oder eines Beitrags, setzt der Filter `request` die Query auf diesen Inhalt (`page_id` bzw. `p`). Feed, Embed und Pagination bleiben erhalten.
* Kategorien auf von WordPress belegten Pfaden bekommen keine Rewrite-Regel.
* Bei Änderungen an Kategorien werden die Rewrite-Regeln einmal am Ende des Requests neu erzeugt.
* Fehlt die Redirect-Regel in den gespeicherten Regeln, werden sie beim nächsten Request neu erzeugt, höchstens einmal pro Stunde (Transient `shft_ncb_healed`).
* Bei der Deaktivierung entfernt das Plugin seine Filter, bevor es die gespeicherten Regeln verwirft.
* Das Plugin speichert keine eigenen Optionen.

== Installation ==

1. Den Ordner `shft-no-category-base` nach `wp-content/plugins/` kopieren.
2. Das Plugin unter „Plugins“ aktivieren.
3. Fertig, es gibt keine Einstellungen.

== Frequently Asked Questions ==

= Was passiert, wenn ich das Plugin deaktiviere? =

Die Rewrite-Regeln werden ohne das Plugin neu erzeugt. Kategorie-URLs haben dann wieder den Präfix.

= Funktioniert das Plugin mit Polylang? =

Das ist nicht getestet. Bekannt ist: Alte URLs mit Sprachverzeichnis (/en/category/news/) werden nicht auf die neue URL umgeleitet.

= Warum hat eine Kategorie noch den Präfix? =

Die URL ohne Präfix ist schon vergeben: an eine veröffentlichte Seite, an einen veröffentlichten Beitrag oder an WordPress selbst (z. B. /feed/). Der bestehende Inhalt behält seine URL, die Kategorie ihren Präfix. Die betroffenen Kategorien stehen unter *Beiträge → Kategorien*. Mit einem anderen Slug für die Kategorie oder die Seite entfällt der Präfix.

= Warum zeigt /news/rss/ einen Feed statt der Unterkategorie „rss“? =

Unterkategorien mit den Slugs `feed`, `rdf`, `rss`, `rss2`, `atom` oder `embed` sind nicht erreichbar, weil die URL dem Feed bzw. der Einbettung der Elternkategorie gehört. Das ist auch ohne das Plugin so. Solche Slugs für Unterkategorien vermeiden.

= Funktioniert das Plugin in einer Multisite? =

Ja, mit zwei Einschränkungen. Nach einer netzwerkweiten Deaktivierung bleiben die Regeln der anderen Websites gespeichert. Dort jeweils einmal *Einstellungen → Permalinks* speichern, sonst zeigen Kategorie-URLs die Startseite. Legt Code per `switch_to_blog()` Kategorien auf einer anderen Website an, werden deren Regeln nicht neu erzeugt. Auch dann dort die Permalinks speichern.

== Changelog ==

= 1.3.0 =
* Geändert: Das Plugin heißt jetzt CatRmove.
* Geändert: Kollisionen werden nicht mehr über einen Slug-Zusatz (`-2`) gelöst. Ist die URL ohne Präfix schon vergeben, gewinnt der bestehende Inhalt und die Kategorie behält ihren Präfix. Slugs, die schon einen Zusatz haben, bleiben unverändert.
* Sicherheit: Der bisherige Slug-Schutz ließ sich über eine ungültige Elternkategorie umgehen. Die Prüfung läuft jetzt bei jedem Aufruf und hängt nicht mehr von den Formulardaten ab.
* Behoben: Eine Seite oder ein Beitrag, die nach der Kategorie mit demselben Pfad angelegt wurden, sind erreichbar.
* Behoben: Bei Permalinks mit festem Präfix (z. B. `/blog/%postname%/`) werden Kollisionen mit Unterseiten erkannt.
* Behoben: Alte URLs mit erfundenen Zwischensegmenten liefern 404, auch mit kodiertem Schrägstrich (`%2F`).
* Behoben: Alte URLs wie /category/-/ leiten nicht mehr auf eine beliebige Kategorie um.
* Behoben: Kategorie-URLs mit Großbuchstaben (/News/) finden die Kategorie wieder, statt im 404 zu enden.
* Geändert: Entwürfe und private Inhalte zählen nicht mehr als Kollision.
* Geändert: Eine Kategorie mit dem Slug des Präfixes (z. B. `category`) behält samt Unterkategorien ihren Präfix.

= 1.2.0 =
* Sicherheit: Kategorien auf oberster Ebene können keine WordPress-Pfade mehr belegen (Feeds, `embed`, `search`, `wp-json`, andere Taxonomien, Archive von Custom Post Types, numerische Pfade). Solche Slugs bekommen automatisch einen Zusatz.
* Neu: Bei `/%category%/%postname%/` werden auch Kollisionen von Unterkategorien mit Beiträgen erkannt.
* Neu: Wird eine Elternkategorie umbenannt, verschoben oder gelöscht, werden alle Unterkategorien auf Kollisionen geprüft.
* Neu: Änderungen anderer Plugins an den Kategorie-Regeln bleiben erhalten, z. B. Sprachpräfixe von Polylang und Endpoints.
* Behoben: Kategorien mit Slugs in nicht-lateinischer Schrift sind wieder in jeder Schreibweise erreichbar (kodiert mit großen Hex-Ziffern oder dekodiert).
* Behoben: Eine Unterkategorie mit Feed-Namen (z. B. news/rss) wird nicht mehr als Feed der Elternkategorie umgeleitet.
* Behoben: Bei POST auf alte URLs gehen Seitennummer, Feed und Embed nicht mehr verloren.
* Behoben: Ein vom Eindeutigkeits-Check vergebener Slug wird erneut auf Kollisionen geprüft.
* Behoben: Nach der Deaktivierung bleiben keine Plugin-Regeln mehr in der Datenbank zurück.

= 1.1.0 =
* Sicherheit: Der interne Redirect-Parameter lässt sich nicht mehr per GET/POST setzen (verhindert Redirect-Schleifen und Cache-Poisoning).
* Sicherheit: Kategorie-Slugs, die eine bestehende Seite oder einen Beitrag verdecken würden, bekommen automatisch einen Zusatz.
* Sicherheit: Alte URLs mit erfundenen Zwischensegmenten liefern jetzt 404 statt einer Weiterleitung.
* Sicherheit: `function_exists()`-Wrapper entfernt, damit sich fremde Funktionen gleichen Namens nicht unbemerkt einklinken.
* Neu: Weiterleitung alter Beitrags-URLs unter dem Kategorie-Präfix (`/%category%/%postname%/`).
* Behoben: Der Query-String wird sauber neu aufgebaut und mit `&` angehängt, wenn die Ziel-URL schon einen hat.
* Behoben: Weiterleitungen nur bei GET/HEAD im Frontend, POST-Daten gehen nicht mehr verloren.
* Verbessert: Bei Änderungen an Kategorien werden die Rewrite-Regeln einmal pro Request neu erzeugt statt bei jedem parallelen Request.

= 1.0.0 =
* Erste Version.

== Upgrade Notice ==

= 1.3.0 =
Nach dem Update einmal Einstellungen → Permalinks speichern. Kategorien, deren Pfad schon einer Seite, einem Beitrag oder WordPress gehört, haben danach wieder die URL mit Präfix.

= 1.2.0 =
Nach dem Update einmal Einstellungen → Permalinks aufrufen und speichern, damit die neuen Rewrite-Regeln greifen.

= 1.1.0 =
Sicherheits-Härtung. Für alle empfohlen.
