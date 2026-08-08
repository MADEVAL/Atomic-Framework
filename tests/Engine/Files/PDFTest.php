<?php
declare(strict_types=1);

namespace Tests\Engine\Files;

use Engine\Atomic\Core\App;
use Engine\Atomic\Files\PDF;
use PHPUnit\Framework\TestCase;

final class PDFTest extends TestCase
{
    public function test_constructor_falls_back_to_bundled_fonts(): void
    {
        $atomic = App::instance();

        $emptyFonts = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_pdf_fonts_' . uniqid() . DIRECTORY_SEPARATOR;
        $tempOut    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_pdf_out_' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($emptyFonts);
        mkdir($tempOut);

        $originalFonts = $atomic->get('FONTS');
        $originalTemp  = $atomic->get('FONTS_TEMP');

        try {
            $atomic->set('FONTS', $emptyFonts);
            $atomic->set('FONTS_TEMP', $tempOut);

            $pdf = new PDF($tempOut . 'out.pdf');

            $this->assertInstanceOf(PDF::class, $pdf);
        } finally {
            $atomic->set('FONTS', $originalFonts);
            $atomic->set('FONTS_TEMP', $originalTemp);
            @unlink($tempOut . 'out.pdf');
            @rmdir($emptyFonts);
            @rmdir($tempOut);
        }
    }
}
