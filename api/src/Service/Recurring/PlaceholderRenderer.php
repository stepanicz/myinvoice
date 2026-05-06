<?php

declare(strict_types=1);

namespace MyInvoice\Service\Recurring;

/**
 * Nahrazuje proměnné v textech pro pravidelné faktury.
 * Custom feature — ne v upstreamu.
 *
 * Dvě syntaxe:
 *
 * 1) CURLY-BRACE placeholdery (původní systém):
 *
 *   Aktuální období:
 *     {YYYY}    — rok (4 cifry, např. "2026")
 *     {YY}      — rok (2 cifry, např. "26")
 *     {MM}      — měsíc (2 cifry, "01"–"12")
 *     {M}       — měsíc bez nuly ("1"–"12")
 *     {MMMM}    — měsíc (2 cifry, alias pro {MM} — historicky to byl název měsíce,
 *                 změněno 2026-05-06 na číslo per uživatelův požadavek)
 *     {Q}       — číslo čtvrtletí ("1"–"4")
 *     {period}  — popisné období podle frekvence:
 *                   monthly:   "květen 2026" / "May 2026"  (jediné kde zůstává název měsíce)
 *                   quarterly: "1. čtvrtletí 2026" / "Q1 2026"
 *                   yearly:    "rok 2026" / "year 2026"
 *
 *   Předchozí období: {prev:YYYY}, {prev:MM}, {prev:MMMM}, {prev:Q}, {prev:period}, ...
 *   Další období:     {next:YYYY}, {next:MM}, ...
 *
 *   Posun period závisí na frekvenci (monthly = ±1 měsíc, quarterly = ±3, yearly = ±1 rok).
 *
 * 2) PARENS-ARITHMETIC syntaxe (CUSTOM stepanicz, intuitivní pro účetnictví):
 *
 *     (MMMM)/(YYYY)        — měsíc/rok faktury, např. "05/2026"
 *     (MMMM-1)/(YYYY)      — předchozí měsíc/rok s carryover přes přelom roku:
 *                             5/2026 → "04/2026"
 *                             1/2027 → "12/2026"  (yyyy ZE STAVU PŘEDCHOZÍHO MĚSÍCE)
 *     (MMMM+1)/(YYYY)      — následující měsíc/rok (taky carryover)
 *     (MMMM-N)/(YYYY)      — N měsíců zpět
 *     (YYYY)               — jen rok
 *     (YYYY-1)             — předchozí rok
 *     (YYYY+1)             — následující rok
 *
 *   Tato syntaxe NEZÁVISÍ na frekvenci — vždy posouvá o měsíce (resp. roky).
 *   Case insensitive: (mmmm-1)/(yyyy) funguje stejně.
 */
final class PlaceholderRenderer
{
    private const MONTHS_CS = [
        1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec',
    ];

    private const MONTHS_EN = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    public function render(string $text, string $frequency, \DateTimeInterface $issueDate, string $language = 'cs'): string
    {
        if ($text === '' || (strpos($text, '{') === false && strpos($text, '(') === false)) {
            return $text;
        }

        $current = \DateTimeImmutable::createFromInterface($issueDate);

        // 1) PARENS-ARITHMETIC syntaxe — vyhodnotit nejdřív, výstup je čistý "MM/YYYY" / "YYYY".

        // (MMMM±N)/(YYYY) → date.modify("±N month").format("m/Y") — carryover přes přelom roku
        $text = preg_replace_callback(
            '/\(MMMM([+-]\d+)?\)\/\(YYYY\)/i',
            function (array $m) use ($current): string {
                $shifted = $this->shiftMonths($current, isset($m[1]) ? (int) $m[1] : 0);
                return $shifted->format('m/Y');
            },
            $text,
        ) ?? $text;

        // (YYYY±N) → date.modify("±N year").format("Y")
        $text = preg_replace_callback(
            '/\(YYYY([+-]\d+)?\)/i',
            function (array $m) use ($current): string {
                $offset = isset($m[1]) ? (int) $m[1] : 0;
                $shifted = $offset === 0 ? $current : $current->modify(sprintf('%+d year', $offset));
                return $shifted->format('Y');
            },
            $text,
        ) ?? $text;

        // (MMMM±N) standalone bez /(YYYY) → jen číslo měsíce s carryover (ale rok ne)
        $text = preg_replace_callback(
            '/\(MMMM([+-]\d+)?\)/i',
            function (array $m) use ($current): string {
                $shifted = $this->shiftMonths($current, isset($m[1]) ? (int) $m[1] : 0);
                return $shifted->format('m');
            },
            $text,
        ) ?? $text;

        // 2) CURLY-BRACE placeholdery — nadále podporované pro {prev:...}, {next:...}, {period}, atd.
        if (strpos($text, '{') === false) {
            return $text;
        }

        $prev = $this->shift($current, $frequency, -1);
        $next = $this->shift($current, $frequency, +1);

        $vars = [];
        foreach ([['', $current], ['prev:', $prev], ['next:', $next]] as [$prefix, $date]) {
            foreach ($this->variablesFor($date, $frequency, $language) as $key => $value) {
                $vars['{' . $prefix . $key . '}'] = $value;
            }
        }
        return strtr($text, $vars);
    }

    private function shiftMonths(\DateTimeImmutable $date, int $offset): \DateTimeImmutable
    {
        if ($offset === 0) return $date;
        return $date->modify(sprintf('%+d month', $offset));
    }

    /** @return array<string,string> */
    private function variablesFor(\DateTimeImmutable $date, string $frequency, string $language): array
    {
        $month = (int) $date->format('n');
        $year  = $date->format('Y');
        $quarter = (int) ceil($month / 3);

        return [
            'YYYY'   => $year,
            'YY'     => $date->format('y'),
            'MM'     => $date->format('m'),
            'M'      => (string) $month,
            'MMMM'   => $date->format('m'),  // CUSTOM stepanicz: změněno z názvu měsíce na 2-digit číslo
            'Q'      => (string) $quarter,
            'period' => $this->describePeriod($date, $frequency, $language, $month, $quarter, $year),
        ];
    }

    private function describePeriod(
        \DateTimeImmutable $date,
        string $frequency,
        string $language,
        int $month,
        int $quarter,
        string $year,
    ): string {
        $monthName = $language === 'en' ? self::MONTHS_EN[$month] : self::MONTHS_CS[$month];
        return match ($frequency) {
            'monthly'   => $monthName . ' ' . $year,
            'quarterly' => $language === 'en'
                ? 'Q' . $quarter . ' ' . $year
                : $quarter . '. čtvrtletí ' . $year,
            'yearly'    => $language === 'en'
                ? 'year ' . $year
                : 'rok ' . $year,
            default     => $year,
        };
    }

    private function shift(\DateTimeImmutable $date, string $frequency, int $direction): \DateTimeImmutable
    {
        $sign = $direction > 0 ? '+' : '-';
        $abs  = abs($direction);
        $modifier = match ($frequency) {
            'monthly'   => "{$sign}{$abs} month",
            'quarterly' => "{$sign}" . ($abs * 3) . ' month',
            'yearly'    => "{$sign}{$abs} year",
            default     => "{$sign}{$abs} month",
        };
        return $date->modify($modifier);
    }

    /**
     * Vypočte další next_run_date po vystavení.
     * Jednoduché posunutí o jednu periodu.
     */
    public function advanceRunDate(\DateTimeInterface $current, string $frequency): \DateTimeImmutable
    {
        return $this->shift(\DateTimeImmutable::createFromInterface($current), $frequency, +1);
    }
}
