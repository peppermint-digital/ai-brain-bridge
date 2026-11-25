<?php

/**
 * Die Umschaltleiste nimmt ihre Farben aus dem Theme des Produkts (pm #10236).
 *
 * Sie sitzt im Shadow DOM und hatte bis dahin eigene Hex-Werte (zinc). Ein
 * Produkt mit einem anderen Theme bekam damit eine Leiste in fremden Farben.
 * CSS-Variablen erben aber in den Shadow DOM hinein: `var(--background)` im
 * `:host` liest die des Produkts. Der bisherige Wert bleibt als Rueckfall
 * stehen — fuer eine Seite ohne shadcn-Variablen.
 *
 * Geprueft wird der Quelltext wie beim Telefon-Platz (Login/SwitcherTest):
 * Die Leiste laesst sich in PHP nicht rendern, und ein Umbau am Aussehen nimmt
 * die Variablen sonst unbemerkt wieder mit.
 */
function switcherStil(): string
{
    $quelle = file_get_contents(__DIR__.'/../../resources/js/app-switcher.js');
    $start = mb_strpos($quelle, ':host {');
    $ende = mb_strpos($quelle, '.wrap {', $start);

    return mb_substr($quelle, $start, $ende - $start);
}

it('liest die Grundfarben des Produkts, mit dem bisherigen Wert als Rueckfall', function (string $schalter, string $produkt) {
    $stil = switcherStil();

    // In beiden Modi: `:host` und `:host(.dunkel)`.
    expect(substr_count($stil, "--{$schalter}: var(--{$produkt}, "))->toBe(2, "--{$schalter} liest nicht --{$produkt} des Produkts");
})->with([
    ['grund', 'background'],
    ['rand', 'border'],
    ['schrift', 'foreground'],
    ['gedaempft', 'muted-foreground'],
    ['akzent', 'accent'],
]);

it('setzt keine Farbe mehr ohne Produkt-Variable davor', function () {
    $stil = switcherStil();

    // Jede Hex-Angabe muss Rueckfall in einem var(...) sein.
    preg_match_all('/:\s*(#[0-9a-fA-F]{3,8})\s*;/', $stil, $nackt);

    expect($nackt[1])->toBe([]);
});

it('nimmt fuer das Abzeichen das Rot und die Schrift des Produkts', function () {
    $quelle = file_get_contents(__DIR__.'/../../resources/js/app-switcher.js');

    expect($quelle)->toContain('background: var(--destructive, #dc2626);')
        ->and($quelle)->toContain('font-family: var(--font-sans, ui-sans-serif');
});

/**
 * Ein Schalter, der seinen eigenen Namen liest (`--ring: … var(--ring)`), ist
 * ein Zyklus — der Browser verwirft ihn, und der Fokusring waere weg. Die
 * Leiste heisst ihre Schalter teils wie die shadcn-Variablen, deshalb hier
 * eigens.
 */
it('liest keinen Schalter aus sich selbst', function () {
    preg_match_all('/--([a-z-]+):\s*([^;]+);/', switcherStil(), $paare, PREG_SET_ORDER);

    $zyklen = collect($paare)
        ->filter(fn (array $paar) => str_contains($paar[2], "var(--{$paar[1]}"))
        ->map(fn (array $paar) => trim($paar[0]))
        ->values()
        ->all();

    expect($zyklen)->toBe([]);
});
