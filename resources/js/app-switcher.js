/**
 * Die Umschaltleiste zwischen den Peppermint-Systemen (AI Brain #5280).
 *
 * Bewusst ein Web-Component in reinem JavaScript und ohne Bauschritt: Der
 * Manager laeuft auf Vue, CRM, Verwaltung und Brain auf React. Als
 * React-Komponente muesste dieselbe Leiste fuer den Manager ein zweites Mal
 * gebaut werden — und zwei Fassungen driften auseinander, bis niemand mehr
 * sagen kann, welche die richtige ist.
 *
 * Aus demselben Grund liegt das Aussehen im Shadow DOM: Die Leiste haengt in
 * vier Anwendungen mit vier CSS-Bestaenden. Was hier drin steht, kann von
 * aussen nicht versehentlich umgeschrieben werden — und umgekehrt.
 *
 * EINBINDEN:
 *
 *     <script src="/switcher/app-switcher.js?v=13" defer></script>
 *     <peppermint-app-switcher endpoint="/switcher/apps" aktuell="ai-brain">
 *     </peppermint-app-switcher>
 *
 * ABSCHALTEN fuer einzelne Ansichten (Avatar-Vollbild, Praesentationen):
 *
 *     document.documentElement.dataset.switcher = 'aus';
 */
