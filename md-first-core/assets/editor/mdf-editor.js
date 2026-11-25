/**
 * MD-First Core — Markdown-Editor (CodeMirror 5)
 *
 * - Custom-Modus 'text/x-mdf': GFM + Math-Spans ($…$ inline, $$…$$ block,
 *   \Befehle als Commands) — rein optisch, ändert den Text nie.
 * - Tab-UI: Markup | Vorschau (Vorschau = Mdf_Renderer::render via admin-post).
 * - Keine Konvertierung: bei Speichern landet exakt der eingequate Text
 *   im nativen Textfeld und so im Datenbankfeld post_content.
 *
 * Abhängigkeiten: codemirror.min.js, mode_gfm_gfm.js, Inline-Global `MDF`.
 */
(function () {
	'use strict';
	if (typeof CodeMirror === 'undefined' || typeof MDF === 'undefined') { return; }

	/* ─────────────────────────── Custom-Modus ───────────────────────────
	 * v1.3.18: GFM-Basis läuft mit highlightFormatting (fertige CM5-Funktion) →
	 * Marker-Tokens cm-formatting-* . Zwei Token-Gaps gibt es in keinem fertigen
	 * CM5-Markdown-Modus, die füllen wir per minimalem Overlay (CodeMirror's
	 * fertige overlayMode-Utility) selbst:
	 *   - mdf-fencelang: Sprachtag nach ``` (z. B. "lean")
	 *   - mdf-pipe:     Tabellen-Pipes |
	 * Beide sind rein optisch; sie ändern den Text nie.
	 */

	CodeMirror.defineMode('text/x-mdmark', function () {
		var openRe = /^\s*(`{3,}|~{3,})(.*)$/;
		return {
			startState: function () { return { fence: null }; },
			token: function (stream, state) {
				// ── Inside Codeblock: nur Pipes einfärben ──
				if (state.fence !== null) {
					if (stream.sol()) {
						var closeRe = new RegExp('^\\s*' + state.fence + '{3,}\\s*$');
						if (closeRe.test(stream.string)) {
							state.fence = null;   /* schließende Fence */
							stream.skipToEnd();
							return null;
						}
						var ch = stream.string.charAt(stream.string.match(/^\s*/)[0].length);
						if (ch === '`' || ch === '~') {
							/* Fence-Zeile anderer Art: Block für uns zu Ende (Basis entscheidet). */
							state.fence = null;
							stream.skipToEnd();
							return null;
						}
					}
					stream.eatWhile(/[^|]/);
					if (stream.match(/\|/)) { return 'mdf-pipe'; }
					return null;
				}
				// ── Außerhalb: Fence-Offene-Zeile? ──
				if (stream.sol()) {
					var m = stream.match(openRe, false);
					if (m) {
						state.fence = m[1].charAt(0);
						stream.match(/^\s*(`{3,}|~{3,})/, true);
						var rest = m[2];
						var isFence = /^\s*(`{3,}|~{3,})\s*$/.test(rest);
						if (rest && !/^\s*$/.test(rest) && !isFence) {
							/* Sprachtag nach der Fence (z. B. "lean") — eigene Token-Span. */
							stream.eatSpace();
							if (stream.match(/^[A-Za-z0-9_+.#-]+/)) { return 'mdf-fencelang'; }
						}
						stream.skipToEnd();
						return null;
					}
				}
				if (stream.match(/\|/)) { return 'mdf-pipe'; }
				stream.eatWhile(/[^|]/);
				return null;
			}
		};
	});

	CodeMirror.defineMode('text/x-mdf', function (config) {
		var inner = CodeMirror.getMode(config, { name: 'gfm', highlightFormatting: true });
		var base = {
			startState: function () {
				return { math: 0, inner: CodeMirror.startState(inner) };
			},
			/* CodeMirror-Konvention: token(stream, state) — Reihenfolge bindend. */
			token: function (stream, state) {
				// ── In Math-Span: bis zum schließenden $ / $$ ──
				if (state.math) {
					if (state.math === 2) {
						if (stream.match(/^\$\$/)) { state.math = 0; return 'mdf-math'; }
						if (stream.match(/^\$(?!\$)/)) { state.math = 0; return 'mdf-math'; }
					} else if (state.math === 1) {
						if (stream.match(/^\$(?!\$)/)) { state.math = 0; return 'mdf-math'; }
					}
					if (stream.match(/^\\[A-Za-z]+\*?/)) { return 'mdf-cmd'; }
					if (stream.match(/^\\./)) { return 'mdf-cmd'; }
					if (stream.match(/^[^\s\\$]+/)) { return 'mdf-math'; }
					if (stream.eol()) { return null; }
					stream.next();
					return null;
				}
				// ── Außerhalb: Math-Eöffner annehmen ──
				if (stream.match(/^\$\$/)) { state.math = 2; return 'mdf-math'; }
				if (stream.match(/^\$(?!\$)(?=\S)/)) { state.math = 1; return 'mdf-math'; }
				return inner.token(stream, state.inner);
			}
		};
		return CodeMirror.overlayMode(base, CodeMirror.getMode(config, 'text/x-mdmark'));
	});

	/* ─────────────────────────── Editor starten ─────────────────────────── */

	function el(name, cls) {
		var n = document.createElement(name);
		if (cls) { n.className = cls; }
		return n;
	}

	function wrapSel(cm, pre, post, ph) {
		/*
		 * Auswahl mit Prä- und Suffix umklammern; Cursor-Range bleibt auf dem
		 * Text. v1.3.12 (User-Regel): ohne markierten Text passiert GAR NICHTS.
		 */
		if (!cm.getSelection()) { cm.focus(); return; }
		cm.replaceSelection(pre + (cm.getSelection() || ph) + post, 'around');
		cm.focus();
	}

	/* ─────────────── Original-Dialoge des Standard-WP-Editors ───────────────
	 * Link und Bild rufen die ORIGINAL-Dialoge des klassischen WP-Editors auf —
	 * NICHT nachgebaut, direkt aus den Core-Sourcen (User-Vorgabe 2026-11-24):
	 *   Link : wpLink (wp-includes/js/wplink.js) — der „Insert/edit link"-
	 *          Dialog exakt wie der Quicktags-Linkbutton des Standard-Editors:
	 *          wpLink.open('content')  (editorId = unsere Textarea).
	 *          Dialog-Markup (#wp-link), L10n, Nonce und das AJAX für die
	 *          „Or link to existing content"-Liste (wp-link-ajax) liefern die
	 *          WP-Admin-Edit-Seite — wir laden/speichern nichts davon selbst.
	 *   Bild : der qt.ImgButton-Callback (wp-includes/js/quicktags.js) —
	 *          die zwei Original-Prompts (src, alt).
	 *
	 * Beide Original-Pfade schreiben (Klassik-Standard) HTML in die Textarea
	 * #content und feuern danach 'change'. MD-First hält den Text in der
	 * CodeMirror-Instanz, also:
	 *   VOR dem Dialog:  CM → Textarea spiegeln (Wert + Auswahl), damit die
	 *                    Original-Auswahllogik (Link-Text-Vorbelegung,
	 *                    Einfügeposition) unverändert original arbeitet.
	 *   NACH Bestätigung: change-Handler diffet die Textarea, übersetzt das
	 *                     eingefügte HTML nach Markdown und fügt es an der
	 *                     Position in CM ein; Textarea wird aus CM re-
	 *                     gespiegelt (speichern speist die Textarea — bleibt
	 *                     konsistent in beiden Richtungen).
	 *
	 * HTML → Markdown-Mapping (Parsedown-Verhalten verifiziert 2026-11-24):
	 *   <a href="U">T</a>                  → [T](U)    (T leer → [U](U))
	 *   <a href="U" target="_blank">T</a>  → bleibt HTML (MD kennt kein „neuer Tab")
	 *   URL mit Leerzeichen/<>/T mit Zeile → bleibt HTML (MD-Inline kann das nicht)
	 *   <img src="S" alt="A" />            → ![A](S)
	 *   src mit Leerzeichen               → bleibt HTML
	 * Abbruch (Escape / Cancel) ändert NICHTS — wie im Standard.
	 */

	var nativeInsert = { active: false, before: '', cm: null, ta: null };

	function absPos(cm, line, ch) {
		var off = 0, i;
		for (i = 0; i < line; i++) { off += cm.getLine(i).length + 1; }
		return off + ch;
	}

	function posAt(cm, off) {
		var n = (cm.doc && cm.doc.lineCount) ? cm.doc.lineCount() : cm.lineCount();
		var left = 0, i;
		for (i = 0; i < n; i++) {
			var L = cm.getLine(i).length + 1;
			if (off < left + L) { return { line: i, ch: Math.min(off - left, cm.getLine(i).length) }; }
			left += L;
		}
		return { line: n - 1, ch: cm.getLine(n - 1).length };
	}

	function syncTa(cm, ta) {
		ta.value = cm.getValue();
		try {
			ta.setSelectionRange(
				absPos(cm, cm.getCursor('from').line, cm.getCursor('from').ch),
				absPos(cm, cm.getCursor('to').line, cm.getCursor('to').ch));
		} catch (err) { /* ohne Auswahl (ältere Engines) → Original-Default */ }
	}

	function mdTextEscape(t) {
		return t.replace(/\\/g, '\\\\').replace(/[[\]]/g, function (c) { return '\\' + c; });
	}

	function linkHtmlToMd(ins) {
		var m = ins.match(/^<a href="([^"]*)"(\s+target="_blank")?>([\s\S]*)<\/a>$/);
		if (!m) { return null; }
		var href = m[1], text = m[3];
		if (m[2] || /[\s<>]/.test(href) || text.indexOf('\n') !== -1) { return ins; }
		text = (text.trim() === '') ? href : text;
		return '[' + mdTextEscape(text) + '](' + href + ')';
	}

	function imgHtmlToMd(ins) {
		var m = ins.match(/^<img src="([^"]*)" alt="([^"]*)" \/>$/);
		if (!m) { return null; }
		var src = m[1], alt = m[2];
		if (/[\s<>]/.test(src)) { return ins; }
		return '![' + alt.replace(/\\/g, '\\\\') + '](' + src + ')';
	}

	function makeLink(cm, ta) {
		if (typeof window.wpLink !== 'object' || typeof window.wpLink.open !== 'function') {
			if (window.console && console.warn) { console.warn('MDF: wpLink (WP-Core) not loaded — link dialog skipped.'); }
			cm.focus();
			return;
		}
		syncTa(cm, ta);
		nativeInsert.active = true;
		nativeInsert.before = ta.value;
		nativeInsert.cm = cm;
		nativeInsert.ta = ta;
		window.wpLink.open('content');
		// wplink feuert die Bestätigung als jQuery-Trigger auf 'change' (kein
		// natives Event → native Listener erwischen es nicht); zuverlässig ist
		// stattdessen das eigens definierte 'wplink-close' (jQuery-Event auf
		// document, wird in wplink.close() gefeuert). Schreiben passiert vor
		// close() → hier ist ta.value final. setTimeout 0: wplink-Fokus/Aufräumen
		// zuerst laufen lassen.
		if (window.jQuery && window.jQuery.fn && window.jQuery.fn.jquery) {
			window.jQuery(document).one('wplink-close', function () {
				window.setTimeout(processNativeInsert, 0);
			});
		}
	}

	function makeImg(cm, ta) {
		if (typeof window.QTags !== 'function' || typeof window.QTags.getInstance !== 'function') {
			if (window.console && console.warn) { console.warn('MDF: Quicktags (WP-Core) not loaded — image dialog skipped.'); }
			cm.focus();
			return;
		}
		var ed = window.QTags.getInstance('content');
		var btn = (ed && typeof ed.getButton === 'function') ? ed.getButton('img') : null;
		if (!btn || typeof btn.callback !== 'function') { cm.focus(); return; }
		syncTa(cm, ta);
		nativeInsert.active = true;
		nativeInsert.before = ta.value;
		nativeInsert.cm = cm;
		nativeInsert.ta = ta;
		btn.callback.call(btn, ed.getButtonElement('img'), ta, ed);
		// qt.TagButton arbeitet synchron (zwei window.prompt) und feuert danach
		// ein echtes 'change' auf die Textarea (→ Change-Listener, init). Zur
		// Absicherung auch direkt verarbeiten — das active-Gate dedupliziert.
		processNativeInsert();
	}

	/* Läuft auf 'change' der Textarea — feuern die Original-Dialoge nach
	 * Bestätigung (wplink.htmlUpdate / qt.TagButton). Diff (gemeinsames
	 * Prä-/Suffix) isoliert das eingefügte HTML, Mapping oben, Einfügen in
	 * CM an der Position. Einmalig pro Dialog-Bestätigung. */
	function processNativeInsert() {
		if (!nativeInsert.active) { return; } // bereits verbraucht (Dual-Hook)
		nativeInsert.active = false;
		var cm = nativeInsert.cm, ta = nativeInsert.ta;
		if (!cm || !ta) { return; }
		var before = nativeInsert.before;
		var after = ta.value;
		if (after === before) { return; } // Abbruch → GAR NICHTS
		var p = 0, maxp = Math.min(before.length, after.length);
		while (p < maxp && before.charCodeAt(p) === after.charCodeAt(p)) { p++; }
		var s = 0, maxs = Math.min(before.length - p, after.length - p);
		while (s < maxs && before.charCodeAt(before.length - 1 - s) === after.charCodeAt(after.length - 1 - s)) { s++; }
		var ins = after.slice(p, after.length - s);
		var md = linkHtmlToMd(ins) || imgHtmlToMd(ins);
		if (!md) { ta.value = cm.getValue(); return; } // unerwarteter Write → CM bleibt Wahrheit
		// In BEFORE-Koordinaten spannt das eingefügte NODE genau den
		// Ankertext, wenn es eine bestehende Selektion umschließt
		// (<a>…sel…</a>: der inner Text IST die Selektion; <img>: '' —
		// reiner Cursor-Insert). Sonst: reines Einfügen an p (Ankertext
		// aus Textfeld, Original bleibt stehen).
		var inner = '';
		if (ins.indexOf('<a ') === 0) {
			var mIn = ins.match(/^<a [^>]*>([\s\S]*)<\/a>$/);
			if (mIn) { inner = mIn[1]; }
		}
		var endOff = (before.slice(p, p + inner.length) === inner) ? p + inner.length : p;
		cm.replaceRange(md, posAt(cm, p), posAt(cm, endOff));
		cm.setCursor(posAt(cm, endOff + md.length)); // Cursor hinter dem neuen MD
		cm.focus();
		ta.value = cm.getValue(); // Textarea-Sync für den nativen Speicherpfad
	}

	function heading(cm) {
		var line = cm.getLine(cm.getCursor().line);
		var m = line.match(/^( *)?(#{0,5}) ?/);
		var h = m && m[2] ? m[2] + '#' : '#';
	 cm.replaceRange(m && m[1] ? m[1] + h + ' ' : h + ' ', { line: cm.getCursor().line, ch: m && m[1] ? m[1].length : 0 }, { line: cm.getCursor().line, ch: line.length });
		cm.focus();
	}

	/* ───────────────── Quicktags-Toolbar → Markdown ─────────────────
	 * Die WP-Quicktags-Leiste (#ed_toolbar) über dem Editor würde per
	 * Standard HTML-Tags in das Textfeld schreiben. Im Markdown-First-
	 * Modus feuern alle Buttons stattdessen Markdown-Syntax in die
	 * CodeMirror-Instanz: Capture-Phase am DOCUMENT-Element +
	 * StopPropagation blockiert den QT-Handler am Button (Bubble-Phase).
	 * Auf document statt #ed_toolbar, damit es unabhängig von der
	 * Toolbar-Initialisierungsreihenfolge immer zuerst zündet
	 * (native Quicktags-Prompts wie Link/Bild würden sonst die Seite blockieren).
	 * Nur Button-IDs, die eine MD-Operation haben, werden abgefangen —
	 * der Drag-Handle (qt_content_dfw) bleibt unangetastet.
	 */
	function mdToolbar(cm, ta) {
		// MD-Marker um die Auswahl legen — TOGGLE: Sitzt die Auswahl
		// bereits genau einmal eingeschlossen (gleiche Marker links/rechts
		// direkt daneben), entfernt der 2. Klick sie (Sternchen-Häufung
		// vermeiden). Einem längeren identischen Marker angehörige Auswahl
		// (z. B. *abc* in **abc** beim kursiv-Button) wird NICHT angefasst.
		function wrap(pre, post, ph) {
			var sel = cm.getSelection();
			// v1.3.12 (User-Regel): ohne markierten Text passiert GAR NICHTS —
			// kein Platzhalter, kein **fett**-Spam bei Mehrfachklicks.
			if (!sel) { cm.focus(); return; }
			var from = cm.getCursor('from'), to = cm.getCursor('to');
		if (sel && from.line === to.line) {
			var v = cm.getValue();
			var before = v.slice(from.ch - pre.length, from.ch);
			var after  = v.slice(to.ch, to.ch + post.length);
			if (before === pre && after === post) {
				// Guard: Auswahl in einem LAENGEREN identischen Marker-Block
				// (em auf **abc**): NICHT anfassen, weder unwrapen noch wickeln.
				var c = pre.charAt(pre.length - 1);
				var outer = from.ch - pre.length - 1;
				if (pre.length === 1 && outer >= 0 && v.charAt(outer) === c) {
					cm.focus();
					return;
				}
				cm.replaceRange(sel, { line: from.line, ch: from.ch - pre.length }, { line: to.line, ch: to.ch + post.length });
				// Auswahl MUSS auf den freigelegten Text zeigen — CM5 laesst
				// sonst die SELECTION auf einem Teilfragment stehen (z. B.
				// [0,2] = „jk"), und der naechste Klick boldet genau dieses
				// Fragment statt den ganzen Text (User-Bug „3x bold" -> **jk**).
				cm.setSelection(
					{ line: from.line, ch: from.ch - pre.length },
					{ line: to.line, ch: to.ch + post.length });
				cm.focus();
				return;
			}
		}
		cm.replaceSelection(pre + (sel || ph) + post, 'end');
		// Auswahl MUSS auf den INNEREN Text zeigen, nicht auf das Gesamte
		// mit Markern — 'around' waehlt das Gesamte aus, und dann erkennt
		// der naechste Klick den Zustand nicht und haufet die Marker an
		// (Sternchen-Haeufung, User-Bug 6x bold).
		cm.setSelection(
			{ line: from.line, ch: from.ch + pre.length },
			{ line: to.line, ch: to.ch + pre.length });
		cm.focus();
		}

		// Zeilenpräfix als TOGGLE (v1.3.7): Jede Zeile des Auswahlbereichs
		// flippt — beginnt mit „P " → wird entfernt, sonst wird „P "
		// vorangestellt (leere Zeilen bleiben). Wiederholter Klick auf
		// dieselbe Auswahl toggelt also hin und her statt zu häufen.
		// Selection nach dem Vorgang: kompletter Zeilenbereich, damit der
		// Folgeklick denselben Bereich sieht (Analogie: wrap()).
		function lines(prefix) {
			var P = prefix + ' ';
			var from = cm.getCursor('from'), to = cm.getCursor('to');
			var a = from.line, b = to.line;
			var out = [], changed = false, i, l;
			for (i = a; i <= b; i++) {
				l = cm.getLine(i);
				if (!l.length) { out.push(l); }
				else if (l.slice(0, P.length) === P) { out.push(l.slice(P.length)); changed = true; }
				else { out.push(P + l); changed = true; }
			}
			if (!changed) { // Auswahl nur über leere Zeilen → Platzhalter
				cm.replaceSelection(P, 'end');
				cm.focus();
				return;
			}
			cm.replaceRange(out.join('\n'), { line: a, ch: 0 }, { line: b, ch: cm.getLine(b).length });
			cm.setSelection({ line: a, ch: 0 }, { line: b, ch: out[b - a].length });
			cm.focus();
		}

		function linesNumbered() {
			var sel = cm.getSelection();
			var n = 0;
			var out = (sel || '').split('\n')
				.map(function (l) { return l.length ? (++n) + '. ' + l : l; })
				.join('\n');
			cm.replaceSelection(out || '1. ', 'around');
			cm.focus();
		}

		function codeAction() {
			var sel = cm.getSelection();
			if (!sel) { cm.replaceSelection('`code`', null, 'end'); cm.focus(); }
			else if (sel.indexOf('\n') !== -1) { cm.replaceSelection('```\n' + sel + '\n```', 'around'); cm.focus(); }
			else { wrap('`', '`', 'code'); }
		}

		// Footnote (v1.3.17): Auswahl wird zum Fußnotentext. [^N] an der
		// Selektionsstelle, Definition [^N]: … ans Post-Ende (klassische
		// Fußnoten-Konvention, Pandoc-kompatibel). N = max bestehender Referenzen + 1.
		// v1.3.12-Regel: keine Auswahl → passiert nichts (Klick-Tor greift).
		function footnoteAction() {
			var sel = cm.getSelection();
			if (!sel) { return; }
			var doc = cm.getValue();
			var n = 0, m, re = /\[\^(\d+)\]/g;
			while ((m = re.exec(doc)) !== null) {
				var x = parseInt(m[1], 10);
				if (x > n) { n = x; }
			}
			n += 1;
			var def = '[^' + n + ']: ' + sel;
			cm.replaceSelection('[^' + n + ']', 'end');
			var e = cm.lineCount() - 1;
			var last = cm.getLine(e);
			var added = last.length ? '\n\n' : '';
			cm.replaceRange(added + def + '\n', { line: e, ch: last.length });
			var defLine = e + (added ? 2 : 0);
			cm.setSelection({ line: defLine, ch: 0 }, { line: defLine, ch: def.length });
			cm.focus();
		}

		var ACTS = {
			mdf_undo:       function () { cm.undo(); },
			mdf_redo:       function () { cm.redo(); },
			qt_content_strong: function () { wrap('**', '**', 'bold'); },
			qt_content_em:     function () { wrap('*', '*', 'italic'); },
			qt_content_link: function () { makeLink(cm, ta); },
			qt_content_img:  function () { makeImg(cm, ta); },
			qt_content_del:    function () { wrap('~~', '~~', 'strikethrough'); },
			qt_content_ul:     function () { lines('-'); },
			qt_content_ol:     function () { linesNumbered(); },
			qt_content_block:  function () { lines('>'); },
			qt_content_code:   codeAction,
			mdf_footnote:     function () { footnoteAction(); }
			};

		// QuickTags baut #ed_toolbar NACH DOMContentLoaded (wp.editor-Init kommt
		// später) — jeder Rebuild verwirft unsere Buttons. → MutationObserver:
		// jedes Mal, wenn sich die Buttons ändern, Layout idempotent neu anwenden.
		//
		// ins-Button (Quicktags-Standard) hat nur eine HTML-Aktion (<ins>)
		// und Markdown hat keine Unterstreich-Syntax → Button komplett aus
		// dem DOM entfernen (User-Entscheidung v1.3.9), nicht nur aus den Maps.
		// Undo/Redo (CodeMirror-History, Standard-Keybindings Strg+Z /
		// Strg+Shift+Z) an ERSTER Position der Toolbar (User-Wunsch v1.3.10).
		var applying = false;
		function setupToolbar() {
			if (applying) { return true; }
			var tb = document.getElementById('ed_toolbar');
			if (!tb) { return false; }
			applying = true;
			try {
				var ins = document.getElementById('qt_content_ins');
				if (ins && ins.parentNode) { ins.parentNode.removeChild(ins); }
				// v1.3.15f (User-Entscheidung): 'more' (<!--more--> ist
				// WP-Split-Tag, in der MD-Pipeline funktionslos) und 'close'
				// (rohes HTML um die Auswahl, kein MD-Äquivalent) sind für
				// Markdown nicht sinnvoll → komplett aus dem DOM (ins-Muster).
				['qt_content_more', 'qt_content_close', 'qt_content_li'].forEach(function (id) {
					var el = document.getElementById(id);
					if (el && el.parentNode) { el.parentNode.removeChild(el); }
				});
				if (!tb.contains(document.getElementById('mdf_redo'))) {
					var redo = document.createElement('input');
					redo.type = 'button'; redo.className = 'ed_button button button-small';
					redo.id = 'mdf_redo'; redo.value = '\u21BB';
					var undo = document.createElement('input');
					undo.type = 'button'; undo.className = 'ed_button button button-small';
					undo.id = 'mdf_undo'; undo.value = '\u21BA';
					tb.insertBefore(redo, tb.firstChild);
					tb.insertBefore(undo, tb.firstChild);
				}
				// v1.3.17: Fußnote-Button (Symbol ¹) NACH dem Zitat-Button —
				// logische Gruppe „Blockelemente nach Inline-Formatierung".
				if (!tb.contains(document.getElementById('mdf_footnote'))) {
					var fn = document.createElement('input');
					fn.type = 'button'; fn.className = 'ed_button button button-small';
					fn.id = 'mdf_footnote'; fn.value = '\u00B9';
					var anchor = document.getElementById('qt_content_block');
					if (anchor && anchor.parentNode === tb) {
						tb.insertBefore(fn, anchor.nextSibling);
					} else { tb.appendChild(fn); }
				}
				setTitles();
			} finally { applying = false; }
			return true;
		}
		// Initial (Toolbar-Skelett existiert schon) + nach jedem Quicktags-Rebuild.
		setupToolbar();
		if (window.MutationObserver) {
			var tbEl = document.getElementById('ed_toolbar');
			if (tbEl) {
				var mo = new MutationObserver(function () {
					if (!applying) { setTimeout(setupToolbar, 0); }
				});
				mo.observe(tbEl, { childList: true });
			}
		}

		/* i18n (v1.3.16): Strings kommen per wp_localize aus PHP (Textdomain
		 * md-first-core, Default Englisch, Deutsch via languages/de_DE.mo).
		 * Fallback = identisch zum englischen Default. */
		var S = (window.MDF && window.MDF.strings) || {};
		var TITLES = {
			mdf_undo:         S.undo   || 'Undo (Ctrl+Z)',
			mdf_redo:         S.redo   || 'Redo (Ctrl+Shift+Z)',
			qt_content_strong:S.bold   || 'Bold (**text**)',
			qt_content_em:    S.italic || 'Italic (*text*)',
			qt_content_link:  S.link   || 'Link — "Insert/edit link" dialog (standard-WP) → [text](URL)',
			qt_content_img:   S.image  || 'Image — original prompt (src, alt) → ![alt](src)',
			qt_content_del:   S.strike || 'Strikethrough (~~text~~)',
			qt_content_ul:    S.ul     || 'Bulleted list (- item)',
			qt_content_ol:    S.ol     || 'Numbered list (1. item)',
			qt_content_block: S.quote  || 'Blockquote (> text)',
			qt_content_code:  S.code   || 'Code (`inline` / ```block```)',
			mdf_footnote:     S.footnote || 'Footnote — selected text becomes [^N]; definition added at the end of the post'
			};

		function setTitles() {
			if (!TITLES) { return false; }
			var done = Object.keys(TITLES).length;
			Object.keys(TITLES).forEach(function (id) {
				var b = document.getElementById(id);
				if (b) { b.title = TITLES[id]; done--; }
			});
			return done === 0;
		}
		document.addEventListener('click', function (e) {
			var el2 = e.target && e.target.closest ? e.target.closest('#ed_toolbar input.ed_button, #ed_toolbar a.ed_button, #ed_toolbar button.ed_button') : null;
			if (!el2 || !el2.id || !ACTS[el2.id]) { return; }
			// v1.3.12 (User-Regel): WENN NICHTS MARKIERT IST, GEHÖRT GAR NICHTS.
				// Kein Platzhalter, kein -/1./<!--more-->, keine QuickTags-HTML-Aktion.
				// v1.3.13 (User-Wunsch): BILD und LINK öffnen stattdessen wie der
				// Standard-Editor einen WP-Medien-Dialog. Ein Dialog braucht keine
				// Auswahl — ein blindes Eintragen schon. QuickTags-Native-Prompts
				// dürfen dabei nicht plopren (Klick würde die Seite blockieren).
				if (!cm.getSelection() && el2.id !== 'mdf_undo' && el2.id !== 'mdf_redo'
					&& el2.id !== 'qt_content_img' && el2.id !== 'qt_content_link') {
					e.preventDefault();
					e.stopPropagation();
					cm.focus();
					return;
				}
			e.preventDefault();
			e.stopPropagation();
			ACTS[el2.id]();
			cm.focus();
		}, true);
	}

	function startPreview(cm, pane, buttons, host) {
		var src = cm.getValue();
		if (!src.trim()) {
			pane.innerHTML = '<p class="mdf-preview-note">' + MDF.strings.empty + '</p>';
			return;
		}
		pane.innerHTML = '<p class="mdf-preview-note">' + MDF.strings.loading + '</p>';
		var body = new URLSearchParams();
		body.set('nonce', MDF.nonce);
		body.set('markdown', src);
		fetch(MDF.previewUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (j && j.success && j.data && typeof j.data.html === 'string') {
					pane.innerHTML = j.data.html;
				} else {
					pane.innerHTML = '<p class="mdf-preview-note">' + (j && j.data && j.data.message ? j.data.message : MDF.strings.error) + '</p>';
				}
			})
			.catch(function () {
				pane.innerHTML = '<p class="mdf-preview-note">' + MDF.strings.error + '</p>';
			});
	}

	function init(ta) {
		if (ta.getAttribute('data-mdf-init') === '1' || ta.id !== 'content') { return; }
		ta.setAttribute('data-mdf-init', '1');
		var host = ta.closest('#post-body-content') || ta.parentNode;

		// Tab-Leiste vor dem Inhalt.
		var bar = el('div', 'mdf-bar');
		var btnEdit = el('button', 'mdf-tab');
		btnEdit.type = 'button';
		btnEdit.textContent = MDF.strings.edit;
		var btnPrev = el('button', 'mdf-tab');
		btnPrev.type = 'button';
		btnPrev.textContent = MDF.strings.preview;
		bar.appendChild(btnEdit);
		bar.appendChild(btnPrev);
		ta.insertAdjacentElement('beforebegin', bar);

		var cm = CodeMirror.fromTextArea(ta, {
			mode: 'text/x-mdf',
			lineWrapping: true,
			lineNumbers: false,
			viewportMargin: Infinity,
						extraKeys: {
						'Ctrl-B': function () { wrapSel(cm, '**', '**', 'bold'); },
						'Cmd-B':  function () { wrapSel(cm, '**', '**', 'bold'); },
						'Ctrl-I': function () { wrapSel(cm, '*', '*', 'italic'); },
						'Cmd-I':  function () { wrapSel(cm, '*', '*', 'italic'); },
						'Ctrl-K': function () { makeLink(cm, ta); },
									'Cmd-K':  function () { makeLink(cm, ta); },
						// v1.3.12 (User-Regel "gar nichts geschehen"): Heading-Shortcuts
						// würden ohnehin Text einfügen/ersetzen — ohne Auswahl sind sie
						// no-op (konsistent mit ul/ol/li/block, die ebenfalls blockiert sind).
						'Alt-1': function () { if (cm.getSelection()) { heading(cm); } else { cm.focus(); } },
						'Alt-2': function () { if (cm.getSelection()) { cm.replaceSelection('## ', null, 'end'); } cm.focus(); },
						'Alt-3': function () { if (cm.getSelection()) { cm.replaceSelection('### ', null, 'end'); } cm.focus(); }
						}
		});

		// Vorschau-Panne (Luxus-Tab) nach dem Editor.
		// 'mdf-prose': Vorschau-Pane erbt exakt die Frontend-Content-Styles
		// (mdf-content.css wird auf der Edit-Seite nach mdf-editor.css enqueued).
		var pane = el('div', 'mdf-preview-pane mdf-prose');
		cm.getWrapperElement().insertAdjacentElement('afterend', pane);

		var view = 'edit';
		function setView(v) {
			view = v;
			host.setAttribute('data-mdf-view', v);
			btnEdit.setAttribute('aria-pressed', String(v === 'edit'));
			btnPrev.setAttribute('aria-pressed', String(v === 'preview'));
			if (v === 'edit') {
				// CM nach Sichtbarkeitswechsel neu messen.
				requestAnimationFrame(function () { cm.refresh(); });
			} else {
				cm.refresh();
				startPreview(cm, pane);
			}
		}
		btnEdit.addEventListener('click', function () { setView('edit'); });
		btnPrev.addEventListener('click', function () { setView('preview'); });
		btnEdit.setAttribute('aria-pressed', 'true');

		// Tab-Taste setzt Leerzeichen (Mono-Editor-Erwartung), nicht Fokus-Wechsel.
		cm.addKeyMap({ Tab: function () { cm.replaceSelection('    ', 'end', 'simple'); } });

		/* Original-Dialoge (Link wpLink / Bild qt.ImgButton) bestätigen in die
		 * Textarea + feuern 'change' — hier wird das eingefügte HTML in Markdown
		 * übersetzt und in die CM-Instanz übernommen. */
		ta.addEventListener('change', processNativeInsert);

		// WP-Quicktags-Toolbar über dem Editor → Markdown-Aktionen.
		mdToolbar(cm, ta);
	}

	function ready() {
		var ta = document.getElementById('content');
	if (!ta) { return; }
	init(ta);
}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready);
	} else {
		ready();
	}
})();
