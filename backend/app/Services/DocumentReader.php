<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Reads the plain text of an attached document (PDF, Word .docx, or a text file).
 */
class DocumentReader
{
    public const EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'csv'];

    /**
     * @return array{text: string, truncated: bool}
     */
    public function read(UploadedFile $file, int $maxChars): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $text = match ($extension) {
                'pdf' => $this->pdfText($file->getRealPath()),
                'docx' => $this->docxText($file->getRealPath()),
                default => $this->plainText($file->getRealPath()),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail('The file could not be read. It may be damaged or password-protected.');
        }

        // Tidy whitespace: PDFs often carry runs of spaces and blank lines.
        $text = preg_replace(["/[ \t\x{00A0}]+/u", "/\n{3,}/"], [' ', "\n\n"], str_replace("\r", '', $text ?? ''));
        $text = trim((string) $text);

        if ($text === '') {
            $this->fail('No readable text was found in the file. Scanned documents need to be sent as images.');
        }

        $truncated = mb_strlen($text) > $maxChars;

        return ['text' => $truncated ? mb_substr($text, 0, $maxChars) : $text, 'truncated' => $truncated];
    }

    private function pdfText(string $path): string
    {
        $pdf = (new Parser)->parseFile($path);
        $text = $pdf->getText();

        if (! preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        // PDFs store Arabic in visual (left-to-right drawn) order, so the parser's
        // text comes out reversed. Rebuild each line from its positioned pieces
        // and put it back into reading order.
        $lines = [];

        foreach ($pdf->getPages() as $page) {
            $rows = [];

            foreach ($page->getDataTm() as [$matrix, $piece]) {
                $rows[(int) round((float) $matrix[5])][] = [(float) $matrix[4], $piece];
            }

            krsort($rows);

            foreach ($rows as $pieces) {
                usort($pieces, fn ($a, $b) => $a[0] <=> $b[0]);
                $line = implode('', array_column($pieces, 1));

                // Diacritics drawn on their own are reported as separate rows; drop them.
                if (preg_match('/^[\p{Mn}\s]*$/u', $line) && trim($line) !== '') {
                    continue;
                }

                $lines[] = preg_match('/\p{Arabic}/u', $line) ? $this->toReadingOrder($line) : $line;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Reverse a visually ordered right-to-left line, keeping runs of Latin
     * words and numbers left-to-right.
     */
    private function toReadingOrder(string $line): string
    {
        $reversed = implode('', array_reverse(mb_str_split($line)));

        $reversed = preg_replace_callback(
            '/[\p{Latin}\p{N}](?:[\p{Latin}\p{N}.:\/\-_@%+, ]*[\p{Latin}\p{N}])?/u',
            fn ($m) => implode('', array_reverse(mb_str_split($m[0]))),
            $reversed,
        );

        // A numbered heading such as "1. ..." comes out as ".1 ...".
        return preg_replace('/^(\s*)\.(\d+)/u', '$1$2.', $reversed);
    }

    private function docxText(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true || ($stat = $zip->statName('word/document.xml')) === false) {
            $this->fail('This is not a valid Word (.docx) file.');
        }

        // A small upload can unpack to a huge document; refuse those.
        if ($stat['size'] > 20 * 1024 * 1024 || ($xml = $zip->getFromName('word/document.xml')) === false) {
            $this->fail('The Word file is too large to read.');
        }

        $zip->close();

        // Keep paragraph, line break and tab boundaries, then drop the markup.
        $xml = preg_replace(['#</w:p>#', '#<w:br[^>]*/>#', '#<w:tab/>#'], ["\n", "\n", "\t"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function plainText(string $path): string
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->fail('Text files must be saved as UTF-8.');
        }

        return $text;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
