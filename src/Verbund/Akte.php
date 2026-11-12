<?php

namespace Peppermint\AiBrainBridge\Verbund;

use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Die Akte eines Vorgangs — binden und loesen (AI Brain #9682).
 *
 * ## Was eine Akte ist
 *
 * Ein Vorgang laeuft ueber mehrere Systeme: Anfrage im CRM, Angebot in der
 * Verwaltung, Produktion im Manager. Jedes System fuehrt seinen Datensatz mit
 * eigener Nummer, und keines weiss, wie der Vorgang anderswo heisst.
 *
 * Die Akte liegt im Brain und haelt nur Verweise: System, Art, Kennung. Sie
 * ist das Bindeglied, kein viertes System — sie hat keinen Status, keine
 * Summe, keinen Namen und wird in keiner Oberflaeche als Karteikarte gezeigt.
 *
 * Sie entsteht ausschliesslich beim BINDEN zweier Datensaetze. Es gibt
 * absichtlich kein „Akte anlegen": Eine Akte mit einem Verweis sagt „dieser
 * Auftrag ist dieser Auftrag".
 *
 * ## Warum das hier liegt und nicht in jedem Produkt
 *
 * Der Aufruf ist in allen Produkten derselbe: dieselben Werkzeugnamen,
 * dieselben Feldnamen, dieselbe Frage, was ein Fehlschlag bedeutet. Eine
 * zweite Kopie veraltet still — und zwar in der Richtung, die niemand
 * bemerkt, weil ein nicht geschriebener Verweis keine Fehlermeldung ist,
 * sondern eine Akte, die eine unvollstaendige Kette als vollstaendige zeigt
 * (siehe {@see Huelle} zum selben Muster).
 *
 * ## Was dieser Helfer NICHT entscheidet
 *
 * Ob ein Fehlschlag wiederholt wird. Er wirft, und zwar absichtlich: Die
 * Wiederholung gehoert in die Warteschlange des Produkts, weil nur dort
 * bekannt ist, ob es eine gibt und wie oft sie es versucht. Ein Helfer, der
 * den Fehler schluckt, nimmt dem Produkt die Entscheidung ab und macht aus
 * einem fehlenden Verweis ein stilles Ereignis.
 *
 * Die eigene Herkunft setzt der Helfer ebenfalls nicht — die liest das Brain
 * aus dem Produkt-Zugang des Aufrufs. Ein System weiss nicht, unter welchem
 * Kuerzel es anderswo gefuehrt wird (Wiki #595).
 */
final class Akte
{
    /** Die Stufen, die eine Akte fuehrt. Belege und Aufgaben gehoeren NICHT dazu. */
    public const ARTEN = ['deal', 'auftrag', 'projekt'];

    /**
     * Zwei Datensaetze zu einem Vorgang binden; die Akte entsteht dabei.
     *
     *     Akte::binden('auftrag', (string) $order->id, 'projekt', (string) $projektId, 'peppermint-manager');
     *
     * Der ERSTE Datensatz ist der eigene — seine Herkunft setzt das Brain aus
     * dem Produkt-Zugang. Fuer den zweiten gehoert `$anderesSystem` dazu, denn
     * beim Binden ruft genau ein Produkt, und der Nachbar-Datensatz liegt
     * woanders.
     *
     * Zweimal dasselbe Paar zu binden ist folgenlos.
     *
     * @param  string|null  $adresse  Pfad des EIGENEN Datensatzes im eigenen
     *                                System, z. B. `/orders/4312`. Am besten
     *                                mit `route('orders.show', $id, false)`
     *                                erzeugt: Das ist die Route selbst und
     *                                kann nicht von ihr abweichen, anders als
     *                                ein Muster in einer Konfiguration.
     *
     * @return string|null Die Kennung der Akte (`D-10417`), oder null, wenn
     *                     die Bruecke nicht eingerichtet ist.
     *
     * @throws \Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException wenn das Brain nicht erreichbar ist
     * @throws \InvalidArgumentException bei einer Art, die die Akte nicht fuehrt
     */
    public static function binden(
        string $art,
        string $kennung,
        string $andereArt,
        string $andereKennung,
        ?string $anderesSystem = null,
        ?string $adresse = null,
    ): ?string {
        self::pruefeArt($art);
        self::pruefeArt($andereArt);

        if (! Bruecke::eingerichtet()) {
            return null;
        }

        $antwort = AiBrain::call('dossier-link-tool', array_filter([
            'a_art' => $art,
            'a_kennung' => $kennung,
            /*
             * Die Adresse gilt nur fuer den EIGENEN Datensatz (#9815).
             *
             * Es gibt mit Absicht kein `b_adresse`: Wer einen fremden
             * Datensatz mitbindet, kennt dessen Adresse nicht und koennte sie
             * nur raten — genau das soll die Adresse am Verweis verhindern.
             * Das Brain lehnt eine fremde Adresse ohnehin ab; hier gibt es sie
             * gar nicht erst zu tippen.
             *
             * PFADANTEIL, kein voller Verweis: Den Rechnernamen haelt das
             * Brain je Produkt (`connected_products.web_url`) und setzt beim
             * Lesen zusammen. Eine zweite Fassung des Hostnamens hier waere
             * dieselbe doppelte Wahrheit, gegen die die Akte gebaut ist — und
             * sie veraltet beim naechsten Domainwechsel still.
             */
            'a_adresse' => $adresse,
            'b_art' => $andereArt,
            'b_kennung' => $andereKennung,
            'b_system' => $anderesSystem,
        ], fn ($wert): bool => $wert !== null));

        return self::kennungAus($antwort);
    }

    /**
     * Den Verweis auf einen Datensatz loesen — etwa wenn ein Projekt vom
     * falschen Auftrag geloest wird.
     *
     * Ohne das behauptet die Akte weiter eine Bindung, die es nicht mehr gibt,
     * und eine Akte, die Auftrag UND Projekt nennt, sieht nach einer
     * vollstaendigen Kette aus.
     *
     * Die Akte selbst bleibt bestehen, auch wenn nur ein Verweis uebrig ist:
     * Ihre Kennung steht moeglicherweise auf einem Beleg. Zweimal loesen ist
     * folgenlos.
     *
     * @param  string|null  $system  Nur, wenn der Datensatz NICHT im eigenen System liegt.
     * @return bool Ob ein Verweis geloest wurde. `false` heisst „es gab
     *              keinen" und ist kein Fehlschlag.
     *
     * @throws \Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException wenn das Brain nicht erreichbar ist
     * @throws \InvalidArgumentException bei einer Art, die die Akte nicht fuehrt
     */
    public static function loesen(string $art, string $kennung, ?string $system = null): bool
    {
        self::pruefeArt($art);

        if (! Bruecke::eingerichtet()) {
            return false;
        }

        $antwort = AiBrain::call('dossier-unlink-tool', array_filter([
            'art' => $art,
            'kennung' => $kennung,
            'system' => $system,
        ], fn ($wert): bool => $wert !== null));

        return (bool) Huelle::wert($antwort, 'geloest', false);
    }

    /**
     * Die Kennung aus der Antwort schaelen.
     *
     * Ueber `Huelle::wert()`, weil dieselbe Nutzlast je Weg in verschiedenen
     * Huellen ankommt — und weil `schaelen()` eine Huelle nur an einer LISTE
     * erkennt. Die Kennung ist eine Zeichenkette; mit `schaelen()` waere hier
     * immer eine leere Huelle angekommen.
     *
     * Fehlt sie trotzdem, ist das ein Fehler und keine leere Antwort: Ein
     * `null` hier liesse sich als „nicht eingerichtet" lesen, und dann faende
     * niemand den fehlenden Verweis.
     */
    private static function kennungAus(array $antwort): string
    {
        $kennung = Huelle::wert($antwort, 'kennung');

        if (! is_string($kennung) || $kennung === '') {
            throw new \Peppermint\AiBrainBridge\Exceptions\AiBrainBridgeException(
                'Die Antwort von dossier-link-tool nennt keine Akten-Kennung.'
            );
        }

        return $kennung;
    }

    private static function pruefeArt(string $art): void
    {
        if (! in_array($art, self::ARTEN, true)) {
            // Fruehe, laute Absage: Eine unbekannte Art kommt vom Brain als
            // Fehlertext zurueck, und der landet dann in einem Job-Versuch
            // statt beim Entwickler.
            throw new \InvalidArgumentException(
                "Die Akte fuehrt die Stufen ".implode(', ', self::ARTEN).", nicht „{$art}“."
            );
        }
    }
}
