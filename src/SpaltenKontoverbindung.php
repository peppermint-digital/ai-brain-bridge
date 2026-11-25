<?php

namespace Peppermint\AiBrainBridge;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Die Vorgabe fuer {@see Kontoverbindung} (pm #10219).
 *
 * - Mit gemeinsamer Anmeldung (`login.enabled`) kommt jede Person aus AI
 *   Brain — sie ist verbunden. Produkte, die so anmelden, bleiben damit, wie
 *   sie waren.
 * - Sonst entscheidet eine Spalte an der Person (`verbindung.spalte`,
 *   Vorgabe `ai_brain_user_id`). Gesetzt wird sie beim Verbinden ueber
 *   /auth/brain/verbinden ({@see Auth\BrainLogin::verbindeUsing()}).
 *
 * Ein Produkt ohne diese Spalte hat niemanden verbunden. Das ist gewollt: Ohne
 * ausdrueckliche Verbindung gibt es keine Funktion aus AI Brain.
 */
class SpaltenKontoverbindung implements Kontoverbindung
{
    public function istVerbunden(?Authenticatable $person): bool
    {
        if ($person === null) {
            return false;
        }

        if (config('ai-brain-bridge.login.enabled')) {
            return true;
        }

        return filled($this->wert($person, (string) config('ai-brain-bridge.verbindung.spalte', 'ai_brain_user_id')));
    }

    public function adresse(?Authenticatable $person): ?string
    {
        if ($person === null) {
            return null;
        }

        if ($this->istVerbunden($person)) {
            $dort = $this->wert($person, (string) config('ai-brain-bridge.verbindung.adresse_spalte', 'ai_brain_email'));

            if (is_string($dort) && trim($dort) !== '') {
                return trim($dort);
            }
        }

        $hier = $this->wert($person, 'email');

        return is_string($hier) && trim($hier) !== '' ? trim($hier) : null;
    }

    protected function wert(Authenticatable $person, string $spalte): mixed
    {
        if ($spalte === '') {
            return null;
        }

        // Eloquent liefert fuer eine fehlende Spalte null; ein schlichtes
        // Objekt kennt `getAttribute` nicht.
        if (method_exists($person, 'getAttribute')) {
            return $person->getAttribute($spalte);
        }

        return $person->{$spalte} ?? null;
    }
}
