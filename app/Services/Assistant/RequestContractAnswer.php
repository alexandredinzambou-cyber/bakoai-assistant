<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Render complete required-field sets from one documented API contract. */
class RequestContractAnswer
{
    private function candidates(string $question, Collection $chunks): Collection
    {
        $subject = (new ConversationPlan)->subject($question);
        $words = ['refund' => 'rembours', 'fees' => 'frais', 'balance' => 'solde', 'kyc' => 'identit', 'payment' => 'initier un paiement'];
        if (! isset($words[$subject])) {
            return collect();
        }

        return $chunks->filter(static fn ($c) => str_contains(Str::ascii(mb_strtolower((string) data_get($c, 'document_titre', ''))), $words[$subject]));
    }

    private function sections(object $chunk, bool $required = true): array
    {
        $groups = [];
        $section = 'Champs obligatoires';
        foreach (preg_split('/\R/u', $chunk->contenu) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s+(Corps de la requête|Paramètres de chemin|Paramètres de requête|En-têtes)/u', $line, $heading) === 1) {
                $section = $heading[1];
            }
            if (preg_match('/^-\s+([A-Za-z_][A-Za-z0-9_-]*)\s+\(([^)]*)\)\s*[—–-]\s*(.+)$/iu', $line, $field) === 1) {
                $marker = $field[2].' '.$field[3];
                $isRequired = preg_match('/\b(?:obligatoire|requis)\b/iu', $marker) === 1;
                $isOptional = preg_match('/\b(?:optionnel|facultatif)\b/iu', $marker) === 1;
                if (($required && $isRequired && ! $isOptional) || (! $required && $isOptional && ! $isRequired)) {
                    $groups[$section][$field[1]] = $field[3];
                }
            }
        }

