<?php

namespace App\Services\Assistant;

/** Durable, bounded snapshots of useful answers, separate from presentation and refusals. */
class ConversationMemory
{
    private function restore(array $memory, array $snapshot): array
    {
        // Old snapshots may omit newer fields. Missing facts must not leak from another topic.
        $topic = $snapshot['topic_question'] ?? $snapshot['resolved_question'] ?? $snapshot['last_question'] ?? '';
        $defaults = ['topic_question' => $topic, 'last_question' => $topic, 'resolved_question' => $topic, 'last_answer' => '', 'last_sources' => [], 'plan_answer' => '',
            'last_payment_method' => 'PVIT', 'last_operation' => null, 'last_verified_answer' => $snapshot['last_answer'] ?? '', 'last_verified_sources' => $snapshot['last_sources'] ?? [],
            'duration_fact' => null, 'parameter_answer' => '', 'parameter_sources' => [], 'parameter_sets' => [], 'active_parameter_kind' => null, 'active_parameter_scope' => null, 'parameter_memory_invalidated' => false, 'parameter_question' => '', 'payload_answer' => '', 'payload_sources' => [], 'payload_examples' => [],
            'plan_steps' => [], 'plan_sources' => [], 'plan_subject' => null, 'plan_index' => 0, 'plan_selected' => preg_match('/^Étape \d+\s*:/u', $snapshot['last_answer'] ?? '') === 1, 'evidence_stamp' => null, 'recent_questions' => []];

        return array_merge($memory, $defaults, $snapshot);
    }

    public function invalidate(array $memory): array
    {
        $subject = (new ConversationPlan)->subject($memory['topic_question'] ?? '');
        if ($subject !== null) {
            unset($memory['subjects'][$subject]);
        }
        foreach (['last_answer', 'last_verified_answer', 'parameter_answer', 'parameter_question', 'payload_answer', 'plan_answer'] as $key) {
            $memory[$key] = '';
        }
        foreach (['last_sources', 'last_verified_sources', 'parameter_sources', 'parameter_sets', 'payload_sources', 'payload_examples', 'plan_sources', 'plan_steps'] as $key) {
            $memory[$key] = [];
        }
        foreach (['plan_subject', 'duration_fact', 'active_parameter_kind', 'active_parameter_scope', 'evidence_stamp'] as $key) {
            $memory[$key] = null;
        }
        $memory['plan_index'] = 0;
        $memory['plan_selected'] = false;

        return $memory;
    }

    public function forQuestion(array $memory, string $question): array
    {
        $subject = (new ConversationPlan)->subject($question);
        $current = (new ConversationPlan)->subject($memory['topic_question'] ?? '');
        if ($subject !== null && ! isset($memory['subjects'][$subject])) {
            foreach (array_reverse($memory['subjects'] ?? [], true) as $snapshot) {
                $owner = $snapshot['plan_subject'] ?? (new ConversationPlan)->procedureSubject($snapshot['topic_question'] ?? '');
                if ($owner === $subject && ! empty($snapshot['plan_steps'])) {
                    return $this->restore($memory, $snapshot);
                }
            }
        }
        if ($subject !== null && $subject !== $current && isset($memory['subjects'][$subject])) {
            $memory = $this->restore($memory, $memory['subjects'][$subject]);
        }

        return $memory;
    }

    public function retain(array $previous, array $next, bool $verified, bool $presentation): array
    {
        if (! $verified) {
            // A rejected draft is not a new fact or a new procedure.
            return $previous;
        }
        if ($presentation) {
            $next['last_question'] = $previous['last_question'] ?? $next['last_question'];
            $next['recent_questions'] = $previous['recent_questions'] ?? [];
        }
        $next['last_verified_answer'] = $presentation ? ($previous['last_verified_answer'] ?? $previous['last_answer'] ?? '') : $next['last_answer'];
        $next['last_verified_sources'] = $presentation ? ($previous['last_verified_sources'] ?? $previous['last_sources'] ?? []) : $next['last_sources'];
        $next['subjects'] = $previous['subjects'] ?? [];
        $subject = (new ConversationPlan)->subject($next['topic_question'] ?? '');
        if ($subject !== null) {
            $snapshot = array_diff_key($next, ['subjects' => true]);
            unset($next['subjects'][$subject]);
            $next['subjects'][$subject] = $snapshot;
            $next['subjects'] = array_slice($next['subjects'], -10, null, true);
        }

        return $next;
    }
}
