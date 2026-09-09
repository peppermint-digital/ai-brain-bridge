<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Peppermint\AiBrainBridge\Auth\BrainLogin;

/**
 * „Mit AI Brain anmelden" (AI Brain #5266).
 *
 * Der Anmeldeweg über die Kontrollebene: authorization_code mit PKCE, Scope
 * `identity`. AI Brain sagt nur, WER da kommt — ob diese Person hier hereindarf,
 * entscheiden zwei Stellen, und beide sind fail-closed:
 *
 * 1. AI Brain selbst weist ab, wenn im Verzeichnis kein Haken für dieses
 *    Produkt steht (Middleware EnsureProductLoginAllowed).
 * 2. Dieses Produkt lässt nur herein, wen es schon als Konto gibt und wem der
 *    Zugang nicht entzogen wurde.
 *
 * Der bisherige Anmeldeweg bleibt vollständig bestehen. Dieser hier ist ein
 * zweiter Knopf, kein Ersatz — solange er nicht ausdrücklich abgeschaltet wird,
 * kann niemand durch diesen Umbau ausgesperrt werden.
 */
class BrainLoginController
{
    /**
     * Hin zu AI Brain — mit frischem Prüfwort (PKCE) und Zustand in der Sitzung.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $config = $this->config();

        if ($config['client_id'] === null || $config['client_id'] === '') {
            return $this->zurueckMitFehler('Die Anmeldung über AI Brain ist hier nicht eingerichtet.');
        }

        $verifier = Str::random(96);
        $state = Str::random(40);

        $request->session()->put('ai_brain_login.verifier', $verifier);
        $request->session()->put('ai_brain_login.state', $state);

        // Wohin nach der Anmeldung — nur produkteigene Pfade, nie ein von außen
        // gereichtes Ziel. Sonst wäre der Anmeldeknopf eine Weiterleitungs-Rampe.
        $ziel = $request->query('ziel');

        if (is_string($ziel) && str_starts_with($ziel, '/') && ! str_starts_with($ziel, '//')) {
            $request->session()->put('ai_brain_login.ziel', $ziel);
        }

        $frage = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $config['scope'],
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away(rtrim($config['base_url'], '/').'/oauth/authorize?'.$frage);
    }

    /**
     * Zurück von AI Brain — Code eintauschen, Person holen, hier anmelden.
     */
    public function callback(Request $request): RedirectResponse
    {
        $config = $this->config();

        $verifier = $request->session()->pull('ai_brain_login.verifier');
        $state = $request->session()->pull('ai_brain_login.state');
        $ziel = $request->session()->pull('ai_brain_login.ziel');

        if ($request->query('error') !== null) {
            // AI Brain hat abgelehnt — meist: kein Haken im Verzeichnis.
            return $this->zurueckMitFehler('AI Brain hat die Anmeldung für dieses System abgelehnt.');
        }

        if (! is_string($state) || $state === '' || $request->query('state') !== $state) {
            return $this->zurueckMitFehler('Die Anmeldung ist abgelaufen. Bitte erneut versuchen.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || ! is_string($verifier)) {
            return $this->zurueckMitFehler('Die Anmeldung ist unvollständig zurückgekommen.');
        }

        $antwort = Http::asForm()
            ->timeout($config['timeout'])
            ->post(rtrim($config['base_url'], '/').'/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $config['redirect_uri'],
                'code_verifier' => $verifier,
                'code' => $code,
            ]);

        if (! $antwort->successful() || ! is_string($antwort->json('access_token'))) {
            Log::warning('AI-Brain-Anmeldung: Code-Tausch fehlgeschlagen', [
                'status' => $antwort->status(),
            ]);

            // 429 ist keine Ablehnung, sondern eine Drossel. „Nicht bestätigt"
            // schickt jemanden auf die Suche nach einem Rechteproblem, das es
            // nicht gibt — und zum wiederholten Versuch, der die Drossel
            // frisch nachlädt.
            return $this->zurueckMitFehler($antwort->status() === 429
                ? 'Zu viele Anmeldeversuche in kurzer Zeit. Bitte eine Minute warten und erneut versuchen.'
                : 'AI Brain hat die Anmeldung nicht bestätigt.');
        }

        $person = Http::withToken((string) $antwort->json('access_token'))
            ->timeout($config['timeout'])
            ->acceptJson()
            ->get(rtrim($config['base_url'], '/').'/api/v1/me');

        if (! $person->successful() || ! is_string($person->json('email'))) {
            Log::warning('AI-Brain-Anmeldung: Person nicht abrufbar', [
                'status' => $person->status(),
            ]);

            return $this->zurueckMitFehler('AI Brain konnte die angemeldete Person nicht nennen.');
        }

        $daten = (array) $person->json();
        $user = BrainLogin::resolve($daten);

        if ($user === null) {
            // Bewusst kein Konto anlegen: Wer hier fehlt, wird im Verzeichnis
            // freigeschaltet — dabei entsteht das Konto kontrolliert.
            Log::warning('AI-Brain-Anmeldung: kein Konto in diesem System', [
                'email' => $daten['email'] ?? null,
            ]);

            return $this->zurueckMitFehler('Für diese Person gibt es hier kein Konto. Bitte im AI Brain freischalten lassen.');
        }

        if (! BrainLogin::darfAnmelden($user)) {
            return $this->zurueckMitFehler('Dein Zugang zu diesem System wurde entzogen.');
        }

        Auth::guard($config['guard'])->login($user, remember: false);
        $request->session()->regenerate();

        return redirect()->intended(is_string($ziel) && $ziel !== '' ? $ziel : $config['after_login']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $login = (array) config('ai-brain-bridge.login', []);
        $basis = rtrim((string) config('ai-brain-bridge.base_url'), '/');

        return [
            'base_url' => $basis,
            'client_id' => $login['client_id'] ?? null,
            'client_secret' => $login['client_secret'] ?? null,
            'scope' => (string) ($login['scope'] ?? 'identity'),
            'guard' => (string) ($login['guard'] ?? 'web'),
            'after_login' => (string) ($login['after_login'] ?? '/'),
            'timeout' => (int) ($login['timeout'] ?? 15),
            'redirect_uri' => url((string) ($login['callback_path'] ?? '/auth/brain/callback')),
        ];
    }

    /**
     * Zurück zur gewohnten Anmeldemaske — mit einem Satz, der den Grund nennt.
     */
    protected function zurueckMitFehler(string $meldung): RedirectResponse
    {
        $route = (string) config('ai-brain-bridge.login.failure_route', 'login');

        $ziel = \Illuminate\Support\Facades\Route::has($route)
            ? redirect()->route($route)
            : redirect()->to('/login');

        return $ziel->withErrors(['email' => $meldung])->with('error', $meldung);
    }
}