(() => {
    'use strict';

    const VERSION = '1.5.0';
    const SPEICHER = 'peppermint-switcher-apps';
    const HALTBAR = 5 * 60 * 1000;
    const NACHLAUF = 260;

    /**
     * Die Leiste ist Beiwerk. Sie darf nie der Grund sein, dass eine Seite
     * nicht laedt — deshalb faengt jeder Weg hier seine eigenen Fehler ab und
     * endet im Zweifel damit, dass gar nichts erscheint.
     */
    class AppSwitcher extends HTMLElement {
        constructor() {
            super();
            this.apps = [];
            this.pins = [];
            this.offen = false;
            this.bearbeitet = false;
            this.schliessUhr = null;
        }

        connectedCallback() {
            if (this.shadowRoot) {
                return;
            }

            this.attachShadow({ mode: 'open' });
            this.shadowRoot.innerHTML = this.geruest();

            this.wrap = this.shadowRoot.querySelector('.wrap');
            this.griff = this.shadowRoot.querySelector('.griff');
            this.leiste = this.shadowRoot.querySelector('.leiste');

            this.wrap.addEventListener('pointerenter', (e) => {
                // Beruehrung loest kein Ausfahren aus — dort gibt es kein
                // „daneben", und die Leiste bliebe nach dem ersten Tippen
                // stehen. Der Griff ist stattdessen antippbar.
                if (e.pointerType !== 'touch') {
                    this.aufmachen();
                }
            });
            this.wrap.addEventListener('pointerleave', () => this.zumachenGleich());
            this.griff.addEventListener('click', () => (this.offen ? this.zumachen() : this.aufmachen()));

            this.aufTaste = (e) => this.taste(e);
            this.aufKlickAussen = (e) => {
                if (this.offen && !e.composedPath().includes(this)) {
                    this.zumachen();
                }
            };

            document.addEventListener('keydown', this.aufTaste);
            document.addEventListener('pointerdown', this.aufKlickAussen);

            this.themaFolgen();
            this.laden();
        }

        disconnectedCallback() {
            document.removeEventListener('keydown', this.aufTaste);
            document.removeEventListener('pointerdown', this.aufKlickAussen);
            this.themaWaechter?.disconnect();
            clearTimeout(this.schliessUhr);
        }

        /* ---------------------------------------------------------------- */

        /**
         * Erst aus dem Zwischenspeicher, dann vom Server. Ohne den
         * Zwischenspeicher fragte jede Seitenansicht neu — fuer eine Liste,
         * die sich im Monat vielleicht einmal aendert.
         */
        async laden() {
            // Erst das, was sofort dasteht: die vom Server mitgelieferte Liste
            // oder der Zwischenspeicher. Damit ist die Leiste ohne Warten da
            // und poppt beim Ankommen nicht nach.
            const sofort = this.ausAttribut() ?? this.ausSpeicher();

            if (sofort) {
                this.zeichnen(sofort);
            }

            // Und DANACH trotzdem nachfragen, still im Hintergrund.
            //
            // Ohne das bliebe ein veralteter Stand stehen, bis der
            // Zwischenspeicher von selbst ablaeuft: Wer in einem System einen
            // Merkzettel anlegt, saehe ihn in den anderen minutenlang nicht —
            // und hielte die Funktion fuer kaputt. Genau so ist es gemeldet
            // worden.
            //
            // Es kostet eine Anfrage nach dem Laden, keine davor. Die Seite
            // wartet zu keinem Zeitpunkt darauf.

            try {
                const antwort = await fetch(this.getAttribute('endpoint') || '/switcher/apps', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!antwort.ok) {
                    return;
                }

                const daten = await antwort.json();
                const nutzlast = {
                    apps: Array.isArray(daten.apps) ? daten.apps : [],
                    pins: Array.isArray(daten.pins) ? daten.pins : [],
                };

                this.inSpeicher(nutzlast);
                this.zeichnen(nutzlast);
            } catch {
                // Kein Netz, keine Leiste. Die Seite selbst geht das nichts an.
            }
        }

        /** Die vom Server mitgelieferte Nutzlast — oder null, wenn keine dasteht. */
        ausAttribut() {
            const roh = this.getAttribute('apps');

            if (roh === null || roh === '') {
                return null;
            }

            try {
                return this.normalisieren(JSON.parse(roh));
            } catch {
                return null;
            }
        }

        /**
         * Eine blosse Liste ist die alte Form (nur Systeme). Sie muss weiter
         * gelesen werden koennen: Waehrend eines gestaffelten Rollouts liefert
         * ein noch nicht aktualisiertes Produkt genau die.
         */
        normalisieren(roh) {
            if (Array.isArray(roh)) {
                return { apps: roh, pins: [] };
            }

            if (roh && typeof roh === 'object') {
                return {
                    apps: Array.isArray(roh.apps) ? roh.apps : [],
                    pins: Array.isArray(roh.pins) ? roh.pins : [],
                };
            }

            return null;
        }

        ausSpeicher() {
            try {
                const roh = sessionStorage.getItem(SPEICHER);

                if (!roh) {
                    return null;
                }

                const { zeit, nutzlast } = JSON.parse(roh);

                return Date.now() - zeit < HALTBAR ? this.normalisieren(nutzlast) : null;
            } catch {
                return null;
            }
        }

        inSpeicher(nutzlast) {
            try {
                sessionStorage.setItem(SPEICHER, JSON.stringify({ zeit: Date.now(), nutzlast }));
            } catch {
                // Privates Fenster o.ae. — dann eben jedes Mal frisch fragen.
            }
        }

        /* ---------------------------------------------------------------- */

        zeichnen(nutzlast) {
            this.apps = nutzlast.apps;
            this.pins = nutzlast.pins;

            // Ein einziger Eintrag und kein Merkzettel heisst: es gibt nichts
            // zu wechseln. Dann ist auch der Griff zu viel — er verspraeche
            // eine Wahl.
            const zeigen = this.apps.length > 1 || this.pins.length > 0;

            this.wrap.hidden = !zeigen;

            if (!zeigen) {
                return;
            }

            const systeme = this.apps.map((app) => {
                const aktuell = app.aktuell === true;
                const ziel = aktuell ? '' : ` href="${this.sicher(app.url)}"`;

                // Das Kuerzel liegt UNTER dem Bild, nicht statt seiner:
                // Faellt das Icon aus, steht dort weiter etwas Lesbares,
                // statt einer leeren Kachel.
                return `
                    <a class="knopf${aktuell ? ' ist-hier' : ''}"${ziel}
                       ${aktuell ? 'aria-current="page" tabindex="-1"' : ''}
                       aria-label="${this.sicher(app.name)}">
                        <span class="kachel" style="--farbe: ${this.sicher(app.farbe)}">
                            <span class="kuerzel">${this.sicher(app.kuerzel)}</span>
                            <img src="${this.sicher(app.icon)}" alt=""
                                 data-ersatz="${this.sicher(app.icon_ersatz || '')}"
                                 decoding="async">
                        </span>
                        <span class="hinweis" role="tooltip">${this.sicher(app.name)}</span>
                    </a>`;
            });

            const merkzettel = this.pins.map((pin) => `
                <span class="pin-huelle">
                    <a class="knopf pin" href="${this.sicher(pin.url)}"
                       aria-label="${this.sicher(pin.label)}">
                        <span class="pin-kachel" style="--farbe: ${this.sicher(pin.farbe)}">
                            ${this.sicher(this.pinKuerzel(pin.label))}
                        </span>
                        <span class="hinweis" role="tooltip">${this.sicher(pin.label)}</span>
                    </a>
                    <button class="loesen" type="button" data-pin="${this.sicher(pin.id)}"
                            aria-label="${this.sicher(pin.label)} entfernen">&times;</button>
                </span>`);

            // Der Strich trennt zwei Dinge, die verschieden funktionieren:
            // links die Systeme (immer da, vom Verzeichnis bestimmt), rechts
            // die eigenen Merkzettel (selbst abgelegt, jederzeit loeschbar).
            const trenner = (merkzettel.length > 0 || this.kannAnpinnen()) && systeme.length > 0
                ? '<span class="trenner" aria-hidden="true"></span>'
                : '';

            this.leiste.innerHTML = systeme.join('') + trenner + merkzettel.join('') + this.anpinnKnopf();

            this.bilderAbsichern();
            this.knoepfeVerdrahten();
        }

        /**
         * Ein Bild, das nicht kommt, darf keine leere Kachel hinterlassen:
         * erst die zweite Adresse versuchen, dann das Bild ganz entfernen.
         *
         * KEIN loading="lazy" an den Bildern: Die Leiste ist eingeklappt,
         * solange niemand sie oeffnet — ein verzoegertes Bild in einem
         * verborgenen Element laedt der Browser gar nicht erst, und beim ersten
         * Ausfahren stuenden dort leere Kacheln.
         */
        bilderAbsichern() {
            this.leiste.querySelectorAll('img').forEach((bild) => {
                bild.addEventListener('error', () => {
                    const ersatz = bild.dataset.ersatz;

                    if (ersatz && bild.src !== ersatz) {
                        bild.dataset.ersatz = '';
                        bild.src = ersatz;

                        return;
                    }

                    bild.closest('.kachel')?.classList.add('ohne-bild');
                    bild.remove();
                });
            });
        }

        /**
         * Zwei Buchstaben aus dem Titel — mehr passt nicht auf eine Kachel.
         *
         * Bei mehreren Woertern die Anfangsbuchstaben der ersten beiden
         * („Offene Rechnungen" → „OR"), sonst die ersten beiden Zeichen.
         */
        pinKuerzel(label) {
            const text = String(label ?? '').trim();

            if (text === '') {
                return '?';
            }

            // Bindestriche und Schrägstriche sind keine Woerter. „Tasks - AI
            // Brain" ergaebe sonst „T-".
            const woerter = text.split(/\s+/).filter((w) => /[\p{L}\p{N}]/u.test(w));

            if (woerter.length === 0) {
                return text.slice(0, 2).toUpperCase();
            }

            return woerter.length >= 2
                ? (woerter[0][0] + woerter[1][0]).toUpperCase()
                : woerter[0].slice(0, 2).toUpperCase();
        }

        /**
         * Der Seitentitel ohne den Anwendungsnamen.
         *
         * Fast jede Seite haengt ihn an („Tasks - AI Brain"). Auf einem
         * Merkzettel IN dieser Anwendung ist er ueberfluessig — er steht in
         * jedem Eintrag und sagt nichts, was der Farbstreifen nicht schon
         * zeigt.
         */
        titelKuerzen(titel) {
            const hier = this.apps.find((app) => app.aktuell === true);
            const name = hier?.name ?? '';
            let text = String(titel ?? '').trim();

            if (name !== '') {
                for (const trenner of [' - ', ' – ', ' — ', ' | ']) {
                    if (text.endsWith(trenner + name)) {
                        text = text.slice(0, -(trenner + name).length).trim();
                        break;
                    }
                }
            }

            // Heisst die Seite wie das System selbst („Peppermint Manager"),
            // sagt der Merkzettel nichts — er sieht dann aus wie die Kachel
            // daneben. Dann lieber der Weg: /projekte/17 wird zu „Projekte".
            if (text === '' || text === name) {
                text = this.ausDemWeg();
            }

            return text !== '' ? text : (titel ?? '').trim();
        }

        /** Der erste sprechende Teil der Adresse, gross geschrieben. */
        ausDemWeg() {
            const teile = location.pathname.split('/').filter((t) => t !== '' && !/^\d+$/.test(t));

            if (teile.length === 0) {
                return 'Startseite';
            }

            const wort = decodeURIComponent(teile[0]).replace(/[-_]+/g, ' ');

            return wort.charAt(0).toUpperCase() + wort.slice(1);
        }

        /** Gibt es ueberhaupt eine Stelle, an der abgelegt werden kann? */
        kannAnpinnen() {
            return Boolean(this.getAttribute('pin-endpoint'));
        }

        /** Der Merkzettel zur Seite, auf der wir gerade stehen — oder null. */
        hierAngepinnt() {
            return this.pins.find((pin) => pin.url === location.href) ?? null;
        }

        /**
         * Ein Umschalter, kein Knopf, der verschwindet.
         *
         * Vorher wurde er ausgeblendet, sobald die Seite schon angepinnt war.
         * Das ist gemeldet worden als „ich kann nur einen hinzufuegen": Wer auf
         * einer Seite steht, die schon drin ist, sieht keinen Knopf — und kann
         * nicht wissen, ob die Funktion fehlt, kaputt ist oder ihre Arbeit
         * bereits getan hat.
         *
         * Ein Zustand, den man sieht, ist besser als einer, den man aus einer
         * Abwesenheit erschliessen muss. Zweiter Klick nimmt wieder heraus —
         * dieselbe Geste wie beim Lesezeichen-Stern im Browser.
         */
        anpinnKnopf() {
            if (!this.kannAnpinnen()) {
                return '';
            }

            const drin = this.hierAngepinnt();
            const text = drin ? 'Diese Seite ist angepinnt — Klick nimmt sie heraus' : 'Diese Seite anpinnen';

            // Im Ruhezustand IMMER dasselbe: graues gestricheltes Kaestchen mit
            // Plus. Das Problem war nie das Zeichen, sondern dass der Knopf
            // ganz verschwand — daran war nicht zu erkennen, ob die Funktion
            // fehlt, kaputt ist oder ihre Arbeit getan hat.
            //
            // Ist die Seite schon drin, dreht sich das Plus beim Zeigen zum
            // Kreuz (dieselbe Form, 45 Grad) und der Hinweis sagt es. Der
            // Zustand ist damit da, wo man ihn braucht — beim Hinsehen, nicht
            // die ganze Zeit.
            return `
                <button class="anpinnen${drin ? ' ist-drin' : ''}" type="button"
                        aria-pressed="${drin ? 'true' : 'false'}"
                        aria-label="${this.sicher(text)}">
                    <span class="plus" aria-hidden="true">+</span>
                    <span class="hinweis" role="tooltip">${this.sicher(text)}</span>
                </button>`;
        }

        knoepfeVerdrahten() {
            this.leiste.querySelector('.anpinnen')?.addEventListener('click', (e) => {
                e.preventDefault();

                const drin = this.hierAngepinnt();

                if (drin) {
                    this.loesen(drin.id);

                    return;
                }

                this.benennen();
            });

            this.leiste.querySelectorAll('.loesen').forEach((knopf) => {
                knopf.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.loesen(knopf.dataset.pin);
                });
            });
        }

        /**
         * Vor dem Ablegen nach dem Namen fragen.
         *
         * Der Seitentitel allein reicht nicht: Zwei Postfaecher im selben
         * System heissen beide „Emails" und unterscheiden sich nur in der
         * Adresse — nebeneinander in der Leiste sind sie dann nicht
         * auseinanderzuhalten. Welches welches ist, weiss nur der Mensch davor;
         * keine Regel kann das erraten.
         *
         * Vorbelegt mit dem Titel, damit der Normalfall ein Klick und ein
         * Enter bleibt.
         */
        benennen() {
            const vorschlag = this.titelKuerzen(document.title || location.pathname).slice(0, 80);

            this.leiste.querySelector('.anpinnen')?.remove();

            const feld = document.createElement('input');
            feld.className = 'eingabe';
            feld.type = 'text';
            feld.value = vorschlag;
            feld.maxLength = 80;
            feld.setAttribute('aria-label', 'Name des Merkzettels');
            this.leiste.appendChild(feld);

            // Solange getippt wird, darf die Leiste nicht zufahren — sonst ist
            // die Eingabe weg, sobald die Maus danebengeraet.
            this.bearbeitet = true;

            feld.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.bearbeitet = false;
                    this.anpinnen(feld.value.trim() || vorschlag);
                }

                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.abbrechen();
                }
            });

            feld.addEventListener('blur', () => {
                // Ein Klick daneben ist ein Abbruch, kein Verlust: Was
                // getippt wurde, war noch nicht abgelegt.
                if (this.bearbeitet) {
                    this.abbrechen();
                }
            });

            feld.focus();
            feld.select();
        }

        abbrechen() {
            this.bearbeitet = false;
            this.zeichnen({ apps: this.apps, pins: this.pins });
        }

        /**
         * Die aktuelle Seite ablegen.
         *
         * Titel und Adresse kommen aus dem Dokument — niemand soll etwas
         * abtippen muessen. Der Server prueft trotzdem beides; er darf sich auf
         * nichts verlassen, was von hier kommt.
         */
        async anpinnen(label) {

            const neuerPin = await this.schicken('POST', {
                url: location.href,
                label,
                product_slug: this.getAttribute('aktuell') || '',
            });

            if (neuerPin === null) {
                return;
            }

            this.pins = [...this.pins, neuerPin];
            this.inSpeicher({ apps: this.apps, pins: this.pins });
            this.zeichnen({ apps: this.apps, pins: this.pins });
        }

        async loesen(id) {
            if (await this.schicken('DELETE', { id }) === null) {
                return;
            }

            this.pins = this.pins.filter((pin) => String(pin.id) !== String(id));
            this.inSpeicher({ apps: this.apps, pins: this.pins });
            this.zeichnen({ apps: this.apps, pins: this.pins });
        }

        /**
         * Schreiben gegen den eigenen Server.
         *
         * Der Sitzungsschutz (CSRF) kommt aus dem Keks, den Laravel setzt —
         * derselbe Weg, den auch die Anwendung drumherum nimmt. Ohne ihn lehnt
         * der Server ab, und die Leiste taete stumm nichts.
         */
        async schicken(methode, daten) {
            const ziel = this.getAttribute('pin-endpoint');

            if (!ziel) {
                return null;
            }

            try {
                const antwort = await fetch(ziel, {
                    method: methode,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': this.sitzungsschutz(),
                    },
                    body: JSON.stringify(daten),
                });

                if (!antwort.ok) {
                    return null;
                }

                const inhalt = await antwort.json();

                return inhalt.pin ?? true;
            } catch {
                return null;
            }
        }

        sitzungsschutz() {
            const treffer = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

            return treffer ? decodeURIComponent(treffer[1]) : '';
        }

        /** Die Liste kommt vom eigenen Server — trotzdem nichts ungeprueft ins Markup. */
        sicher(wert) {
            return String(wert ?? '').replace(
                /[&<>"']/g,
                (z) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[z],
            );
        }

        /* ---------------------------------------------------------------- */

        aufmachen() {
            clearTimeout(this.schliessUhr);

            if (this.offen || this.wrap.hidden || document.documentElement.dataset.switcher === 'aus') {
                return;
            }

            this.offen = true;
            this.wrap.classList.add('offen');
            this.leiste.hidden = false;
            this.vorwaermen();
        }

        /**
         * Verbindung zu den Zielsystemen aufbauen, sobald die Leiste ausfaehrt.
         *
         * Zwischen Ausfahren und Klick liegen ein paar Hundert Millisekunden, in
         * denen der Browser nichts tut. Namensaufloesung und Verschluesselung
         * passen genau dorthin — beim Klick faellt dieser Teil dann weg.
         *
         * Nur einmal je Sitzung: Ein zweites preconnect auf dieselbe Adresse
         * bringt nichts und muellt den Kopfbereich zu.
         */
        vorwaermen() {
            if (this.vorgewaermt) {
                return;
            }

            this.vorgewaermt = true;

            for (const app of this.apps) {
                if (app.aktuell === true || typeof app.url !== 'string') {
                    continue;
                }

                try {
                    const link = document.createElement('link');
                    link.rel = 'preconnect';
                    link.href = new URL(app.url).origin;
                    link.crossOrigin = 'use-credentials';
                    document.head.appendChild(link);
                } catch {
                    // Unbrauchbare Adresse — dann eben ohne Vorwaermen.
                }
            }
        }

        zumachen() {
            clearTimeout(this.schliessUhr);

            if (!this.offen || this.bearbeitet) {
                return;
            }

            this.offen = false;
            this.wrap.classList.remove('offen');

            // Erst nach dem Einfahren aus dem Baum nehmen, sonst springt die
            // Leiste weg, statt zu verschwinden.
            this.schliessUhr = setTimeout(() => {
                if (!this.offen) {
                    this.leiste.hidden = true;
                }
            }, 200);
        }

        /**
         * Nachlauf beim Verlassen: Wer mit der Maus knapp an der Kante
         * vorbeifaehrt, soll die Leiste nicht sofort verlieren.
         */
        zumachenGleich() {
            clearTimeout(this.schliessUhr);
            this.schliessUhr = setTimeout(() => this.zumachen(), NACHLAUF);
        }

        taste(e) {
            if (e.key === 'Escape' && this.offen) {
                this.zumachen();
                this.griff.focus?.();

                return;
            }

            // Alt+Umschalt+S — der zweite Weg fuer alle, die nicht mit der
            // Maus an den Bildschirmrand fahren wollen.
            if (e.altKey && e.shiftKey && (e.key === 'S' || e.key === 's')) {
                e.preventDefault();

                if (this.offen) {
                    this.zumachen();

                    return;
                }

                this.aufmachen();
                this.shadowRoot.querySelector('.knopf[href]')?.focus();

                return;
            }

            if (!this.offen || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) {
                return;
            }

            const knoepfe = [...this.shadowRoot.querySelectorAll('.knopf[href]')];
            const jetzt = knoepfe.indexOf(this.shadowRoot.activeElement);

            if (jetzt === -1) {
                return;
            }

            e.preventDefault();
            const schritt = e.key === 'ArrowRight' ? 1 : -1;
            knoepfe[(jetzt + schritt + knoepfe.length) % knoepfe.length].focus();
        }

        /**
         * Dem Thema der Anwendung folgen.
         *
         * `prefers-color-scheme` allein reicht nicht: Alle vier Anwendungen
         * haben einen eigenen Umschalter, der eine Klasse an <html> haengt.
         * Wer dort auf Hell stellt, bekaeme sonst eine dunkle Leiste ueber
         * einer hellen Seite.
         */
        themaFolgen() {
            const setzen = () => {
                const dunkel =
                    document.documentElement.classList.contains('dark') ||
                    document.documentElement.dataset.theme === 'dark';

                this.shadowRoot.host.classList.toggle('dunkel', dunkel);
            };

            setzen();

            this.themaWaechter = new MutationObserver(setzen);
            this.themaWaechter.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class', 'data-theme'],
            });
        }

        /* ---------------------------------------------------------------- */

        geruest() {
            return `
<style>
    :host {
        --grund: #ffffff;
        --rand: rgba(15, 23, 42, 0.12);
        --griff: rgba(15, 23, 42, 0.22);
        --schrift: #0f172a;
        --gedaempft: rgba(15, 23, 42, 0.45);
        --schatten: 0 12px 28px -12px rgba(15, 23, 42, 0.35);

        position: fixed;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        z-index: 2147483000;
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
    }

    :host(.dunkel) {
        --grund: #17181c;
        --rand: rgba(255, 255, 255, 0.14);
        --griff: rgba(255, 255, 255, 0.3);
        --schrift: #f1f5f9;
        --gedaempft: rgba(241, 245, 249, 0.5);
        --schatten: 0 12px 28px -12px rgba(0, 0, 0, 0.8);
    }

    /* Geschlossen nur ein schmaler Streifen — er darf der Seite darunter
       nicht mehr wegnehmen als noetig. */
    .wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 220px;
        padding-bottom: 6px;
    }

    .wrap[hidden] { display: none; }

    .griff {
        width: 56px;
        height: 6px;
        border: 0;
        padding: 0;
        border-radius: 0 0 7px 7px;
        background: var(--griff);
        cursor: pointer;
        transition: width 160ms ease, height 160ms ease, background 160ms ease;
    }

    .griff:hover,
    .griff:focus-visible {
        width: 76px;
        height: 9px;
        background: var(--gedaempft);
    }

    .griff:focus-visible { outline: 2px solid var(--gedaempft); outline-offset: 2px; }

    .wrap.offen .griff { width: 76px; height: 9px; }

    .leiste {
        display: flex;
        gap: 6px;
        margin-top: 8px;
        padding: 8px;
        border: 1px solid var(--rand);
        border-radius: 18px;
        background: var(--grund);
        box-shadow: var(--schatten);
        opacity: 0;
        transform: translateY(-10px);
        transition: opacity 180ms ease, transform 180ms ease;
    }

    .leiste[hidden] { display: none; }

    .wrap.offen .leiste { opacity: 1; transform: translateY(0); }

    .knopf {
        position: relative;
        display: block;
        text-decoration: none;
        border-radius: 12px;
    }

    .knopf:focus-visible { outline: 2px solid var(--gedaempft); outline-offset: 3px; }

    .kachel {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 10px;
        color: var(--gedaempft);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.02em;
        transition: transform 140ms ease, background 140ms ease;
    }

    /* Das Kuerzel ist der Ersatz fuer ein fehlendes Logo, nicht sein Nachbar.
       Sichtbar wird es erst, wenn die Marke nicht kommt — dann setzt die
       Fehlerbehandlung die Klasse. */
    .kuerzel { display: none; }
    .kachel.ohne-bild .kuerzel { display: block; }

    /*
     * Eine Farbe fuer alle Marken.
     *
     * Die vier Bildmarken sind verschieden gebaut — Strich, Flaeche, mehrfarbig,
     * mit eigener Farbumschaltung. Nebeneinander in einer schmalen Leiste ergibt
     * das ein unruhiges Bild. brightness(0) macht aus jeder Marke eine
     * Silhouette, invert(1) dreht sie im Dunkelmodus ins Helle. Damit sehen
     * alle vier gleich aus, ohne dass irgendwo ein Logo nachgepflegt wird.
     *
     * Voraussetzung ist eine FREIGESTELLTE Marke: Bringt ein Icon seine eigene
     * Flaeche mit, wird daraus ein ausgefuellter Klotz. Genau deshalb zeigt die
     * Liste auf /logo.svg und nicht auf das App-Icon.
     */
    .kachel img {
        width: calc(22px * var(--skalierung, 1));
        height: calc(22px * var(--skalierung, 1));
        object-fit: contain;
        filter: brightness(0);
        opacity: 0.62;
        transition: opacity 140ms ease;
    }

    :host(.dunkel) .kachel img { filter: brightness(0) invert(1); opacity: 0.72; }

    .knopf:hover .kachel,
    .knopf:focus-visible .kachel {
        transform: scale(1.06);
        background: var(--rand);
    }

    .knopf:hover .kachel img,
    .knopf:focus-visible .kachel img { opacity: 1; }

    /* Wo man gerade ist, wird gezeigt, nicht angeboten. */
    .knopf.ist-hier { cursor: default; }
    .knopf.ist-hier .kachel { background: var(--rand); }
    .knopf.ist-hier .kachel img { opacity: 1; }
    .knopf.ist-hier:hover .kachel { transform: none; }

    /* Trennt Systeme von Merkzetteln. Links steht, was das Verzeichnis
       bestimmt; rechts, was man sich selbst abgelegt hat. */
    .trenner {
        width: 1px;
        align-self: stretch;
        margin: 4px 2px;
        background: var(--rand);
    }

    /*
     * Ein Merkzettel sieht bewusst ANDERS aus als ein System: kein Logo,
     * schmaler, Buchstaben statt Bild. Saehe er gleich aus, wuerde man ihn fuer
     * eine weitere Anwendung halten — und sich wundern, dass er verschwindet,
     * wenn man ihn entfernt.
     */
    .pin-huelle { position: relative; display: block; }

    .pin-kachel {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 38px;
        border-radius: 10px;
        background: var(--rand);
        color: var(--schrift);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.02em;
        /* Der farbige Streifen sagt, in welchem System die Seite liegt. */
        box-shadow: inset 0 -3px 0 0 var(--farbe, #64748b);
        transition: transform 140ms ease;
    }

    .knopf.pin:hover .pin-kachel,
    .knopf.pin:focus-visible .pin-kachel { transform: scale(1.06); }

    /* Das Entfernen taucht erst auf, wenn man den Merkzettel meint. */
    .loesen {
        position: absolute;
        top: -5px;
        right: -5px;
        width: 16px;
        height: 16px;
        padding: 0;
        border: 1px solid var(--rand);
        border-radius: 50%;
        background: var(--grund);
        color: var(--gedaempft);
        font-size: 12px;
        line-height: 1;
        cursor: pointer;
        opacity: 0;
        transition: opacity 120ms ease;
    }

    .pin-huelle:hover .loesen,
    .loesen:focus-visible { opacity: 1; }

    .loesen:hover { color: var(--schrift); }

    /* Anpinnen: gestrichelt, weil dort noch nichts ist. */
    .anpinnen {
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        width: 34px;
        height: 38px;
        padding: 0;
        border: 1px dashed var(--rand);
        border-radius: 10px;
        background: none;
        color: var(--gedaempft);
        cursor: pointer;
        transition: color 140ms ease, border-color 140ms ease;
    }

    .anpinnen:hover,
    .anpinnen:focus-visible { color: var(--schrift); border-color: var(--gedaempft); }

    /* Schon angepinnt: im Ruhezustand nicht zu unterscheiden — erst beim
       Zeigen dreht sich das Plus zum Kreuz und der Hinweis sagt, was ein Klick
       tut. */
    .plus { transition: transform 140ms ease; }

    .anpinnen.ist-drin:hover .plus,
    .anpinnen.ist-drin:focus-visible .plus { transform: rotate(45deg); }

    .plus { font-size: 16px; line-height: 1; }

    /* Das Namensfeld steht an der Stelle des Plus — die Leiste waechst kurz,
       statt ein Fenster aufzumachen. */
    .eingabe {
        width: 150px;
        height: 38px;
        padding: 0 10px;
        border: 1px solid var(--gedaempft);
        border-radius: 10px;
        background: var(--grund);
        color: var(--schrift);
        font: inherit;
        font-size: 12px;
        outline: none;
    }

    .eingabe::placeholder { color: var(--gedaempft); }


    /* Der Name erscheint erst beim Zeigen — vier Beschriftungen nebeneinander
       machen aus der Leiste eine Liste. */
    .hinweis {
        position: absolute;
        top: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%) translateY(-4px);
        padding: 4px 9px;
        border-radius: 8px;
        background: var(--schrift);
        color: var(--grund);
        font-size: 11px;
        line-height: 1.3;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 120ms ease, transform 120ms ease;
    }

    .knopf:hover .hinweis,
    .knopf:focus-visible .hinweis {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    @media (prefers-reduced-motion: reduce) {
        .griff, .leiste, .kachel, .pin-kachel, .hinweis, .loesen, .anpinnen, .plus { transition: none; }
    }

    /* Ausdruecklich abgeschaltet (Avatar-Vollbild, Praesentation). */
    :host-context(html[data-switcher='aus']) { display: none; }
</style>

<div class="wrap" hidden>
    <button class="griff" type="button" aria-label="Anwendung wechseln (Alt+Umschalt+S)"></button>
    <nav class="leiste" aria-label="Anwendung wechseln" hidden></nav>
</div>`;
        }
    }

    if (!customElements.get('peppermint-app-switcher')) {
        AppSwitcher.VERSION = VERSION;
        customElements.define('peppermint-app-switcher', AppSwitcher);
    }
})();
