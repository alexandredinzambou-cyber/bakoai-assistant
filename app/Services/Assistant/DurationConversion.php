<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class DurationConversion
{
    private const FACTORS = ['seconde' => 1, 'minute' => 60, 'heure' => 3600, 'jour' => 86400];

    public function isConversionFollowUp(string $question): bool
    {
        $flat = trim(Str::ascii(mb_strtolower($question)));

        return preg_match('/^(?:et\s+)?en\s+(?:secondes?|minutes?|heures?|jours?|semaines?|mois|annees?)\b/u', $flat) === 1
            || preg_match('/^convertis?\w*\s+(?:cette|la)\s+duree\s+en\b/u', $flat) === 1;
    }

    public function requestedUnit(string $question): ?string
    {
        return $this->requestedUnits($question)[0] ?? null;
    }

    /** @return list<string> */
    public function requestedUnits(string $question): array
    {
        $flat = Str::ascii(mb_strtolower($question));
        $units = '(secondes?|minutes?|heures?|jours?)';
        if (preg_match('/(?:convertis?\w* (?:cette|la) duree en|^(?:et )?en|\b(?:donne|exprime|affiche|presente)\b.{0,35}?\b(?:reponse|resultat)\s+en|\bcombien de|\b(?:duree|delai|validite)\b.{0,90}?\ben)\s+'.$units.'\b/u', $flat, $start) !== 1) {
            return [];
        }
        $tail = substr($flat, strpos($flat, $start[0]) + strlen($start[0]));
        $result = [rtrim($start[1], 's')];
        while (preg_match('/^\s*(?:,\s*(?:(?:et|puis)\s+)?|et\s+|puis\s+|ou\s+)(?:en\s+)?'.$units.'\b/u', $tail, $next) === 1) {
            $result[] = rtrim($next[1], 's');
            $tail = substr($tail, strlen($next[0]));
        }

        if ($this->isConversionFollowUp($question)
            && preg_match('/^\s*[,;]?\s*(?:(?:uniquement|seulement)\s*[,;]?\s*)?(?:(?:svp|stp|s[ ’\']il (?:te|vous) plait)\s*)?[.!?\s]*$/u', $tail) !== 1
            && preg_match('/^\s*[,;]?\s*(?:pas|sans|ni)\b/u', $tail) !== 1) {
            return [];
        }

        return array_values(array_unique($result));
    }

    public function convertMany(array $fact, array $targets): string
    {
        return implode("\n\n", array_map(fn ($target) => $this->convert($fact, $target), $targets));
    }

    public function extract(string $answer): ?array
    {
        preg_match_all('/(?<![\p{L}\d.,])([+\-]?\d[\d \x{00A0}\x{202F}.,eE+\-]*?)\s+(secondes?|minutes?|heures?|jours?)\b/iu', $answer, $matches, PREG_SET_ORDER);
        $facts = [];
        foreach ($matches as $match) {
            $number = trim($match[1]);
            if (preg_match('/^(?:\d+(?:[.,]\d+)?|\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d+)?)$/u', $number) !== 1) {
                return null;
            }
            $value = (float) str_replace(',', '.', preg_replace('/[ \x{00A0}\x{202F}]/u', '', $number));
            $unit = rtrim(mb_strtolower($match[2]), 's');
            $seconds = $value * self::FACTORS[$unit];
            $facts[(string) $seconds] = ['value' => $value, 'unit' => $unit];
        }

        return count($facts) === 1 ? reset($facts) : null;
    }

    public function convert(array $fact, string $target): string
    {
        $target = rtrim(mb_strtolower($target), 's');
        if (! isset(self::FACTORS[$target],self::FACTORS[$fact['unit']])) {
            throw new \InvalidArgumentException('Unsupported duration unit.');
        }
        $value = $fact['value'] * self::FACTORS[$fact['unit']] / self::FACTORS[$target];
        $format = static fn ($n) => rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');

        return $format($fact['value']).' '.$fact['unit'].((float) $fact['value'] === 1.0 ? '' : 's').' correspondent à '.$format($value).' '.$target.((float) $value === 1.0 ? '' : 's').'. Le point de départ du délai reste inchangé.';
    }
}
