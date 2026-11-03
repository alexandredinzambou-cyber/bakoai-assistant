<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/** A request location is part of a parameter contract, not just its presentation. */
class ParameterScope
{
    private const SECTIONS = ['body' => 'Corps de la requête', 'path' => 'Paramètres de chemin', 'query' => 'Paramètres de requête', 'headers' => 'En-têtes'];

    public function requested(string $question): ?array
    {
        $flat = Str::ascii(mb_strtolower($question));
        $patterns = ['body' => '\bcorps\b', 'path' => '\bchemin\b', 'query' => '\b(?:parametres|champs)(?:\s+(?:obligatoires?|optionnels?|facultatifs?|requis))*\s+de\s+(?:la\s+)?requete\b', 'headers' => '\ben[- ]tetes?\b'];
        $positive = $flat;
        $included = [];
        $excluded = [];
        foreach ($patterns as $key => $pattern) {
            $negative = '/\b(?:sans|ni|pas|evite[rz]?|exclu(?:s|re|ez))\s+(?:(?:les|des|de|du|le|la|champs|parametres|obligatoires|optionnels|facultatifs)\s+)*'.$pattern.'/u';
            if (preg_match($negative, $flat) === 1) {
                $excluded[] = $key;
                $positive = preg_replace($negative, '', $positive) ?? $positive;
            }
        }
        foreach ($patterns as $key => $pattern) {
            if (preg_match('/'.$pattern.'/u', $positive) === 1) {
                $included[] = $key;
            }
        }
        if ($included === [] && $excluded === []) {
            return preg_match('/\btous les (?:parametres|champs)\b/u', $positive) === 1 ? array_keys(self::SECTIONS) : null;
        }

        return array_values(array_diff($included !== [] ? $included : array_keys(self::SECTIONS), $excluded));
    }

    public function key(?array $scope): string
    {
        $scope ??= array_keys(self::SECTIONS);
        sort($scope);

        return implode('+', $scope);
    }

    public function filter(array $groups, string $question): array
    {
        $scope = $this->requested($question);
        if ($scope === null) {
            return $groups;
        }

        return array_intersect_key($groups, array_flip(array_map(static fn ($key) => self::SECTIONS[$key], $scope)));
    }

    public function directive(string $key): string
    {
        $sections = array_intersect_key(self::SECTIONS, array_flip(explode('+', $key)));

        return ' Périmètre des paramètres : '.implode(' et ', $sections).'.';
    }
}
