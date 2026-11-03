<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;

/** Fingerprint persisted evidence so a corpus change cannot silently reuse old instructions. */
class ConversationEvidence
{
    public function capture(array $sources): ?array
    {
        $ids = array_values(array_unique(array_filter(array_column($sources, 'chunk_id'))));
        sort($ids);
        if ($ids === []) {
            return null;
        }
        $rows = DB::table('chunks')->join('documents_api', 'documents_api.id', '=', 'chunks.document_id')
            ->leftJoin('knowledge_versions', 'knowledge_versions.id', '=', 'documents_api.knowledge_version_id')
            ->whereIn('chunks.id', $ids)->orderBy('chunks.id')
            ->get(['chunks.id', 'chunks.contenu', 'chunks.status', 'documents_api.actif', 'documents_api.link_verified_at', 'documents_api.knowledge_version_id', 'knowledge_versions.statut as version_status']);
        if ($rows->isEmpty()) {
            return null;
        }
        $versions = DB::table('knowledge_versions')->where('statut', 'active')->orderBy('id')->pluck('id')->all();
        $valid = $rows->count() === count($ids) && $rows->every(static fn ($row) => $row->status === 'active' && (bool) $row->actif && $row->link_verified_at !== null && ($row->version_status === 'active' || $row->knowledge_version_id === null && $versions === []));

        return ['ids' => $ids, 'hash' => hash('sha256', json_encode([$rows->all(), $versions])), 'valid' => $valid];
    }

    public function isCurrent(?array $stamp): bool
    {
        if ($stamp === null) {
            return true;
        } // Older records acquire a stamp on their next verified turn.
        $now = $this->capture(array_map(static fn ($id) => ['chunk_id' => $id], $stamp['ids']));

        return $now !== null && $now['valid'] && hash_equals($stamp['hash'], $now['hash']);
    }
}
