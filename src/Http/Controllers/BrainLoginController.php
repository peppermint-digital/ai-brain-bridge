<?php

namespace Peppermint\AiBrainBridge\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Peppermint\AiBrainBridge\Auth\BrainLogin;
use Peppermint\AiBrainBridge\Verbund\Kontomeldung;

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
     * Läuft gerade ein Verbinden statt einer Anmeldung? Dann führen Fehler
     * zurück auf die Seite, von der aus verbunden wurde — nicht auf die
     * Anmeldemaske, die eine angemeldete Person gar nicht sieht.
     */
    protected bool $verbinden = false;

    /**
     * Konto mit AI Brain verbinden (pm #10162): derselbe Weg wie die
     * Anmeldung, aber nur für eine schon angemeldete Person, und der Rückruf
     * meldet niemanden an, sondern übergibt dem Produkt die Kennung.
     */
    public function connect(Request $request): RedirectResponse
    {
        $config = $this->config();
        $this->verbinden = true;

        $user = Auth::guard($config['guard'])->user();

        abort_if($user === null, 403);

        if ($config['client_id'] === null || $config['client_id'] === '') {
            return $this->zurueckMitFehler('Die Verbindung mit AI Brain ist hier nicht eingerichtet.');
        }

        $request->session()->put('ai_brain_login.zweck', 'verbinden');
        $request->session()->put('ai_brain_login.verbinder', $user->getAuthIdentifier());

        return $this->zuAiBrain($request, $config);
    }

    /**
     * Hin zu AI Brain — mit frischem Prüfwort (PKCE) und Zustand in der Sitzung.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $config = $this->config();

        if ($config['client_id'] === null || $config['client_id'] === '') {
            return $this->zurueckMitFehler('Die Anmeldung über AI Brain ist hier nicht eingerichtet.');
        }

        // Ein früher begonnenes Verbinden ist mit dem Anmeldestart vorbei.
        $request->session()->forget(['ai_brain_login.zweck', 'ai_brain_login.verbinder']);

        return $this->zuAiBrain($request, $config);
    }

    /**
     * Prüfwort (PKCE) und Zustand in die Sitzung, dann zu AI Brain.
     *
     * @param  array<string, mixed>  $config
     */
    protected function zuAiBrain(Request $request, array $config): RedirectResponse
    {
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
        $this->verbinden = $request->session()->pull('ai_brain_login.zweck') === 'verbinden';
        $verbinder = $request->session()->pull('ai_brain_login.verbinder');

        // Jeder Weg nur mit seinem eigenen Schalter. Der Rückruf ist auch
        // registriert, wenn nur das Verbinden an ist — dann meldet er nicht an.
        if ($this->verbinden ? ! $config['connect'] : ! $config['enabled']) {
            return $this->zurueckMitFehler($this->verbinden
                ? 'Die Verbindung mit AI Brain ist hier nicht eingeschaltet.'
                : 'Die Anmeldung über AI Brain ist hier abgeschaltet.');
        }

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

        if ($this->verbinden) {
            return $this->verbindeAngemeldete($config, $daten, $verbinder);
        }

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
     * Die Person aus AI Brain an die angemeldete Person dieses Produkts
     * hängen. Nur wenn noch dieselbe Person angemeldet ist, die das Verbinden
     * begonnen hat — sonst landete die Kennung bei jemand anderem.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $person
     */
    protected function verbindeAngemeldete(array $config, array $person, mixed $verbinder): RedirectResponse
    {
        $user = Auth::guard($config['guard'])->user();

        if ($user === null || $verbinder === null || (string) $user->getAuthIdentifier() !== (string) $verbinder) {
            return $this->zurueckMitFehler('Die Verbindung wurde von einer anderen Sitzung begonnen. Bitte erneut verbinden.');
        }

        if (! isset($person['id']) || ! is_scalar($person['id']) || (string) $person['id'] === '') {
            return $this->zurueckMitFehler('AI Brain konnte die angemeldete Person nicht nennen.');
        }

        $fehler = BrainLogin::verbinde($user, $person);

        if ($fehler !== null) {
            return $this->zurueckMitFehler($fehler);
        }

        // AI Brain sofort Bescheid geben, nicht erst bei der naechsten
        // Messung (pm #10265). Gelesen wird die Person frisch — der Verbinder
        // hat die Spalte eben erst geschrieben.
        Kontomeldung::melden(method_exists($user, 'fresh') ? ($user->fresh() ?? $user) : $user);

        return redirect()->to($config['connect_after'])
            ->with('status', 'Dein Konto ist jetzt mit AI Brain verbunden.');
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
            'enabled' => (bool) ($login['enabled'] ?? false),
            'connect' => (bool) ($login['connect'] ?? false),
            'connect_after' => (string) ($login['connect_after'] ?? '/'),
            'timeout' => (int) ($login['timeout'] ?? 15),
            'redirect_uri' => url((string) ($login['callback_path'] ?? '/auth/brain/callback')),
        ];
    }

    /**
     * Zurück zur gewohnten Anmeldemaske — mit einem Satz, der den Grund nennt.
     */
    protected function zurueckMitFehler(string $meldung): RedirectResponse
    {
        if ($this->verbinden) {
            return redirect()->to($this->config()['connect_after'])
                ->withErrors(['ai_brain' => $meldung])
                ->with('error', $meldung);
        }

        $route = (string) config('ai-brain-bridge.login.failure_route', 'login');

        $ziel = \Illuminate\Support\Facades\Route::has($route)
            ? redirect()->route($route)
            : redirect()->to('/login');

        return $ziel->withErrors(['email' => $meldung])->with('error', $meldung);
    }
}
