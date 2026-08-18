<?php

namespace TwillAi\Services;

use PhpOffice\PhpWord\IOFactory;
use Throwable;

/**
 * Extracts plain text from a .docx file using PhpWord. Word documents are not
 * natively readable by the model, so we flatten them to text and feed that to
 * the agent as context.
 */
class DocxTextExtractor
{
    public function extract(string $absolutePath): string
    {
        try {
            $document = IOFactory::load($absolutePath);
        } catch (Throwable) {
            return '';
        }

        $lines = [];

        foreach ($document->getSections() as $section) {
            $this->walk($section->getElements(), $lines);
        }

        return trim(implode("\n", array_filter($lines, fn (string $line) => $line !== '')));
    }

    /**
     * @param  iterable<object>  $elements
     * @param  array<int, string>  $lines
     */
    protected function walk(iterable $elements, array &$lines): void
    {
        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $text = $element->getText();

                if (is_string($text)) {
                    $lines[] = $text;
                }
            }

            if (method_exists($element, 'getElements')) {
                $this->walk($element->getElements(), $lines);
            } elseif (method_exists($element, 'getRows')) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->walk($cell->getElements(), $lines);
                    }
                }
            }
        }
    }
}
