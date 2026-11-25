/**
 * Die Umschaltleiste zwischen den Peppermint-Systemen (AI Brain #5280).
 *
 * Bewusst ein Web-Component in reinem JavaScript und ohne Bauschritt: Die
 * Leiste haengt in vier Anwendungen mit vier Bau-Ketten. Als React-Komponente
 * muesste sie in jeder davon mitgebaut und mitversioniert werden; so ist sie
 * EINE Datei, die ueberall dieselbe ist.
 *
 * (Urspruenglich kam die Bauform daher, dass der Manager auf Vue lief und die
 * uebrigen auf React. Das ist seit dem 12.09.2026 nicht mehr so — die Flotte
 * ist einheitlich React. Die Bauform bleibt aus dem Grund darueber; ein Umbau
 * auf ein React-Bauteil waere moeglich, ist aber bewusst nicht gemacht.)
 *
 * Aus demselben Grund liegt das Aussehen im Shadow DOM: Die Leiste haengt in
 * vier Anwendungen mit vier CSS-Bestaenden. Was hier drin steht, kann von
 * aussen nicht versehentlich umgeschrieben werden — und umgekehrt.
 *
 * EINBINDEN:
 *
 *     <script src="/switcher/app-switcher.js?v=13" defer></script>
 *     <peppermint-app-switcher endpoint="/switcher/apps" aktuell="ai-brain"
 *                              postfach="/notifications/inbox"
 *                              postfach-zaehler="/api/inbox/unread-count">
 *     </peppermint-app-switcher>
 *
 * `postfach` und `postfach-zaehler` sind freiwillig: Ohne sie erscheint keine
 * Postfach-Kachel. Ein Produkt ohne das Benachrichtigungspaket hat keine
 * Posteingangs-Seite, und ein Symbol auf einen 404 ist schlimmer als keines.
 *
 * EINTRAEGE DES PRODUKTS (`lokal`, z. B. die Module des Managers): kommen in
 * der Nutzlast unter `lokal` mit und nehmen den Platz des eigenen Systems ein.
 * Welcher davon aktiv ist, sagt `aktuell` — oder, weil eine Inertia-Anwendung
 * das Layout beim Navigieren nicht neu schreibt, das Attribut
 * `lokal-aktiv="<schluessel>"`, das die Anwendung zur Laufzeit setzt.
 *
 * ABSCHALTEN fuer einzelne Ansichten (Avatar-Vollbild, Praesentationen):
 *
 *     document.documentElement.dataset.switcher = 'aus';
 */
