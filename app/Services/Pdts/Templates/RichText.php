<?php

namespace App\Services\Pdts\Templates;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Formatted text from the "Formatted text" question: paragraphs, bold,
 * italic, bullet and numbered lists. Anything else (pasted from Word or a
 * web page) is reduced to those, so what is stored is always safe and the
 * same on the form, in Word and in the PDF.
 */
final class RichText
{
    /**
     * The text as blocks: each a paragraph or list item, with its runs.
     *
     * @return list<array{list: ?string, number: int, runs: list<array{text: string, bold: bool, italic: bool, break: bool}>}>
     */
    public static function blocks(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        // Plain text (older answers, or no formatting): one paragraph per line.
        if (! preg_match('/<[a-z][^>]*>/i', $html)) {
            return array_values(array_map(
                fn (string $line) => ['list' => null, 'number' => 0, 'runs' => [['text' => $line, 'bold' => false, 'italic' => false, 'break' => false]]],
                array_filter(array_map('trim', preg_split('/\R/u', $html)), fn ($line) => $line !== ''),
            ));
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        $blocks = [];
        $loose = [];
        $flush = function () use (&$loose, &$blocks) {
            if (self::hasText($loose)) {
                $blocks[] = ['list' => null, 'number' => 0, 'runs' => self::trimRuns($loose)];
            }
            $loose = [];
        };

        foreach ($body ? iterator_to_array($body->childNodes) : [] as $node) {
            self::walkBlock($node, $blocks, $loose, $flush);
        }
        $flush();

        return $blocks;
    }

    private static function walkBlock(DOMNode $node, array &$blocks, array &$loose, callable $flush): void
    {
        $tag = $node instanceof DOMElement ? strtolower($node->tagName) : null;

        if (in_array($tag, ['ul', 'ol'], true)) {
            $flush();
            $number = 0;
            foreach ($node->childNodes as $item) {
                if ($item instanceof DOMElement && strtolower($item->tagName) === 'li') {
                    $runs = self::trimRuns(self::runs($item));
                    if (self::hasText($runs)) {
                        $blocks[] = ['list' => $tag, 'number' => ++$number, 'runs' => $runs];
                    }
                }
            }

            return;
        }

        if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'li', 'table', 'tr'], true)) {
            $flush();
            // A block holding other blocks (a pasted table, nested divs): walk inside it.
            $hasBlocks = false;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['p', 'div', 'ul', 'ol', 'table', 'tr', 'td', 'th', 'tbody'], true)) {
                    $hasBlocks = true;
                    break;
                }
            }
            if ($hasBlocks) {
                $inner = [];
                foreach ($node->childNodes as $child) {
                    self::walkBlock($child, $blocks, $inner, function () use (&$inner, &$blocks) {
                        if (self::hasText($inner)) {
                            $blocks[] = ['list' => null, 'number' => 0, 'runs' => self::trimRuns($inner)];
                        }
                        $inner = [];
                    });
                }
                if (self::hasText($inner)) {
                    $blocks[] = ['list' => null, 'number' => 0, 'runs' => self::trimRuns($inner)];
                }

                return;
            }

            $runs = self::trimRuns(self::runs($node, str_starts_with((string) $tag, 'h')));
            if (self::hasText($runs)) {
                $blocks[] = ['list' => null, 'number' => 0, 'runs' => $runs];
            }

            return;
        }

        if (in_array($tag, ['td', 'th', 'tbody', 'thead'], true)) {
            foreach ($node->childNodes as $child) {
                self::walkBlock($child, $blocks, $loose, $flush);
            }
            $flush();

            return;
        }

        // Text and inline formatting outside a paragraph.
        array_push($loose, ...self::runs($node));
    }

    /** @return list<array{text: string, bold: bool, italic: bool, break: bool}> */
    private static function runs(DOMNode $node, bool $bold = false, bool $italic = false): array
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = preg_replace('/\s+/u', ' ', $node->textContent);

            return $text === '' ? [] : [['text' => $text, 'bold' => $bold, 'italic' => $italic, 'break' => false]];
        }
        if (! $node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'head', 'title', 'meta', 'xml'], true)) {
            return [];
        }
        if ($tag === 'br') {
            return [['text' => '', 'bold' => false, 'italic' => false, 'break' => true]];
        }

        $style = strtolower((string) $node->getAttribute('style'));
        $bold = $bold || in_array($tag, ['b', 'strong'], true) || preg_match('/font-weight\s*:\s*(bold|[6-9]00)/', $style);
        $italic = $italic || in_array($tag, ['i', 'em'], true) || str_contains($style, 'font-style: italic') || str_contains($style, 'font-style:italic');

        $runs = [];
        foreach ($node->childNodes as $child) {
            array_push($runs, ...self::runs($child, (bool) $bold, (bool) $italic));
        }

        return $runs;
    }

    /** Leading and trailing spaces and line breaks of a block are dropped; neighbouring runs of the same look are joined. */
    private static function trimRuns(array $runs): array
    {
        while ($runs && ($runs[0]['break'] || trim($runs[0]['text']) === '')) {
            array_shift($runs);
        }
        while ($runs && ($runs[count($runs) - 1]['break'] || trim($runs[count($runs) - 1]['text']) === '')) {
            array_pop($runs);
        }
        if ($runs) {
            $runs[0]['text'] = ltrim($runs[0]['text']);
            $runs[count($runs) - 1]['text'] = rtrim($runs[count($runs) - 1]['text']);
        }

        $joined = [];
        foreach ($runs as $run) {
            $last = $joined ? count($joined) - 1 : null;
            if ($last !== null && ! $run['break'] && ! $joined[$last]['break'] && $joined[$last]['bold'] === $run['bold'] && $joined[$last]['italic'] === $run['italic']) {
                $joined[$last]['text'] .= $run['text'];
            } else {
                $joined[] = $run;
            }
        }

        return $joined;
    }

    private static function hasText(array $runs): bool
    {
        foreach ($runs as $run) {
            if (trim($run['text']) !== '') {
                return true;
            }
        }

        return false;
    }

    /** The safe HTML kept for the answer: only p, ul, ol, li, strong, em and br. */
    public static function clean(string $html): string
    {
        $out = '';
        $openList = null;

        foreach (self::blocks($html) as $block) {
            if ($block['list'] !== $openList) {
                $out .= $openList ? '</'.$openList.'>' : '';
                $out .= $block['list'] ? '<'.$block['list'].'>' : '';
                $openList = $block['list'];
            }
            $inner = implode('', array_map(function (array $run) {
                if ($run['break']) {
                    return '<br>';
                }
                $text = htmlspecialchars($run['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = $run['italic'] ? '<em>'.$text.'</em>' : $text;

                return $run['bold'] ? '<strong>'.$text.'</strong>' : $text;
            }, $block['runs']));
            $out .= $block['list'] ? '<li>'.$inner.'</li>' : '<p>'.$inner.'</p>';
        }

        return $out.($openList ? '</'.$openList.'>' : '');
    }

    /** The answer as plain text, one line per paragraph or list item. */
    public static function plain(string $html): string
    {
        return implode("\n", array_map(function (array $block) {
            $text = implode('', array_map(fn ($run) => $run['break'] ? "\n" : $run['text'], $block['runs']));

            return match ($block['list']) {
                'ul' => '• '.$text,
                'ol' => $block['number'].'. '.$text,
                default => $text,
            };
        }, self::blocks($html)));
    }
}
