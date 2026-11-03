<?php

namespace Tests\Unit;

use App\Services\Assistant\LocalImageTextExtractor;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

class LocalImageTextExtractorTest extends TestCase
{
    public function test_it_reads_only_the_local_process_output(): void
    {
        // PHP stands in for the OCR executable and reads the temporary input file.
        config()->set('rag.image_ocr_binary', PHP_BINARY);
        $input = UploadedFile::fake()->createWithContent('capture.png', '<?php echo "api_key=private-test-token";');

        $this->assertSame('api_key=private-test-token', (new LocalImageTextExtractor)->extract($input));
    }

    public function test_a_failed_local_process_returns_no_partial_text(): void
    {
        config()->set('rag.image_ocr_binary', PHP_BINARY);
        $input = UploadedFile::fake()->createWithContent('capture.png', '<?php echo "partial"; exit(1);');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Local image inspection unavailable.');

        (new LocalImageTextExtractor)->extract($input);
    }
}
