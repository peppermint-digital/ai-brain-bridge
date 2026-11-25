<?php

namespace Peppermint\AiBrainBridge\Switcher;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\AiBrainBridge\Config\BridgeConfig;

/**
 * Die Favoriten (Stern) der Umschaltleiste im Produkt selbst (pm #10219).
 *
 * Bastian, 25.11.2026: „jedes system haelt wieder seine eigenen daten". Bis
 * dahin lagen die Favoriten in AI Brain und kamen nur mit gemeinsamer
 * Anmeldung in die Leiste. Ohne sie fehlten sie ganz.
 *
 * Fuer JEDE angemeldete Person, unabhaengig von einer Verbindung zu AI Brain:
 * Ein Favorit ist eine Adresse in diesem System, also Daten dieses Systems.
 *
 * Die Regeln sind die von AI Brain, damit die Leiste unveraendert bleibt:
 * dieselbe Nutzlast (`id`, `label`, `url`, `slug`, `farbe`, `symbol`),
 * hoechstens zwoelf, eine Adresse nur einmal, Name hoechstens 80 Zeichen,
 * Symbol nur aus der Liste der Leiste.
 */
class Favoriten
{
    /**
     * Die Symbole, die die Leiste zeichnen kann — dieselbe Liste wie in AI
     * Brain (`SwitcherSymbole`) und in `app-switcher.js` (`SYMBOLE`).
     */
    public const SYMBOLE = [
        'mail', 'kalender', 'datei', 'ordner', 'person', 'gruppe', 'haus', 'stern',
        'herz', 'fahne', 'lupe', 'uhr', 'chat', 'telefon', 'ort', 'euro',
        'diagramm', 'haken', 'regler', 'buch', 'paket', 'gluehbirne',
    ];

    /** Dieselbe Palette wie AI Brain fuer Systeme ohne eigene Farbe. */
    protected const PALETTE = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6'];

    /**
     * Liegen die Favoriten in diesem Produkt?
     *
     * Ausdruecklich `local` oder `brain` gewinnt. Ohne Angabe: mit gemeinsamer
     * Anmeldung in AI Brain (so war es, und so bleibt es dort), sonst hier.
     */
    public static function lokal(): bool
    {
        $wo = config('ai-brain-bridge.switcher.pins.store');

        if ($wo === 'local' || $wo === 'brain') {
            return $wo === 'local';
        }

        return ! config('ai-brain-bridge.login.enabled');
    }

    /**
     * @return list<array{id: int, label: string, url: string, slug: string, farbe: string, symbol: ?string}>
     */
    public function liste(Authenticatable $person): array
    {
        return Favorit::query()
            ->where('user_id', $person->getAuthIdentifier())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Favorit $favorit): array => $this->zeile($favorit))
            ->values()
            ->all();
    }

    /**
     * @return array{pin: array<string, mixed>}|array{fehler: string}
     */
    public function anlegen(Authenticatable $person, string $url, string $label, mixed $symbol = null): array
    {
        if (! $this->gehoertHierher($url)) {
            return ['fehler' => 'Diese Adresse gehört nicht zu diesem System.'];
        }

        $hash = Favorit::fingerabdruck($url);
        $da = Favorit::query()->where('user_id', $person->getAuthIdentifier())->where('url_hash', $hash)->first();

        if ($da !== null) {
            return ['pin' => $this->zeile($da)];
        }

        $anzahl = Favorit::query()->where('user_id', $person->getAuthIdentifier())->count();
        $hoechstens = $this->hoechstens();

        if ($anzahl >= $hoechstens) {
            return ['fehler' => 'Mehr als '.$hoechstens.' Favoriten passen nicht in die Leiste.'];
        }

        $favorit = Favorit::query()->create([
            'user_id' => $person->getAuthIdentifier(),
            'url' => $url,
            'url_hash' => $hash,
            'label' => self::name($label),
            'symbol' => self::symbol($symbol),
            'position' => (int) Favorit::query()->where('user_id', $person->getAuthIdentifier())->max('position') + 1,
        ]);

        return ['pin' => $this->zeile($favorit)];
    }

    /**
     * `$symbol` nur aendern, wenn es mitkam — sonst bliebe nach einem reinen
     * Umbenennen das Symbol nicht stehen.
     *
     * @return array<string, mixed>|null null, wenn es diesen Favoriten fuer diese Person nicht gibt
     */
    public function aendern(Authenticatable $person, int $id, string $label, bool $mitSymbol, mixed $symbol = null): ?array
    {
        $favorit = Favorit::query()->where('user_id', $person->getAuthIdentifier())->whereKey($id)->first();

        if ($favorit === null) {
            return null;
        }

        $favorit->update([
            'label' => self::name($label),
            ...($mitSymbol ? ['symbol' => self::symbol($symbol)] : []),
        ]);

        return $this->zeile($favorit);
    }

    public function entfernen(Authenticatable $person, int $id): void
    {
        Favorit::query()->where('user_id', $person->getAuthIdentifier())->whereKey($id)->delete();
    }

    /**
     * Nur Adressen dieses Systems: dieselbe Herkunft wie `app.url`.
     *
     * Ein Vergleich des Anfangs der Zeichenkette genuegte nicht —
     * `https://pm.test.boese.example` beginnt mit `https://pm.test`.
     */
    public function gehoertHierher(string $url): bool
    {
        $basis = rtrim((string) config('app.url'), '/');

        if ($basis === '') {
            return false;
        }

        return $url === $basis || str_starts_with($url, $basis.'/') || str_starts_with($url, $basis.'?') || str_starts_with($url, $basis.'#');
    }

    public function hoechstens(): int
    {
        return max(1, (int) config('ai-brain-bridge.switcher.pins.hoechstens', 12));
    }

    public static function name(string $label): string
    {
        return mb_substr(trim($label), 0, 80);
    }

    public static function symbol(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }

        $wert = mb_strtolower(trim($wert));

        return in_array($wert, self::SYMBOLE, true) ? $wert : null;
    }

    public static function slug(): string
    {
        return (string) (BridgeConfig::load()['source'] ?? config('ai-brain-bridge.source'));
    }

    public function farbe(): string
    {
        $farbe = config('ai-brain-bridge.switcher.pins.farbe');

        if (is_string($farbe) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $farbe) === 1) {
            return $farbe;
        }

        return self::PALETTE[abs(crc32(self::slug())) % count(self::PALETTE)];
    }

    /**
     * @return array{id: int, label: string, url: string, slug: string, farbe: string, symbol: ?string}
     */
    public function zeile(Favorit $favorit): array
    {
        return [
            'id' => (int) $favorit->id,
            'label' => (string) $favorit->label,
            'url' => (string) $favorit->url,
            'slug' => self::slug(),
            'farbe' => $this->farbe(),
            'symbol' => $favorit->symbol,
        ];
    }
}