(() => {
    'use strict';

    const VERSION = '1.12.0';

    /**
     * Die Symbole, die ein Merkzettel tragen kann (#5320).
     *
     * ## Warum hier und nicht im Server
     *
     * Ueber die Leitung geht nur ein SCHLUESSEL. Wie er aussieht, entscheidet
     * die Leiste — sonst stuende Markup aus der Datenbank in einer Anzeige, die
     * auf JEDER Seite ALLER vier Systeme eingeblendet wird.
     *
     * Brain fuehrt dieselben Schluessel als Erlaubnisliste. Laufen beide
     * auseinander, ist der Ausgang gutmuetig: Ein unbekannter Schluessel faellt
     * hier durch und der Merkzettel zeigt wieder seine Anfangsbuchstaben.
     *
     * Alle Pfade zeichnen in `currentColor` und ohne Fuellung — damit sie in
     * hell und dunkel gleich gut sitzen.
     *
     * Seit 1.12.0 (pm #10219) sind es die Formen von Lucide (ISC-Lizenz,
     * lucide.dev) — dieselben, die die Produkte in ihrer Seitenleiste zeigen.
     * Eingebettet statt geladen: Die Leiste hat keinen Bauschritt. Jeder Wert
     * ist der Inhalt eines 24er-`<svg>` und steht ausschliesslich HIER, nie in
     * Daten.
     */
    const SYMBOLE = {
        mail: '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
        kalender: '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/>',
        datei: '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        ordner: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        person: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        gruppe: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>',
        haus: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        stern: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
        herz: '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/>',
        fahne: '<path d="M4 22V4a1 1 0 0 1 .4-.8A6 6 0 0 1 8 2c3 0 5 2 7.333 2q2 0 3.067-.8A1 1 0 0 1 20 4v10a1 1 0 0 1-.4.8A6 6 0 0 1 16 16c-3 0-5-2-8-2a6 6 0 0 0-4 1.528"/>',
        lupe: '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
        uhr: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        chat: '<path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/>',
        telefon: '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>',
        ort: '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        euro: '<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
        diagramm: '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        haken: '<path d="M21 10.656V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.344"/><path d="m9 11 3 3L22 4"/>',
        regler: '<path d="M10 5H3"/><path d="M12 19H3"/><path d="M14 3v4"/><path d="M16 17v4"/><path d="M21 12h-9"/><path d="M21 19h-5"/><path d="M21 5h-7"/><path d="M8 10v4"/><path d="M8 12H3"/>',
        buch: '<path d="M12 5v16"/><path d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z"/>',
        paket: '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
        gluehbirne: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
    };

    /**
     * Wie ein Symbol heisst, wenn ein Mensch danach fragt.
     *
     * Der Schluessel ist ein Bezeichner, kein Wort: „gluehbirne" als Kurzinfo
     * und als Vorlesetext waere schlampig. Fehlt eine Bezeichnung, faellt es
     * auf den Schluessel zurueck — dann steht dort etwas Unschoenes statt
     * nichts.
     */
    const BEZEICHNUNG = {
        mail: 'E-Mail',
        kalender: 'Kalender',
        datei: 'Datei',
        ordner: 'Ordner',
        person: 'Person',
        gruppe: 'Gruppe',
        haus: 'Startseite',
        stern: 'Favorit',
        herz: 'Gemerkt',
        fahne: 'Markiert',
        lupe: 'Suche',
        uhr: 'Zeit',
        chat: 'Nachrichten',
        telefon: 'Telefon',
        ort: 'Ort',
        euro: 'Geld',
        diagramm: 'Auswertung',
        haken: 'Erledigt',
        regler: 'Einstellungen',
        buch: 'Wissen',
        paket: 'Lieferung',
        gluehbirne: 'Idee',
    };

    /**
     * Das Postfach-Symbol — ein Briefkasten mit aufgeklappter Lasche.
     *
     * Bewusst nicht die Glocke: Die stand bisher IN jeder Anwendung und
     * meinte dort nur deren eigene Meldungen. Hier geht es um den Posteingang
     * ueber alle Systeme hinweg, und ein neuer Ort verdient ein eigenes Bild.
     */
    const POSTFACH_SYMBOL = '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>';

    /** Lucide `plus` — fuer das Anpinnen. */
    const PLUS_SYMBOL = '<path d="M5 12h14"/><path d="M12 5v14"/>';

    /** Wie oft der Zaehler nachgefragt wird. */
    const POSTFACH_TAKT = 60 * 1000;

    const SPEICHER = 'peppermint-switcher-apps';
    const HALTBAR = 5 * 60 * 1000;
    const NACHLAUF = 260;

    /**
     * Die Leiste ist Beiwerk. Sie darf nie der Grund sein, dass eine Seite
     * nicht laedt — deshalb faengt jeder Weg hier seine eigenen Fehler ab und
     * endet im Zweifel damit, dass gar nichts erscheint.
     */
    class AppSwitcher extends HTMLElement {
        static get observedAttributes() {
            return ['lokal-aktiv'];
        }

        constructor() {
            super();
            this.apps = [];
            this.pins = [];
            this.lokal = [];
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

            if (this.hatPostfach()) {
                void this.postfachZaehlen();
                this.postfachUhr = setInterval(() => void this.postfachZaehlen(), POSTFACH_TAKT);
            }
        }

        /**
         * Die Anwendung meldet ein anderes aktives Modul (Inertia-Navigation).
         * Neu zeichnen, aber nur, wenn schon etwas steht — beim ersten Setzen
         * vor `connectedCallback` gibt es noch keine Leiste.
         */
        attributeChangedCallback(name, alt, neu) {
            if (name === 'lokal-aktiv' && alt !== neu && this.leiste) {
                this.zeichnen({ apps: this.apps, pins: this.pins, lokal: this.lokal });
            }
        }

        disconnectedCallback() {
            document.removeEventListener('keydown', this.aufTaste);
            document.removeEventListener('pointerdown', this.aufKlickAussen);
            this.themaWaechter?.disconnect();
            clearTimeout(this.schliessUhr);
            clearInterval(this.postfachUhr);
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
            let sofort = this.ausAttribut() ?? this.ausSpeicher();

            // Hat der Server nur die Eintraege des Produkts mitgegeben (die
            // Liste von AI Brain stand noch nicht bereit), kommen die Systeme
            // aus dem eigenen Speicher — sonst fehlten sie bis zur Antwort.
            if (sofort && sofort.ohneSysteme) {
                const gespeichert = this.ausSpeicher();

                sofort = {
                    apps: gespeichert?.apps ?? [],
                    // Liegen die Favoriten im Produkt (pm #10219), schickt der
                    // Server sie immer mit — dann gelten SEINE, nicht ein
                    // alter Stand aus dem Speicher.
                    pins: sofort.mitPins ? sofort.pins : (gespeichert?.pins ?? []),
                    lokal: sofort.lokal,
                };
            }

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
                    lokal: Array.isArray(daten.lokal) ? daten.lokal : [],
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
                return { apps: roh, pins: [], lokal: [] };
            }

            if (roh && typeof roh === 'object') {
                return {
                    apps: Array.isArray(roh.apps) ? roh.apps : [],
                    pins: Array.isArray(roh.pins) ? roh.pins : [],
                    lokal: Array.isArray(roh.lokal) ? roh.lokal : [],
                    // Nur die Eintraege des Produkts, ohne die Liste von AI
                    // Brain — siehe `laden()`.
                    ohneSysteme: !('apps' in roh) && Array.isArray(roh.lokal),
                    mitPins: Array.isArray(roh.pins),
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
            this.lokal = Array.isArray(nutzlast.lokal) ? nutzlast.lokal : [];

            const kacheln = this.kacheln();

            // Ein einziger Eintrag und kein Merkzettel heisst: es gibt nichts
            // zu wechseln. Dann ist auch der Griff zu viel — er verspraeche
            // eine Wahl.
            //
            // Das Postfach zaehlt dazu: Es ist seit #7995 der einzige Ort, an
            // dem der Posteingang noch haengt — die Glocke in der Anwendung
            // ist dafuer weggefallen. Eine Leiste, die sich wegen eines
            // einzelnen Systems versteckt, versteckte ihn mit.
            const zeigen = kacheln.length > 1 || this.pins.length > 0 || this.hatPostfach();

            this.wrap.hidden = !zeigen;

            if (!zeigen) {
                return;
            }

            const systeme = kacheln.map((app) => {
                if (app.lokal === true) {
                    return this.lokalKnopf(app);
                }

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
                            ${this.pinInhalt(pin)}
                        </span>
                        <span class="hinweis" role="tooltip">${this.sicher(pin.label)}</span>
                    </a>
                    <button class="umbenennen" type="button" data-pin="${this.sicher(pin.id)}"
                            aria-label="${this.sicher(pin.label)} bearbeiten">&#9998;</button>
                    <button class="loesen" type="button" data-pin="${this.sicher(pin.id)}"
                            aria-label="${this.sicher(pin.label)} entfernen">&times;</button>
                </span>`);

            // Der Strich trennt zwei Dinge, die verschieden funktionieren:
            // links die Systeme (immer da, vom Verzeichnis bestimmt), rechts
            // die eigenen Merkzettel (selbst abgelegt, jederzeit loeschbar).
            const trenner = (merkzettel.length > 0 || this.kannAnpinnen()) && systeme.length > 0
                ? '<span class="trenner" aria-hidden="true"></span>'
                : '';

            this.leiste.innerHTML = this.postfachKnopf() + systeme.join('') + trenner
                + merkzettel.join('') + this.anpinnKnopf();

            this.bilderAbsichern();
            this.knoepfeVerdrahten();
        }

        /* ------------------------------------- die Eintraege des Produkts */

        /**
         * Systeme und Eintraege des Produkts in einer Reihe.
         *
         * Die Eintraege des Produkts (seine Module) stehen dort, wo sonst das
         * eigene System steht — der Eintrag, den AI Brain mit `aktuell`
         * markiert. Sonst stuende „Peppermint Manager" neben dem Modul
         * „Manager". Kennt AI Brain das eigene System nicht (oder antwortet
         * nicht), stehen sie vorn.
         *
         * `this.apps` selbst bleibt unveraendert: Der Name des eigenen Systems
         * wird noch gebraucht, um Seitentitel fuer Merkzettel zu kuerzen.
         */
        kacheln() {
            if (this.lokal.length === 0) {
                return this.apps;
            }

            const aktiv = this.getAttribute('lokal-aktiv');
            const eigene = this.lokal.map((eintrag) => ({
                ...eintrag,
                lokal: true,
                aktuell: aktiv ? eintrag.schluessel === aktiv : eintrag.aktuell === true,
            }));

            const stelle = this.apps.findIndex((app) => app.aktuell === true);

            if (stelle === -1) {
                return [...eigene, ...this.apps];
            }

            return [...this.apps.slice(0, stelle), ...eigene, ...this.apps.slice(stelle + 1)];
        }

        /**
         * Ein Eintrag des Produkts — dieselbe Kachel wie ein System, aber mit
         * Symbol oder Kuerzel statt Logo (ein Modul hat keine Bildmarke).
         */
        lokalKnopf(eintrag) {
            const aktuell = eintrag.aktuell === true;
            const ziel = aktuell ? '' : ` href="${this.sicher(eintrag.url)}"`;
            const inhalt = this.symbolBild(eintrag.symbol)
                || `<span class="kuerzel-modul">${this.sicher(eintrag.kuerzel || this.pinKuerzel(eintrag.name))}</span>`;

            return `
                <a class="knopf modul${aktuell ? ' ist-hier' : ''}"${ziel}
                   ${aktuell ? 'aria-current="page" tabindex="-1"' : ''}
                   data-modul="${this.sicher(eintrag.schluessel)}"
                   aria-label="${this.sicher(eintrag.name)}">
                    <span class="kachel modul-kachel" style="--farbe: ${this.sicher(eintrag.farbe)}">
                        ${inhalt}
                    </span>
                    <span class="hinweis" role="tooltip">${this.sicher(eintrag.name)}</span>
                </a>`;
        }

        /* ------------------------------------------------- das Postfach */

        /**
         * Haengt an dieser Leiste ein Postfach?
         *
         * Nur, wenn das Produkt eine Adresse mitgegeben hat. Ein Produkt ohne
         * das Benachrichtigungspaket hat keine Posteingangs-Seite, und ein
         * Symbol, das auf einen 404 fuehrt, ist schlimmer als keines.
         */
        hatPostfach() {
            return typeof this.getAttribute('postfach') === 'string'
                && this.getAttribute('postfach') !== '';
        }

        /**
         * Die Postfach-Kachel — ganz links, vor den Systemen.
         *
         * Links, weil sie kein System ist, sondern das, was aus allen zusammen
         * kommt. Rechts bei den Merkzetteln stuende sie zwischen Dingen, die
         * man sich selbst abgelegt hat und jederzeit loeschen kann.
         */
        postfachKnopf() {
            if (!this.hatPostfach()) {
                return '';
            }

            const ungelesen = this.postfachZahl || 0;

            // Dreistellig passt nicht auf ein Abzeichen dieser Groesse, und
            // die genaue Zahl ist ab da ohnehin keine Hilfe mehr.
            const text = ungelesen > 99 ? '99+' : String(ungelesen);

            const abzeichen = ungelesen > 0
                ? `<span class="postfach-zahl">${this.sicher(text)}</span>`
                : '';

            // „in allen Systemen" gehoert dazu (AI Brain #1152): Die Zahl
            // ist der Bestand ueber alle Arten und Systeme, die Reiter auf
            // der Seite dahinter zeigen die Liste. Am 29.10.2026 stand 4623
            // hier gegen Reiter mit 9/29/50/112/50, und das las sich als
            // Widerspruch. Derselbe Satz steht am Symbol in der Kopfzeile.
            const beschriftung = ungelesen > 0
                ? `Posteingang, ${ungelesen} ungelesen in allen Systemen`
                : 'Posteingang';

            return `
                <a class="knopf postfach" href="${this.sicher(this.getAttribute('postfach'))}"
                   aria-label="${this.sicher(beschriftung)}">
                    <span class="kachel postfach-kachel">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                             stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${POSTFACH_SYMBOL}</svg>
                        ${abzeichen}
                    </span>
                    <span class="hinweis" role="tooltip">${this.sicher(beschriftung)}</span>
                </a>`;
        }

        /**
         * Den Zaehler holen und im Takt nachfuehren.
         *
         * Scheitert der Abruf, bleibt die letzte bekannte Zahl stehen und es
         * wird nichts gezeichnet. Eine Null wegen eines Netzaussetzers waere
         * die falsche Aussage — sie hiesse „alles gelesen".
         */
        async postfachZaehlen() {
            const adresse = this.getAttribute('postfach-zaehler');

            if (!adresse) {
                return;
            }

            try {
                const antwort = await fetch(adresse, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!antwort.ok) {
                    return;
                }

                const daten = await antwort.json();
                const zahl = Number(daten.unread_count);

                if (!Number.isFinite(zahl) || zahl === this.postfachZahl) {
                    return;
                }

                this.postfachZahl = zahl;

                // Nur die Kachel neu, nicht die ganze Leiste: Ein Neuzeichnen
                // mitten im Umbenennen eines Merkzettels naehme dem Menschen
                // das Eingabefeld unter den Fingern weg.
                const alteKachel = this.leiste?.querySelector('.postfach');

                if (alteKachel) {
                    alteKachel.outerHTML = this.postfachKnopf();
                }
            } catch (fehler) {
                // Die Leiste ist Beiwerk. Ein Zaehler, der nicht kommt, darf
                // keine Fehlermeldung in die Konsole jeder Seite schreiben.
            }
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
        /**
         * Was in der Kachel steht: das gewaehlte Symbol — sonst die Buchstaben.
         *
         * Die Reihenfolge ist der ganze Punkt. Ein unbekannter Schluessel (eine
         * aeltere Leiste, ein neu hinzugekommenes Symbol) faellt hier durch und
         * landet bei den Anfangsbuchstaben. Der Merkzettel bleibt damit unter
         * allen Umstaenden benutzbar; das Symbol ist Schmuck, keine Bedingung.
         */
        pinInhalt(pin) {
            return this.symbolBild(pin.symbol)
                || `<span class="kuerzel-pin">${this.sicher(this.pinKuerzel(pin.label))}</span>`;
        }

        /**
         * Ein Symbol als SVG — oder nichts, wenn der Schluessel unbekannt ist.
         *
         * Der Pfad stammt aus SYMBOLE, nie aus den Daten. Deshalb ist das hier
         * die einzige Stelle der Leiste, die Markup zusammensetzt, ohne den
         * Inhalt vorher durch `sicher()` zu schicken — er kommt nicht von
         * aussen.
         */
        symbolBild(schluessel) {
            const form = SYMBOLE[schluessel];

            if (!form) {
                return '';
            }

            return `<svg class="symbol" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">${form}</svg>`;
        }

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
            // Ohne Liste aus AI Brain (Konto nicht verbunden, pm #10219)
            // nennt das Produkt seinen Namen selbst.
            const name = hier?.name ?? this.getAttribute('anwendung') ?? '';
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
                    <svg class="plus" viewBox="0 0 24 24" width="16" height="16" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">${PLUS_SYMBOL}</svg>
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

            this.leiste.querySelectorAll('.umbenennen').forEach((knopf) => {
                knopf.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.umbenennen(knopf.dataset.pin);
                });
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

            const feld = this.feldOeffnen(vorschlag, (name, symbol) => this.anpinnen(name, symbol));

            feld.focus();
            feld.select();
        }

        /**
         * Das Namensfeld — eine Stelle fuer Anlegen und Umbenennen.
         *
         * Zwei Felder mit derselben Aufgabe waeren zwei Stellen, an denen das
         * Abbrechen unterschiedlich funktioniert.
         */
        feldOeffnen(vorbelegung, beimBestaetigen, symbolVorbelegung = null) {
            this.gewaehltesSymbol = symbolVorbelegung;

            const huelle = document.createElement('span');
            huelle.className = 'eingabe-huelle';

            const feld = document.createElement('input');
            feld.className = 'eingabe';
            feld.type = 'text';
            feld.value = vorbelegung;
            feld.maxLength = 80;
            feld.setAttribute('aria-label', 'Name des Merkzettels');

            huelle.appendChild(feld);
            huelle.appendChild(this.symbolwahlBauen());
            this.leiste.appendChild(huelle);

            // Solange getippt wird, darf die Leiste nicht zufahren — sonst ist
            // die Eingabe weg, sobald die Maus danebengeraet.
            this.bearbeitet = true;

            // Ein Klick auf ein Symbol nimmt dem Feld den Fokus, und ein
            // verlorener Fokus gilt hier als Abbruch. Deshalb wird der Fokus
            // beim Druecken erst gar nicht abgegeben: `mousedown` abfangen ist
            // die einzige Stelle, an der das noch geht — `click` kommt zu spaet,
            // der Abbruch waere schon gelaufen.
            huelle.addEventListener('mousedown', (e) => {
                if (e.target.closest('.symbolknopf')) {
                    e.preventDefault();
                }
            });

            huelle.addEventListener('click', (e) => {
                const knopf = e.target.closest('.symbolknopf');

                if (!knopf) {
                    return;
                }

                const wahl = knopf.dataset.symbol || null;

                // Noch einmal auf dasselbe Symbol heisst abwaehlen. Sonst gaebe
                // es keinen Weg zurueck zu den Buchstaben, ausser den ganzen
                // Merkzettel neu anzulegen.
                this.gewaehltesSymbol = this.gewaehltesSymbol === wahl ? null : wahl;

                huelle.querySelectorAll('.symbolknopf').forEach((k) => {
                    k.classList.toggle('gewaehlt', k.dataset.symbol === this.gewaehltesSymbol);
                    k.setAttribute('aria-pressed', String(k.dataset.symbol === this.gewaehltesSymbol));
                });

                feld.focus();
            });

            feld.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.bearbeitet = false;
                    beimBestaetigen(feld.value.trim() || vorbelegung, this.gewaehltesSymbol);
                }

                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.abbrechen();
                }
            });

            feld.addEventListener('blur', () => {
                // Ein Klick daneben ist ein Abbruch, kein Verlust: Was getippt
                // wurde, war noch nicht abgelegt. Der Aufschub um einen Zug
                // laesst dem Symbol-Klick oben den Vortritt, der den Fokus
                // sofort zurueckholt.
                setTimeout(() => {
                    if (this.bearbeitet && !huelle.contains(document.activeElement)) {
                        this.abbrechen();
                    }
                }, 0);
            });

            return feld;
        }

        /**
         * Die Symbolauswahl unter dem Namensfeld.
         *
         * Als Raster und nicht als Ausklappliste: Symbole erkennt man im
         * Nebeneinander, eine Liste zwaenge sie in eine Reihenfolge, die
         * niemand kennt. Wer keines will, waehlt einfach keines — es gibt
         * bewusst KEINEN „ohne"-Knopf, denn nichts zu waehlen ist der
         * Ausgangszustand und braucht keine eigene Schaltflaeche.
         */
        symbolwahlBauen() {
            const wahl = document.createElement('span');
            wahl.className = 'symbolwahl';
            wahl.setAttribute('role', 'group');
            wahl.setAttribute('aria-label', 'Symbol des Merkzettels');

            wahl.innerHTML = Object.keys(SYMBOLE).map((schluessel) => {
                const drin = schluessel === this.gewaehltesSymbol;

                return `<button class="symbolknopf${drin ? ' gewaehlt' : ''}" type="button"
                                data-symbol="${this.sicher(schluessel)}"
                                aria-pressed="${drin}"
                                aria-label="${this.sicher(BEZEICHNUNG[schluessel] || schluessel)}"
                                title="${this.sicher(BEZEICHNUNG[schluessel] || schluessel)}">${this.symbolBild(schluessel)}</button>`;
            }).join('');

            return wahl;
        }

        /**
         * Einen vorhandenen Merkzettel umbenennen.
         *
         * Dieselbe Eingabe wie beim Anlegen, nur mit dem VORHANDENEN Namen
         * vorbelegt — nicht mit dem Titel der Seite, auf der man gerade steht.
         * Der hat mit dem Merkzettel nichts zu tun.
         */
        umbenennen(id) {
            const pin = this.pins.find((p) => String(p.id) === String(id));

            if (!pin) {
                return;
            }

            const feld = this.feldOeffnen(pin.label, async (name, symbol) => {
                const geaendert = await this.schicken('PATCH', { id: pin.id, label: name, symbol });

                if (geaendert === null) {
                    this.abbrechen();

                    return;
                }

                this.pins = this.pins.map((p) => (String(p.id) === String(id) ? geaendert : p));
                this.inSpeicher({ apps: this.apps, pins: this.pins, lokal: this.lokal });
                this.zeichnen({ apps: this.apps, pins: this.pins, lokal: this.lokal });
            }, pin.symbol ?? null);

            feld.focus();
            feld.select();
        }

        abbrechen() {
            this.bearbeitet = false;
            this.zeichnen({ apps: this.apps, pins: this.pins, lokal: this.lokal });
        }

        /**
         * Die aktuelle Seite ablegen.
         *
         * Titel und Adresse kommen aus dem Dokument — niemand soll etwas
         * abtippen muessen. Der Server prueft trotzdem beides; er darf sich auf
         * nichts verlassen, was von hier kommt.
         */
        async anpinnen(label, symbol = null) {

            const neuerPin = await this.schicken('POST', {
                url: location.href,
                label,
                symbol,
                product_slug: this.getAttribute('aktuell') || '',
            });

            if (neuerPin === null) {
                return;
            }

            this.pins = [...this.pins, neuerPin];
            this.inSpeicher({ apps: this.apps, pins: this.pins, lokal: this.lokal });
            this.zeichnen({ apps: this.apps, pins: this.pins, lokal: this.lokal });
        }

        async loesen(id) {
            if (await this.schicken('DELETE', { id }) === null) {
                return;
            }

            this.pins = this.pins.filter((pin) => String(pin.id) !== String(id));
            this.inSpeicher({ apps: this.apps, pins: this.pins, lokal: this.lokal });
            this.zeichnen({ apps: this.apps, pins: this.pins, lokal: this.lokal });
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
    /*
     * Die Stil-Schalter. Ein Produkt kann jeden davon von aussen setzen
     * (peppermint-app-switcher { --akzent: … }). Die Werte folgen seit
     * 1.12.0 (pm #10219) der neutralen Palette von shadcn/ui (zinc): Flaeche,
     * feiner Rand, --akzent fuer Zeigen und Aktiv, --ring fuer den Fokus.
     */
    :host {
        --grund: #ffffff;
        --rand: #e4e4e7;
        --griff: rgba(9, 9, 11, 0.22);
        --schrift: #09090b;
        --gedaempft: #71717a;
        --akzent: #f4f4f5;
        --ring: rgba(161, 161, 170, 0.55);
        --schatten: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -2px rgba(0, 0, 0, 0.06);

        position: fixed;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        z-index: 2147483000;
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
    }

    :host(.dunkel) {
        --grund: #09090b;
        --rand: rgba(255, 255, 255, 0.1);
        --griff: rgba(255, 255, 255, 0.3);
        --schrift: #fafafa;
        --gedaempft: #a1a1aa;
        --akzent: #27272a;
        --ring: rgba(113, 113, 122, 0.6);
        --schatten: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
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
        color: var(--gedaempft);
        cursor: pointer;
        transition: width 160ms ease, height 160ms ease, background 160ms ease;
    }

    /* Am Zeigegeraet traegt der Streifen kein Zeichen — er liegt an der
       Bildkante und wird mit dem Mauszeiger ohne Zielen getroffen. Am
       Telefon wird aus ihm ein Knopf, und ein Knopf ohne Zeichen ist ein
       grauer Fleck. Das Zeichen kommt deshalb erst in der Medienabfrage
       weiter unten zum Vorschein. */
    .griff svg { display: none; }

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
        gap: 4px;
        margin-top: 8px;
        padding: 6px;
        border: 1px solid var(--rand);
        border-radius: 12px;
        background: var(--grund);
        box-shadow: var(--schatten);
        opacity: 0;
        transform: translateY(-10px);
        transition: opacity 180ms ease, transform 180ms ease;
    }

    .leiste[hidden] { display: none; }

    .wrap.offen .leiste { opacity: 1; transform: translateY(0); }

    /*
     * Ein Knopf wie in shadcn/ui (Button variant="ghost" size="icon"):
     * keine eigene Flaeche, beim Zeigen und als „hier" die Akzentflaeche, der
     * Fokus als Ring. Bis 1.12.0 trug jede Kachel unten einen farbigen
     * Streifen; Bastian (25.11.2026): „Dieser untere Schatten in Farbe gefaellt
     * mir irgendwie auch nicht." Die Farbe des Systems bleibt als Punkt.
     */
    .knopf {
        position: relative;
        display: block;
        text-decoration: none;
        border-radius: 8px;
        outline: none;
    }

    .kachel {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        color: var(--gedaempft);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.02em;
        transition: background-color 150ms ease, color 150ms ease, box-shadow 150ms ease;
    }

    .knopf:focus-visible .kachel,
    .knopf:focus-visible .pin-kachel { box-shadow: 0 0 0 3px var(--ring); }

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
        width: calc(18px * var(--skalierung, 1));
        height: calc(18px * var(--skalierung, 1));
        object-fit: contain;
        filter: brightness(0);
        opacity: 0.6;
        transition: opacity 140ms ease;
    }

    :host(.dunkel) .kachel img { filter: brightness(0) invert(1); opacity: 0.72; }

    .knopf:hover .kachel,
    .knopf:focus-visible .kachel {
        background: var(--akzent);
        color: var(--schrift);
    }

    .knopf:hover .kachel img,
    .knopf:focus-visible .kachel img { opacity: 1; }

    /* Wo man gerade ist, wird gezeigt, nicht angeboten. */
    .knopf.ist-hier { cursor: default; }
    .knopf.ist-hier .kachel {
        background: var(--akzent);
        color: var(--schrift);
        box-shadow: inset 0 0 0 1px var(--rand);
    }
    .knopf.ist-hier .kachel img { opacity: 1; }

    /*
     * Das Postfach sieht aus wie ein System — gleiche Kachel, gleiche Groesse
     * —, hat aber kein Logo, sondern ein gezeichnetes Symbol. Es steht links
     * und gehoert damit sichtbar zur Leiste und nicht zu den Merkzetteln.
     */
    .postfach-kachel svg { display: block; }

    /* Ein Modul des Produkts und ein Merkzettel: Symbol statt Logo, die Farbe
       des Moduls bzw. Systems als Punkt unten rechts. Der Rand in der
       Grundfarbe setzt ihn auch auf der Akzentflaeche ab. */
    .modul-kachel::after,
    .pin-kachel::after {
        content: '';
        position: absolute;
        right: 5px;
        bottom: 5px;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--farbe, var(--gedaempft));
        box-shadow: 0 0 0 1.5px var(--grund);
    }
    .modul-kachel:not([style*='--farbe: #'])::after { display: none; }
    .kuerzel-modul { line-height: 1; }

    /*
     * Das Abzeichen sitzt AUF der Kachel und nicht daneben: Die Leiste ist
     * schmal, und eine Zahl daneben verschoebe alles Folgende, sobald sie
     * zweistellig wird.
     */
    .postfach-zahl {
        position: absolute;
        top: -3px;
        right: -3px;
        min-width: 17px;
        height: 17px;
        padding: 0 4px;
        box-sizing: border-box;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #dc2626;
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
        line-height: 1;
        /* Ein Rand in der Grundfarbe setzt das Abzeichen von der Kachel ab,
           auch wenn beide dunkel sind. */
        box-shadow: 0 0 0 2px var(--grund);
    }

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
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: transparent;
        color: var(--gedaempft);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.02em;
        transition: background-color 150ms ease, color 150ms ease, box-shadow 150ms ease;
    }

    .knopf.pin:hover .pin-kachel,
    .knopf.pin:focus-visible .pin-kachel { background: var(--akzent); color: var(--schrift); }

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

    .umbenennen {
        position: absolute;
        top: -5px;
        left: -5px;
        width: 16px;
        height: 16px;
        padding: 0;
        border: 1px solid var(--rand);
        border-radius: 50%;
        background: var(--grund);
        color: var(--gedaempft);
        font-size: 9px;
        line-height: 1;
        cursor: pointer;
        opacity: 0;
        transition: opacity 120ms ease;
    }

    .pin-huelle:hover .loesen,
    .pin-huelle:hover .umbenennen,
    .loesen:focus-visible,
    .umbenennen:focus-visible { opacity: 1; }

    .loesen:hover,
    .umbenennen:hover { color: var(--schrift); }

    /* Anpinnen: gestrichelt, weil dort noch nichts ist. */
    .anpinnen {
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        width: 36px;
        height: 36px;
        padding: 0;
        border: 1px dashed var(--rand);
        border-radius: 8px;
        background: none;
        color: var(--gedaempft);
        cursor: pointer;
        outline: none;
        transition: color 150ms ease, background-color 150ms ease, box-shadow 150ms ease;
    }

    .anpinnen:hover,
    .anpinnen:focus-visible { color: var(--schrift); background: var(--akzent); }
    .anpinnen:focus-visible { box-shadow: 0 0 0 3px var(--ring); }

    /* Schon angepinnt: im Ruhezustand nicht zu unterscheiden — erst beim
       Zeigen dreht sich das Plus zum Kreuz und der Hinweis sagt, was ein Klick
       tut. */
    .plus { transition: transform 140ms ease; }

    .anpinnen.ist-drin:hover .plus,
    .anpinnen.ist-drin:focus-visible .plus { transform: rotate(45deg); }

    .plus { display: block; }

    /* Das Namensfeld steht an der Stelle des Plus — die Leiste waechst kurz,
       statt ein Fenster aufzumachen. */
    .eingabe {
        width: 150px;
        height: 36px;
        padding: 0 10px;
        border: 1px solid var(--rand);
        border-radius: 8px;
        background: var(--grund);
        color: var(--schrift);
        font: inherit;
        font-size: 12px;
        outline: none;
    }

    .eingabe:focus { box-shadow: 0 0 0 3px var(--ring); }

    .eingabe::placeholder { color: var(--gedaempft); }

    /* Symbol in der Kachel — gleiche Groesse wie die Buchstaben vorher, damit
       gemischte Merkzettel (mit und ohne Symbol) auf einer Linie stehen. */
    .symbol {
        width: 18px;
        height: 18px;
        display: block;
    }

    .kuerzel-pin { line-height: 1; }

    /* Das Feld traegt die Auswahl, damit sie sich mit ihm bewegt. */
    .eingabe-huelle {
        position: relative;
        display: inline-flex;
    }

    /* Das Raster haengt UNTER der Leiste, nicht in ihr: Die Leiste ist ein
       schmaler Streifen, 22 Symbole nebeneinander waeren breiter als der
       Bildschirm. Und es faehrt nicht ein — wer den Stift drueckt, will es
       sofort sehen. */
    .symbolwahl {
        position: absolute;
        top: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        display: grid;
        grid-template-columns: repeat(8, 1fr);
        gap: 2px;
        padding: 6px;
        border: 1px solid var(--rand);
        border-radius: 12px;
        background: var(--grund);
        box-shadow: var(--schatten);
    }

    .symbolknopf {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        padding: 0;
        border: 1px solid transparent;
        border-radius: 8px;
        background: none;
        color: var(--gedaempft);
        cursor: pointer;
    }

    .symbolknopf:hover,
    .symbolknopf:focus-visible {
        color: var(--schrift);
        background: var(--akzent);
        outline: none;
    }

    .symbolknopf:focus-visible { box-shadow: 0 0 0 3px var(--ring); }

    /* Das Gewaehlte muss sich auch ohne Farbe unterscheiden — deshalb Rahmen
       UND Flaeche, nicht nur ein Farbton. */
    .symbolknopf.gewaehlt {
        color: var(--schrift);
        border-color: var(--rand);
        background: var(--akzent);
    }


    /* Der Name erscheint erst beim Zeigen — vier Beschriftungen nebeneinander
       machen aus der Leiste eine Liste. */
    .hinweis {
        position: absolute;
        top: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%) translateY(-4px);
        padding: 4px 8px;
        border-radius: 6px;
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

    /*
     * Am Telefon zieht die Leiste nach UNTEN LINKS (AI Brain #1208).
     *
     * Gemessen am 31.10.2026 auf 390 x 844: Oben in der Mitte war der Griff
     * 56 x 6 px gross — unter jedem greifbaren Ziel — und klebte an der
     * Kante, an der am Geraet die Statusleiste und die Zieh-zum-Neuladen-Geste
     * des Browsers mitreden. Ausgeklappt mass die Leiste 403 px in einem
     * 390-px-Fenster; weil der Wirt mittig haengt, lief sie auf BEIDEN Seiten
     * heraus und die erste Kachel (Postfach) begann bei x = -5.
     *
     * Oben Platz zu schaffen geht nicht: Ab y = 14 beginnt in den Produkten
     * die Symbolreihe der Kopfzeile. Ein 44-px-Ziel dort verdeckte Suche und
     * Chat. Unten MITTIG geht auch nicht — dort liegt die Home-Anzeige von
     * iOS samt ihrer Wischgeste. Bleibt unten links; unten rechts ist durch
     * den runden Verfassen-Knopf des Mail-Pakets belegt.
     *
     * Die Grenze ist 767 px und nicht '(pointer: coarse)': Am geteilten
     * Pruefbrowser meldet die Emulation 'coarse = false' (Wiki „Handy-Nachweis:
     * Geraete-Emulation haengt am Tab"), eine Abfrage danach waere also genau
     * dort unpruefbar, wo geprueft wird. Die Breite ist dieselbe Grenze, die
     * das Layout der Produkte benutzt ('md').
     */
    @media (max-width: 767px) {
        :host {
            top: auto;
            left: 12px;
            bottom: calc(12px + env(safe-area-inset-bottom, 0px));
            transform: none;
        }

        /* 'column-reverse': Der Griff gehoert ans untere Ende, die Leiste
           waechst ueber ihm auf. Die Reihenfolge im Markup bleibt davon
           unberuehrt — sie ist die der Tastatur. */
        .wrap {
            flex-direction: column-reverse;
            align-items: flex-start;
            width: auto;
            padding-bottom: 0;
        }

        .griff {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--rand);
            border-radius: 12px;
            background: var(--grund);
            color: var(--schrift);
            box-shadow: var(--schatten);
        }

        .griff svg { display: block; }

        /* Die drei Regeln, die den Streifen beim Zeigen und im offenen
           Zustand wachsen lassen, duerfen hier nicht greifen: Ein Ziel, das
           seine Groesse aendert, waehrend der Finger daraufliegt, verfehlt
           man. */
        .griff:hover,
        .griff:focus-visible,
        .wrap.offen .griff {
            width: 44px;
            height: 44px;
            background: var(--grund);
        }

        .leiste {
            margin-top: 0;
            margin-bottom: 8px;
            flex-wrap: wrap;
            justify-content: flex-start;
            /* 12 px Abstand links, 12 px rechts — mehr als das Fenster gibt
               es nicht, und was darueber hinausgeht, waere abgeschnitten
               statt scrollbar.

               'box-sizing' gehoert dazu: Ohne es zaehlt die Grenze nur den
               Inhalt, Polsterung und Rand kommen obendrauf, und die Leiste
               stand bei der Messung am 31.10.2026 rechts buendig auf 390
               statt mit Abstand. */
            box-sizing: border-box;
            max-width: calc(100vw - 24px);
            /* Aufklappen von unten statt von oben. */
            transform: translateY(10px);
        }

        /* Der Hinweis unter einer Kachel liefe in der untersten Reihe aus dem
           Bild. Am Telefon traegt jede Kachel ohnehin ihre Beschriftung ueber
           'aria-label'. */
        .hinweis { display: none; }
    }

    @media (prefers-reduced-motion: reduce) {
        .griff, .leiste, .kachel, .pin-kachel, .hinweis, .loesen, .umbenennen, .anpinnen, .plus, .symbolknopf { transition: none; }
    }

    /* Ausdruecklich abgeschaltet (Avatar-Vollbild, Praesentation). */
    :host-context(html[data-switcher='aus']) { display: none; }
</style>

<div class="wrap" hidden>
    <button class="griff" type="button" aria-label="Anwendung wechseln (Alt+Umschalt+S)">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
            <path d="M5 3h4a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm10 0h4a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2ZM5 13h4a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2Zm10 0h4a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2Z"></path>
        </svg>
    </button>
    <nav class="leiste" aria-label="Anwendung wechseln" hidden></nav>
</div>`;
        }
    }

    if (!customElements.get('peppermint-app-switcher')) {
        AppSwitcher.VERSION = VERSION;
        customElements.define('peppermint-app-switcher', AppSwitcher);
    }
})();
