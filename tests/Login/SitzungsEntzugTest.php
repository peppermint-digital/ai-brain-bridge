<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peppermint\AiBrainBridge\Auth\BrainLogin;
use Peppermint\AiBrainBridge\Auth\SitzungenBeenden;
use Peppermint\AiBrainBridge\Events\AiBrainEventReceived;
use Peppermint\AiBrainBridge\Events\Event;

/**
 * Ein entzogener Zugang beendet die laufende Sitzung (AI Brain #5343).
 *
 * Der Kern: Der Entzug greift SOFORT, nicht erst bei der naechsten Anmeldung —
 * und er trifft nur die gemeinte Person.
 */
beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    Schema::dropIfExists('sessions');
    Schema::create('sessions', function ($table) {
        $table->string('id')->primary();
        $table->integer('user_id')->nullable()->index();
        $table->text('payload');
        $table->integer('last_activity')->index();
    });

    BrainLogin::resolveUsing(null);

    $this->person = LoginTestUser::create(['name' => 'Chris', 'email' => 'chris@example.test']);
    $this->andere = LoginTestUser::create(['name' => 'Volker', 'email' => 'volker@example.test']);

    foreach ([[$this->person->id, 's1'], [$this->person->id, 's2'], [$this->andere->id, 's3']] as [$uid, $sid]) {
        DB::table('sessions')->insert(['id' => $sid, 'user_id' => $uid, 'payload' => '', 'last_activity' => time()]);
    }
});

function entzug(string $email): AiBrainEventReceived
{
    return new AiBrainEventReceived(Event::fromArray([
        'id' => 'evt_1',
        'type' => 'user.access.revoked',
        'source' => 'ai-brain',
        'occurred_at' => now()->toIso8601String(),
        'idempotency_key' => 'evt_1',
        'payload' => ['email' => $email, 'grund' => 'Zugang im Verzeichnis entzogen'],
    ]));
}

it('beendet die Sitzungen der genannten Person — und nur ihre', function () {
    app(SitzungenBeenden::class)->handle(entzug('chris@example.test'));

    expect(DB::table('sessions')->where('user_id', $this->person->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->andere->id)->count())->toBe(1);
});

it('beendet auch die Sitzungen auf anderen Geraeten', function () {
    // Zwei Sitzungen derselben Person = zwei Browser. Eine davon stehenzulassen
    // waere kein Entzug, sondern eine Unannehmlichkeit.
    expect(DB::table('sessions')->where('user_id', $this->person->id)->count())->toBe(2);

    app(SitzungenBeenden::class)->handle(entzug('chris@example.test'));

    expect(DB::table('sessions')->where('user_id', $this->person->id)->count())->toBe(0);
});

it('tut nichts bei einer Person, die es hier gar nicht gibt', function () {
    app(SitzungenBeenden::class)->handle(entzug('niemand@example.test'));

    expect(DB::table('sessions')->count())->toBe(3);
});

it('tut nichts bei einem anderen Ereignis', function () {
    $fremd = new AiBrainEventReceived(Event::fromArray([
        'id' => 'evt_2',
        'type' => 'task.created',
        'source' => 'ai-brain',
        'occurred_at' => now()->toIso8601String(),
        'idempotency_key' => 'evt_2',
        'payload' => ['email' => 'chris@example.test'],
    ]));

    app(SitzungenBeenden::class)->handle($fremd);

    expect(DB::table('sessions')->count())->toBe(3);
});

it('folgt der Zuordnung des Produkts, wenn es eine eigene hat', function () {
    // Ein Produkt mit abweichender Zuordnung ueberschreibt die Aufloesung
    // einmal — und beide Wege (Anmeldung und Entzug) folgen ihr. Sonst kaeme
    // jemand ueber den einen Weg herein und liesse sich ueber den anderen
    // nicht hinauswerfen.
    BrainLogin::resolveUsing(fn (array $person) => $person['email'] === 'anders@example.test'
        ? $this->person
        : null);

    app(SitzungenBeenden::class)->handle(entzug('anders@example.test'));

    expect(DB::table('sessions')->where('user_id', $this->person->id)->count())->toBe(0);
});

it('haengt am Ereignis-Empfang, nicht an einem Schalter', function () {
    // Ein Sicherheitsriegel, den man einschalten muss, ist bei dem einen
    // Produkt aus, bei dem es darauf ankommt.
    AiBrainEventReceived::dispatch(entzug('chris@example.test')->event);

    expect(DB::table('sessions')->where('user_id', $this->person->id)->count())->toBe(0);
});
