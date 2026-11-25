<?php

namespace Peppermint\AiBrainBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Peppermint\AiBrainBridge\Kontoverbindung;
use Peppermint\AiBrainBridge\Switcher\AiBrainLeiste;
use Peppermint\AiBrainBridge\Switcher\Favorit;
use Peppermint\AiBrainBridge\Switcher\Favoriten;

/**
 * Bestehende Favoriten aus AI Brain ins Produkt uebernehmen (pm #10219).
 *
 * Bis pm #10219 lagen die Favoriten in AI Brain. Gelesen wird ueber die
 * Verbindung: je verbundener Person ({@see Kontoverbindung}), in ihrem Namen,
 * aus derselben Antwort, die frueher die Leiste fuellte. Uebernommen werden
 * nur Adressen DIESES Produkts — ein Favorit auf AI Brain gehoert dorthin.
 *
 * - ohne Zusatz: Trockenlauf, zaehlt nur.
 * - `--ausfuehren`: schreibt, in einer Transaktion, und merkt sich die neuen
 *   Zeilen in einer Datei unter storage/app/ai-brain-favoriten.
 * - `--zuruecknehmen=<datei>`: entfernt genau diese Zeilen wieder.
 *
 * Antwortet AI Brain fuer eine Person nicht, wird NICHTS geschrieben: Eine
 * halbe Uebernahme saehe aus wie eine ganze.
 *
 * AI Brain selbst bleibt unveraendert; die Favoriten liegen dort weiter.
 */
class FavoritenUebernehmenCommand extends Command
{
    protected $signature = 'ai-brain:favoriten-uebernehmen
        {--ausfuehren : Schreiben statt nur zaehlen}
        {--zuruecknehmen= : Datei einer frueheren Uebernahme — entfernt deren Favoriten}';

    protected $description = 'Favoriten der Umschaltleiste aus AI Brain ins Produkt uebernehmen (Trockenlauf ohne --ausfuehren)';

    public function handle(Favoriten $favoriten, AiBrainLeiste $leiste, Kontoverbindung $verbindung): int
    {
        if (! Favoriten::lokal()) {
            $this->error('Die Favoriten liegen in diesem Produkt nicht lokal (ai-brain-bridge.switcher.pins.store).');

            return self::FAILURE;
        }

        if (is_string($this->option('zuruecknehmen')) && $this->option('zuruecknehmen') !== '') {
            return $this->zuruecknehmen((string) $this->option('zuruecknehmen'));
        }

        $ausfuehren = (bool) $this->option('ausfuehren');
        $this->line($ausfuehren ? 'Uebernahme' : 'Trockenlauf — es wird nichts geschrieben.');

        // 1. Erst ALLES lesen. Schreiben erst, wenn jede Person geantwortet hat.
        $plan = [];

        foreach ($this->personen() as $person) {
            if (! $verbindung->istVerbunden($person)) {
                continue;
            }

            $antwort = $leiste->abrufen($verbindung->adresse($person));

            if ($antwort === null) {
                $this->error('AI Brain ist fuer Person #'.$person->getAuthIdentifier().' nicht erreichbar — abgebrochen, nichts geschrieben.');

                return self::FAILURE;
            }

            $hier = array_values(array_filter(
                $antwort['pins'],
                fn (mixed $pin): bool => is_array($pin) && is_string($pin['url'] ?? null) && $favoriten->gehoertHierher($pin['url']),
            ));

            $plan[] = ['person' => $person, 'pins' => $hier];
        }

        // 2. Zaehlen und (wenn gewollt) schreiben.
        $quelle = 0;
        $neu = 0;
        $schonDa = 0;
        $angelegt = [];

        $schreiben = function () use ($plan, $ausfuehren, &$quelle, &$neu, &$schonDa, &$angelegt): void {
            foreach ($plan as $eintrag) {
                $id = $eintrag['person']->getAuthIdentifier();
                $position = (int) Favorit::query()->where('user_id', $id)->max('position');

                foreach ($eintrag['pins'] as $pin) {
                    $quelle++;
                    $hash = Favorit::fingerabdruck($pin['url']);

                    if (Favorit::query()->where('user_id', $id)->where('url_hash', $hash)->exists()) {
                        $schonDa++;

                        continue;
                    }

                    $neu++;

                    if (! $ausfuehren) {
                        continue;
                    }

                    $angelegt[] = Favorit::query()->create([
                        'user_id' => $id,
                        'url' => $pin['url'],
                        'url_hash' => $hash,
                        'label' => Favoriten::name((string) ($pin['label'] ?? $pin['url'])),
                        'symbol' => Favoriten::symbol($pin['symbol'] ?? null),
                        'position' => ++$position,
                    ])->id;
                }
            }
        };

        $ausfuehren ? DB::transaction($schreiben) : $schreiben();

        $this->info(sprintf(
            '%d verbundene Personen, %d Favoriten dieses Produkts, %d neu, %d schon da.',
            count($plan), $quelle, $neu, $schonDa,
        ));

        if (! $ausfuehren) {
            return self::SUCCESS;
        }

        // 3. Gegenprobe: Jede Quelle steht jetzt im Ziel.
        $ziel = 0;

        foreach ($plan as $eintrag) {
            $hashes = array_map(fn (array $pin): string => Favorit::fingerabdruck($pin['url']), $eintrag['pins']);
            $ziel += Favorit::query()
                ->where('user_id', $eintrag['person']->getAuthIdentifier())
                ->whereIn('url_hash', $hashes)
                ->count();
        }

        $this->info("Gegenprobe: Quelle {$quelle} = Ziel {$ziel}".($quelle === $ziel ? '' : ' — ABWEICHUNG'));

        if ($angelegt !== []) {
            $datei = storage_path('app/ai-brain-favoriten/uebernahme-'.now()->format('Ymd-His').'.json');
            File::ensureDirectoryExists(dirname($datei));
            File::put($datei, (string) json_encode(['ids' => $angelegt], JSON_PRETTY_PRINT));
            $this->line('Ruecknahme: --zuruecknehmen='.$datei);
        }

        return $quelle === $ziel ? self::SUCCESS : self::FAILURE;
    }

    protected function zuruecknehmen(string $datei): int
    {
        if (! File::exists($datei)) {
            $this->error('Datei nicht gefunden.');

            return self::FAILURE;
        }

        $ids = json_decode((string) File::get($datei), true)['ids'] ?? null;

        if (! is_array($ids)) {
            $this->error('Datei hat kein Feld ids.');

            return self::FAILURE;
        }

        $entfernt = Favorit::query()->whereKey(array_map('intval', $ids))->delete();
        $this->info($entfernt.' entfernt.');

        return self::SUCCESS;
    }

    /**
     * Alle Personen des Produkts, in Bloecken — ueber das Modell aus der
     * Anmelde-Konfiguration, damit das Paket keine Klasse des Produkts kennt.
     *
     * @return iterable<Authenticatable>
     */
    protected function personen(): iterable
    {
        $modell = config('auth.providers.users.model');

        if (! is_string($modell) || ! is_subclass_of($modell, Model::class)) {
            return [];
        }

        return $modell::query()->orderBy((new $modell)->getKeyName())->lazy();
    }
}
