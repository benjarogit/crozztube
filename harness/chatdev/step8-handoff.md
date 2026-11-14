# Fortsetzung: Schritt 8 — Serien und Staffeln

Quelle: aktive Harness, Bestandsaufnahme durch die echte Projektmanager-
Agentensitzung am 14.11.2026. Dies ist eine Übergabe, keine Abnahme.

Schritte 1–7 bleiben abgenommen. Serieninformationen fehlen noch; die ältere
Add-to-Series-Funktion schreibt nur video.playlist_title. Diese Inhalte dürfen
nicht entfernt oder stillschweigend migriert werden. Als Nächstes erweitern:

1. Bestehende Playlist und Video-Pivot um optionalen Serien-/Staffel-/Folgenkontext.
2. Zugriff über SharedPlaylists, PlaylistPolicy und VideoAccessService bewahren.
3. Vorhandenen Watch-Playlistkontext ?list=, sort_order und Queue wiederverwenden.
4. Nächste verfügbare Folge nur nach Rechteprüfung; manuelle Queue hat Vorrang,
   vorhandenes Autoplay bleibt ausschlaggebend.
5. Fehlenden step-08-series-Check mit echten Abnahmeprüfungen implementieren,
   bevor eine vollständige Verifikation/Abnahme möglich ist.

## Tatsächliche Veröffentlichungsgrenze

Die aktuelle Harness bindet normale verify/checkpoint/complete-Aufrufe an main.
Der separat freigegebene ChatDev-Pfad erlaubt ausschließlich Werkzeugdateien.
Produktcode auf codex/chatdev-integration wäre unzulässig; ein Main-Merge ist
nicht freigegeben. Für einen separaten Serien-Feature-PR fehlt daher ein
freigegebener Veröffentlichungsweg. Das ist keine fehlende Produktfreigabe:
die Fortsetzung selbst wurde ausdrücklich genehmigt.

Konkreter Vorschlag zur gesonderten Freigabe: ein auf codex/series-seasons und
Schritt 8 begrenzter Feature-Pfad mit identischer Quell-/Indexbindung,
Geheimnisprüfung, bisherigen checkpointChecks und bestätigtem Push; vollständige
Abnahme zusätzlich nur mit implementiertem step-08-series-Check und echter
Runtime-Hook-Evidenz. Keine Annahme späterer Schritte und kein Main-Merge.
Bis zur Freigabe dürfen Analyse/Testfälle und eine lokale prüfbare Umsetzung
vorbereitet, jedoch keine Produktänderungen über den Werkzeugpfad veröffentlicht
oder die bestehende main-Sperre umgangen werden.