        return $groups;
    }

    private function scoped(array $groups, string $question): array
    {
        return (new ParameterScope)->filter($groups, $question);
    }

    /** Merge compatible fragments of the same API, keeping each field's source. */
    private function contract(Collection $candidates, bool $required, string $question): ?array
    {
        $groups = [];
        $statuses = [];
        foreach ($candidates as $chunk) {
            $section = 'Champs obligatoires';
            foreach (preg_split('/\R/u', $chunk->contenu) ?: [] as $line) {
                if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $heading) === 1) {
                    $section = $heading[1];
                }
                if (preg_match('/^-\s+([A-Za-z_][A-Za-z0-9_-]*)\s+\(([^)]*)\)\s*[—–-]\s*(.+)$/iu', $line, $field) === 1) {
                    $marker = $field[2].' '.$field[3];
                    $status = [preg_match('/\b(?:obligatoire|requis)\b/iu', $marker), preg_match('/\b(?:optionnel|facultatif)\b/iu', $marker)];
                    $key = $section.':'.$field[1];
                    if (isset($statuses[$key]) && $statuses[$key] !== $status) {
                        return null;
                    }
                    $statuses[$key] = $status;
                }
            }
            foreach ($this->scoped($this->sections($chunk, $required), $question) as $section => $fields) {
                foreach ($fields as $name => $description) {
                    if (isset($groups[$section][$name]) && $groups[$section][$name]['description'] !== $description) {
                        return null;
                    }
                    $groups[$section][$name] = ['description' => $description, 'chunk' => $chunk];
                }
            }
        }

        return $groups;
    }

    public function combinedFields(string $question, string $resolved, Collection $chunks): ?array
    {
        if ((new ConversationActions)->parameterKind($question) !== 'both') {
            return null;
        }
        $required = $this->requiredFields($question.' paramètres obligatoires', $resolved, $chunks);
        $optional = $this->optionalFields($question.' paramètres optionnels', $resolved, $chunks);
        if ($required === null || $optional === null) {
            return null;
        }

        return ['answer' => "### Obligatoires\n".$required['answer']."\n\n### Optionnels\n".$optional['answer'], 'parameter_kind' => 'both', 'chunk' => $required['chunk'], 'chunks' => $required['chunks']->merge($optional['chunks'])->unique('chunk_id')];
    }

    public function optionalFields(string $question, string $resolved, Collection $chunks): ?array
    {
        if (preg_match('/champs|param[eè]tres|en[- ]t[eê]tes/iu', $question) !== 1 || ! in_array((new ConversationActions)->parameterKind($question), ['optional', 'both'], true)) {
            return null;
        }
        $candidates = $this->candidates($resolved, $chunks)->filter(fn ($c) => $this->sections($c) !== [] || $this->sections($c, false) !== []);
        if ($candidates->isEmpty()) {
            return null;
        }
        $chunk = $candidates->first();
        $groups = $this->contract($candidates, false, $question);
        if ($groups === null) {
            return null;
        }
        if ($groups === []) {
            return ['answer' => 'Les extraits fournis pour cet appel ne listent pas de paramètre optionnel dans le périmètre demandé [SOURCE:'.$chunk->chunk_id.'].', 'parameter_kind' => 'optional', 'chunk' => $chunk, 'chunks' => $candidates];
        }
        $lines = [];
        foreach ($groups as $section => $fields) {
            foreach ($fields as $name => $field) {
                $lines[] = '- `'.$name.'` : '.$field['description'].' [SOURCE:'.$field['chunk']->chunk_id.'].';
            }
        }

        return ['answer' => implode("\n", $lines), 'parameter_kind' => 'optional', 'chunk' => $chunk, 'chunks' => $candidates];
    }

    public function requiredFields(string $question, string $resolved, Collection $chunks): ?array
    {
        if (preg_match('/champs|param[eè]tres|en[- ]t[eê]tes/iu', $question) !== 1 || ! in_array((new ConversationActions)->parameterKind($question), ['required', 'both'], true)) {
            return null;
        }
        $candidates = $this->candidates($resolved, $chunks)->filter(fn ($c) => $this->sections($c) !== [] || $this->sections($c, false) !== []);
        if ($candidates->isEmpty()) {
            return null;
        }
        $groups = $this->contract($candidates, true, $question);
        if ($groups === null || $groups === []) {
            return null;
        }
        $chunk = $candidates->first();
        $blocks = [];
        foreach ($groups as $section => $fields) {
            $lines = [$section.' — champs obligatoires :'];
            foreach ($fields as $name => $field) {
                $lines[] = '- `'.$name.'` : '.$field['description'].' [SOURCE:'.$field['chunk']->chunk_id.'].';
            }
            $blocks[] = implode("\n", $lines);
        }

        return ['answer' => implode("\n\n", $blocks), 'parameter_kind' => 'required', 'chunk' => $chunk, 'chunks' => $candidates];
    }

    public function refundProcedure(string $question, Collection $chunks): ?array
    {
        if ((new ConversationActions)->parameterKind($question) === 'optional') {
            return null;
        }
        if ((new ParameterScope)->requested($question) !== null && preg_match('/champs|param[eè]tres/iu', $question) === 1) {
            return null;
        }
        $flat = Str::ascii(mb_strtolower($question));
        if (! str_contains($flat, 'rembours') || preg_match('/procedure|comment|initier|etapes|parcours/u', $flat) !== 1) {
            return null;
        }
        $candidates = $this->candidates('remboursement', $chunks);
        $bodies = $candidates->filter(fn ($c) => isset($this->sections($c)['Corps de la requête']));
        if ($bodies->isEmpty() || $bodies->map(fn ($c) => json_encode(array_keys($this->sections($c)['Corps de la requête'])))->unique()->count() !== 1) {
            return null;
        }
        $body = $bodies->first();
        $example = $candidates->first(static fn ($c) => str_contains($c->contenu, 'GIVE_CHANGE') && preg_match('/```bash\s*curl.*?```/su', $c->contenu) === 1);
        $synchronous = $bodies->every(static fn ($c) => str_contains(Str::ascii(mb_strtolower($c->contenu)), 'le remboursement est traite de facon synchrone')
            && preg_match('/aucun webhook n[’\']est n[eé]cessaire/iu', $c->contenu) === 1);
        if ($example === null || ! str_contains($example->contenu, 'X-Secret') || ! $synchronous) {
            return null;
        }
        $lines = ['1. Préparez tous les champs obligatoires du corps de remboursement [SOURCE:'.$body->chunk_id.'] :'];
        foreach ($this->sections($body)['Corps de la requête'] as $name => $description) {
            $lines[] = '   - `'.$name.'` : '.$description;
        }
        $lines[] = "\n2. Transmettez la requête avec l’en-tête X-Secret documenté [SOURCE:".$example->chunk_id.'].';
        if (! (new ConversationActions)->declinesExamples($question)) {
            preg_match('/```bash\s*(curl.*?)```/su', $example->contenu, $curl);
            $lines[] = "\nExemple documentaire ; remplacez ses valeurs par les vôtres [SOURCE:".$example->chunk_id."].\n\n```bash\n".trim($curl[1])."\n```";
        }
        $lines[] = "\n3. Traitez la réponse synchrone : elle contient le statut final et aucun webhook n’est nécessaire [SOURCE:".$body->chunk_id.'].';

        return ['answer' => implode("\n", $lines), 'chunk' => $body, 'chunks' => collect([$body, $example])];
    }
}
