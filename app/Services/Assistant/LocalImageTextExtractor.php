<?php

namespace App\Services\Assistant;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Process;

class LocalImageTextExtractor
{
    /** Extract locally: an unchecked screenshot must never be sent to an external OCR API. */
    public function extract(UploadedFile $image): string
    {
        $process = new Process([
            (string) config('rag.image_ocr_binary', 'tesseract'),
            $image->getPathname(),
            'stdout',
        ]);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Local image inspection unavailable.');
        }

        return mb_convert_encoding($process->getOutput(), 'UTF-8', 'UTF-8');
    }
}
