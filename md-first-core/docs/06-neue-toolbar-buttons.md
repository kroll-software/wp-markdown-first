# Konzept: Neue Toolbar-Buttons (Markdown-spezifisch)

Stand: v1.3.17 (Batch 1 final = 1 Button: Footnote)

**Status: IMPLEMENTIERT** — Footnote-Button `¹` nach dem Zitat-Button, direkte
Textoperation (KEIN Dialog), Auswahl erforderlich (v1.3.12-Regel), Definition am
Dokumentende, N = max bestehende Referenz + 1. Verifiziert: JS-Logik (6 Tests gegen
CM-Stub), Renderer (fn-Ref + Anchor + Body), i18n-MO (WP-Loader, de_DE 3/3).

## Korrektur (User-Review 2026-11-24)
- ~~Codeblock~~ — bereits da: `qt_content_code` rendert Mehrzeilenauswahl als ``` fence (codeAction)
- ~~Strikethrough~~ — bereits da: `qt_content_del`
- ~~Math~~ — LaTeX wird in der Pipeline direkt unterstützt, nichts zu tun
→ **Batch 1 = nur Footnote.**

## Footnote (einziger Button)

### Entscheidung: KEIN Popup-Dialog
Begründung:
1. Alle 13 Buttons sind direkte Textoperationen (wrap / lines / Platzhalter).
   Dialoge existieren nur dort, wo WP sie wegen VIELFELDiger Structur hat
   (Link: URL+Text+Target; Bild: URL+Alt) — Footnote hat genau EIN Feld:
   Text. Und der ist im Editor ohnehin editierbar (undo inkludiert).
2. Widerspräche der etablierten Minimalismusklausel
   (ins/more/close/li alle aus der Toolbar entfernt).
3. Bestehendes Selektion/Platzhalter-Muster (codeAction, wrap) erledigt es
   in ~30 JS-Zeilen ohne neues Modal, ohne zusätzliche i18n-Oberfläche.

### Verhalten (FootnoteAction, Muster = codeAction)
1. `N` = nächstfreie Nummer: `max(matchAll(/\[\^(\d+)\]/g über cm.getValue())) + 1`
2. **Mit Auswahl:** Auswahl → `[^N]` am Selektionsort; Definition `[^N]: <Auswahl>`
   wird ans Dokumentende angehängt (klassische Footnote-Konvention,
   Pandoc-kompatibel); Definition wird selektiert (User korrigiert direkt).
3. **Ohne Auswahl: passiert GAR NICHTS** (v1.3.12-Regel — Klick-Tor greift bereits;
   Button ohne Selektion tut, was alle anderen Formatierungs-Buttons tun: nichts).
4. Dokumentende-Anhang = `\n\n`-Trennung sicherstellen; keine
   Absatzgrenzen-Parsing nötig (bewusst einfach).

### Renderer-Status
`ParsedownExtra` rendert Footnotes nativ; `repair_footnote_anchors()`
bereitet `#fn:KEY`-Anker bereits vor (war für genau dieses Feature gebaut)
→ **keine** Renderer-/Server-Arbeit.

### UI
- Button-Symbol: `¹`, ID `mdf_footnote`, Klasse `ed_button button button-small`
  (wie Undo/Redo), Platzierung: nach `qt_content_block` (Zitat)
- Tooltip: EN `Footnote: inserts [^N] at the cursor, definition at the end of the post`
  DE: `Fußnote: setzt [^N] an den Cursor und die Definition ans Post-Ende`
- i18n: 1 String → PO-Einträge (de + de_DE) + MO-Recompile

### Version & Verifikation
- v1.3.17, Bundle-Key `1.3.17-footnote`
- `php -l` + `node --check`; server-seitig: Footnote-Snippet rendern →
  HTML muss `footnote-ref` + `<li id="fn:` enthalten; E2E im Editor auf Anfrage.

## Phase 2 (bewusst aufgeschoben, begründet)
| Kandidat | Warum warten |
|----------|-------------|
| Taskliste `- [ ]` | ParsedownExtra rendert `[ ]` als Literaltext → eigene Renderer-Regel = separater Arbeitsschritt |
| Callout/Note, Definitionen | erst nach Taskliste / auf Anfrage |
| Spoiler, Mermaid, `<details>` | nicht MD-nativ → raus |

## Offene Frage
(ersetzt durch User-Entscheidung: Footnote ohne Dialog — oben.)
