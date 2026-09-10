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
 *     <script src="/switcher/app-switcher.js?v=8" defer></script>
 *     <peppermint-app-switcher endpoint="/switcher/apps" aktuell="ai-brain">
 *     </peppermint-app-switcher>
 *
 * ABSCHALTEN fuer einzelne Ansichten (Avatar-Vollbild, Praesentationen):
 *
 *     document.documentElement.dataset.switcher = 'aus';
 */
(() => {
    'use strict';

    const VERSION = '1.0.0';
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
            this.offen = false;
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
            const gemerkt = this.ausSpeicher();

            if (gemerkt) {
                this.zeichnen(gemerkt);
            }

            try {
                const antwort = await fetch(this.getAttribute('endpoint') || '/switcher/apps', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!antwort.ok) {
                    return;
                }

                const daten = await antwort.json();
                const apps = Array.isArray(daten.apps) ? daten.apps : [];

                this.inSpeicher(apps);
                this.zeichnen(apps);
            } catch {
                // Kein Netz, keine Leiste. Die Seite selbst geht das nichts an.
            }
        }

        ausSpeicher() {
            try {
                const roh = sessionStorage.getItem(SPEICHER);

                if (!roh) {
                    return null;
                }

                const { zeit, apps } = JSON.parse(roh);

                return Date.now() - zeit < HALTBAR && Array.isArray(apps) ? apps : null;
            } catch {
                return null;
            }
        }

        inSpeicher(apps) {
            try {
                sessionStorage.setItem(SPEICHER, JSON.stringify({ zeit: Date.now(), apps }));
            } catch {
                // Privates Fenster o.ae. — dann eben jedes Mal frisch fragen.
            }
        }

        /* ---------------------------------------------------------------- */

        zeichnen(apps) {
            this.apps = apps;

            // Ein einziger Eintrag heisst: es gibt nichts zu wechseln. Dann
            // ist auch der Griff zu viel — er verspraeche eine Wahl.
            const zeigen = apps.length > 1;

            this.wrap.hidden = !zeigen;

            if (!zeigen) {
                return;
            }

            this.leiste.innerHTML = apps
                .map((app) => {
                    const aktuell = app.aktuell === true;
                    const ziel = aktuell ? '' : ` href="${this.sicher(app.url)}"`;

                    // Das Kuerzel liegt UNTER dem Bild, nicht statt seiner:
                    // Faellt das Icon aus, steht dort weiter etwas Lesbares,
                    // statt eines leeren Kreises.
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
                })
                .join('');

            // KEIN loading="lazy": Die Leiste ist eingeklappt, solange niemand
            // sie oeffnet — ein verzoegertes Bild in einem verborgenen Element
            // laedt der Browser gar nicht erst, und beim ersten Ausfahren
            // stuenden dort vier leere Kacheln.
            //
            // Ein Icon, das nicht kommt, darf keine leere Kachel hinterlassen:
            // erst die zweite Adresse versuchen, dann das Bild ganz entfernen.
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
        }

        zumachen() {
            clearTimeout(this.schliessUhr);

            if (!this.offen) {
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
        .griff, .leiste, .kachel, .hinweis { transition: none; }
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
