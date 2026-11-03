<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Complete a retrieved guide, without mixing in another document or inactive revision. */
class ProcedureContext
{
    public function sandbox(Collection $chunks): Collection
    {
        $ids = $chunks->filter(static fn ($chunk) => str_contains(Str::ascii(mb_strtolower((string) data_get($chunk, 'document_titre', ''))), 'sandbox'))
            ->pluck('document_id')->filter()->unique()->values();
        if ($ids->count() !== 1) {
            return $chunks;
        }
        $sections = DB::table('chunks')
            ->join('documents_api', 'documents_api.id', '=', 'chunks.document_id')
            ->leftJoin('knowledge_versions', 'knowledge_versions.id', '=', 'documents_api.knowledge_version_id')
            ->where('documents_api.id', $ids->first())
            ->where('documents_api.actif', true)
            ->whereNotNull('documents_api.link_verified_at')
            ->whereNotNull('documents_api.lien_officiel')
            ->where('chunks.status', 'active')
            ->where(function ($query): void {
                $query->where('knowledge_versions.statut', 'active')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('documents_api.knowledge_version_id')
                            ->whereNotExists(function ($active): void {
                                $active->selectRaw('1')->from('knowledge_versions')->where('statut', 'active');
                            });
                    });
            })
            ->orderBy('chunks.position')->orderBy('chunks.id')->limit(25)
            ->get(['chunks.id as chunk_id', 'chunks.document_id', 'chunks.contenu', 'chunks.section', 'documents_api.titre as document_titre', 'documents_api.lien_officiel']);

        return $chunks->concat($sections)->unique('chunk_id')->values();
    }
}
