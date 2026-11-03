<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ContextAssembler;
use Tests\TestCase;

class ContextAssemblerTest extends TestCase
{
    public function test_platform_guides_are_only_selected_when_that_platform_is_requested(): void
    {
        $chunks = collect([
            (object) ['chunk_id' => 1, 'document_titre' => 'Callback PVIT', 'contenu' => 'Configuration callback'],
            (object) ['chunk_id' => 2, 'document_titre' => 'PrestaShop', 'contenu' => 'Guide module'],
            (object) ['chunk_id' => 3, 'document_titre' => 'WooCommerce', 'contenu' => 'Guide plugin'],
        ]);
        $assembler = new ContextAssembler;
        $this->assertSame([1], $assembler->select($chunks, 'Comment configurer le callback ?')->pluck('chunk_id')->all());
        $this->assertSame([1, 2], $assembler->select($chunks, 'Comment configurer le callback PrestaShop ?')->pluck('chunk_id')->all());
    }
}
