<?php

namespace App\Services\Assistant;

/** Reject proven semantic misclassification even while documentary evidence is current. */
class ParameterMemory
{
    public function contradicts(string $kind, string $answer): bool
    {
        if ($kind === 'both') {
            return preg_match('/### Optionnels\R(.*)$/su', $answer, $optional) === 1 && $this->contradicts('optional', $optional[1]);
        }
        $plain = str_replace(['**', '__'], '', $answer);
        $required = preg_match('/\b(?:champs|param[eè]tres)\s+obligatoires(?:\s*\[[^\]\r\n]+\])?\s*(?::|$)/imu', $plain) === 1;
        $optional = preg_match('/\b(?:champs|param[eè]tres)\s+(?:optionnels|facultatifs)(?:\s*\[[^\]\r\n]+\])?\s*(?::|$)/imu', $plain) === 1;

        return $kind === 'optional' && $required && ! $optional || $kind === 'required' && $optional && ! $required;
    }

    public function repair(array $memory): array
    {
        foreach ($memory['subjects'] ?? [] as $name => $snapshot) {
            if (is_array($snapshot)) {
                $memory['subjects'][$name] = $this->repairSnapshot($snapshot);
            } else {
                unset($memory['subjects'][$name]);
            }
        }

        return $this->repairSnapshot($memory);
    }

    private function repairSnapshot(array $memory): array
    {
        $removed = false;
        foreach ($memory['parameter_sets'] ?? [] as $key => $set) {
            $kind = explode(':', $key)[0];
            if (! is_array($set) || ! is_string($set['answer'] ?? null)
                || isset($set['contract_kind']) && $set['contract_kind'] !== $kind || $this->contradicts($kind, $set['answer'])) {
                unset($memory['parameter_sets'][$key]);
                $removed = true;
            }
        }
        if ($removed) {
            foreach (['parameter_answer', 'parameter_question'] as $key) {
                $memory[$key] = '';
            }
            $memory['parameter_sources'] = [];
            $memory['parameter_memory_invalidated'] = true;
            if (preg_match('/param[eè]tres|champs|en[- ]t[eê]tes/iu', $memory['last_question'] ?? '') === 1) {
                foreach (['last_answer', 'last_verified_answer'] as $key) {
                    $memory[$key] = '';
                }
                $memory['last_sources'] = $memory['last_verified_sources'] = [];
            }
        }

        return $memory;
    }
}
