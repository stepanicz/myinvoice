<?php

declare(strict_types=1);

namespace MyInvoice\Service\Recurring;

/**
 * Nahrazuje proměnné v textech pro pravidelné faktury.
 * Custom feature — ne v upstreamu.
 *
 * Podporované placeholdery (v {} závorkách):
 *
 *   Aktuální období:
 *     {YYYY}    — rok (4 cifry, např. "2026")
 *     {YY}      — rok (2 cifry, např. "26")
 *     {MM}      — měsíc (2 cifry, "01"–"12")
 *     {M}       — měsíc bez nuly ("1"–"12")
 *     {MMMM}    — název měsíce v jazyce faktury ("květen" / "May")
 *     {Q}       — číslo čtvrtletí ("1"–"4")
 *     {period}  — popisné období podle frekvence:
 *                   monthly:   "květen 2026" / "May 2026"
 *                   quarterly: "1. čtvrtletí 2026" / "Q1 2026"
 *                   yearly:    "rok 2026" / "year 2026"
 *
 *   Předchozí období: {prev:YYYY}, {prev:MM}, {prev:MMMM}, {prev:Q}, {prev:period}, ...
 *   Další období:     {next:YYYY}, {next:MM}, ...
 *
 *   Posun period závisí na frekvenci: monthly = ±1 měsíc, quarterly = ±3 měsíce,
 *   yearly = ±1 rok.
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

    /**
     * @param string $text       text s proměnnými
     * @param string $frequency  monthly|quarterly|yearly
     * @param \DateTimeInterface $issueDate datum vystavení (= "aktuální" období)
     * @param string $language   cs|en (pro názvy měsíců a popisné období)
     */
    public function render(string $text, string $frequency, \DateTimeInterface $issueDate, string $language = 'cs'): string
    {
        if ($text === '' || strpos($text, '{') === false) {
            return $text;
        }

        $current = \DateTimeImmutable::createFromInterface($issueDate);
        $prev    = $this->shift($current, $frequency, -1);
        $next    = $this->shift($current, $frequency, +1);

        $vars = [];
        foreach ([['', $current], ['prev:', $prev], ['next:', $next]] as [$prefix, $date]) {
            foreach ($this->variablesFor($date, $frequency, $language) as $key => $value) {
                $vars['{' . $prefix . $key . '}'] = $value;
            }
        }

        return strtr($text, $vars);
    }

    /** @return array<string,string> */
    private function variablesFor(\DateTimeImmutable $date, string $frequency, string $language): array
    {
        $month = (int) $date->format('n');
        $year  = $date->format('Y');
        $quarter = (int) ceil($month / 3);
        $monthName = $language === 'en'
            ? self::MONTHS_EN[$month]
            : self::MONTHS_CS[$month];

        return [
            'YYYY'   => $year,
            'YY'     => $date->format('y'),
            'MM'     => $date->format('m'),
            'M'      => (string) $month,
            'MMMM'   => $monthName,
            'Q'      => (string) $quarter,
            'period' => $this->describePeriod($date, $frequency, $language, $monthName, $quarter, $year),
        ];
    }

    private function describePeriod(
        \DateTimeImmutable $date,
        string $frequency,
        string $language,
        string $monthName,
        int $quarter,
        string $year,
    ): string {
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
