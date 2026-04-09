<?php

declare(strict_types=1);

namespace Momentum81\PhpRemoveUnusedCss;

/**
 * This is the basic version of the strip tool - this will only
 * check to see if the final level matches, there's no real
 * tree traversal going on, e.g.
 *
 * .this .that { color: red; }
 *
 * Will match <div class="that">, regardless if it is wrapped in
 * another like <div class="this">
 */
class RemoveUnusedCssBasic implements RemoveUnusedCssInterface
{
    /**
     * Traits
     */
    use RemoveUnusedCss;

    /**
     * @var string[]
     */
    protected array $foundUsedCssElements = ['*'];

    /**
     * @var array<string, array<string, array<string, string>>>
     */
    protected array $foundCssStructure = [];

    /**
     * @var array<int, array{filename: string, newFilename: string, source: string}>
     */
    protected array $readyForSave = [];

    /**
     * Use a constant or typed property for the media break marker
     */
    protected string $elementForNoMediaBreak = '__NO_MEDIA__';

    /**
     * Modernized property declarations with types
     */
    protected array $regexForHtmlFiles = [
        'HTML Tags' => [
            'regex' => '/\<([[:alnum:]_-]+).*(?!\/)\>/',
            'stringPlaceBefore' => '',
            'stringPlaceAfter'  => '',
        ],
        'CSS Classes' => [
            'regex' => '/\<.*class\=\"([[:alnum:]\s_-]+)\".*(?!\/)\>/',
            'stringPlaceBefore' => '.',
            'stringPlaceAfter'  => '',
        ],
        'IDs' => [
            'regex' => '/\<.*id\=\"([[:alnum:]\s_-]+)\".*(?!\/)\>/',
            'stringPlaceBefore' => '#',
            'stringPlaceAfter'  => '',
        ],
        'Data Tags (Without Values)' => [
            'regex' => '/\<.*(data-[[:alnum:]_-]+)\=\"(.*)\".*(?!\/)\>/',
            'stringPlaceBefore' => '[',
            'stringPlaceAfter'  => ']',
        ],
        'Data Tags (With Values)' => [
            'regex' => '/\<.*(data-[[:alnum:]_-]+\=\"(.*)\").*(?!\/)\>/',
            'stringPlaceBefore' => '[',
            'stringPlaceAfter'  => ']',
        ],
    ];

    /**
     * @var string[]
     */
    protected array $regexForCssFiles = [
        '/}*([\[*a-zA-Z0-9-_ \~\>\^\"\=\n\(\)\@\+\,\.\#\:\]*]+){+([^}]+)}/',
    ];

    /**
     * @inheritDoc
     */
    public function refactor(): self
    {
        $this->findAllHtmlFiles();
        $this->findAllStyleSheetFiles();
        $this->scanHtmlFilesForUsedElements();
        $this->scanCssFilesForAllElements();
        $this->filterCss();
        $this->prepareForSaving();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function saveFiles(): self
    {
        $this->createFiles();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function returnAsText(): array
    {
        return $this->readyForSave;
    }

    /**
     * Strip out the unused element
     */
    protected function filterCss(): void
    {
        foreach ($this->foundCssStructure as $file => &$fileData) {
            foreach ($fileData as $key => &$block) {
                foreach ($block as $selectors => $values) {
                    $keep = false;
                    $mergedArray = array_merge($this->whitelistArray, $this->foundUsedCssElements);

                    foreach (explode(',', (string)$selectors) as $selector) {
                        $selector = trim($selector);
                        $explodeA = explode(' ', $selector);
                        
                        // Using explode logic to find the base selector
                        $baseSelector = explode(':', $selector)[0];
                        $explodeB = explode(' ', $baseSelector);
                        
                        if (
                            (in_array(end($explodeA), $mergedArray, true)) ||
                            (in_array(end($explodeB), $mergedArray, true)) ||
                            (in_array($baseSelector, $mergedArray, true))
                        ) {
                            $keep = true;
                            break; // Optimization: stop looking if we found a match
                        }
                    }

                    if (!$keep) {
                        unset($block[$selectors]);
                    }
                }
            }
        }
    }

    /**
     * Get the source ready to be saved in files or returned
     */
    public function prepareForSaving(): void
    {
        foreach ($this->foundCssStructure as $file => $fileData) {
            $source = '';

            foreach ($fileData as $key => $block) {
                $prefix  = '';
                $postfix = '';
                $indent  = 0;

                if ($key !== $this->elementForNoMediaBreak) {
                    $prefix  = $key . " {\n";
                    $postfix = "}\n\n";
                    $indent  = 4;
                }

                if (!empty($block)) {
                    $source .= $prefix;

                    foreach ($block as $selector => $values) {
                        $values = trim((string)$values);

                        // Use modern PHP 8 string functions
                        if (!str_ends_with($values, ';')) {
                            $values .= ';';
                        }
                        
                        if (str_contains($values, '{')) {
                            $values .= '}';
                        }

                        $source .= str_pad('', $indent, ' ') . $selector . " {\n";
                        $source .= str_pad('', $indent, ' ') . "    " . $values . "\n";
                        $source .= str_pad('', $indent, ' ') . "}\n";
                    }

                    $source .= $postfix;
                }
            }

            $lastDotPos = strrpos($file, '.');
            $filenameBeforeExt = $lastDotPos !== false ? substr($file, 0, $lastDotPos) : $file;
            $filenameExt = $lastDotPos !== false ? substr($file, $lastDotPos) : '';

            if (!empty($this->appendFilename)) {
                $filenameExt = $this->appendFilename . $filenameExt;
            }

            $newFileName = $filenameBeforeExt . $filenameExt;

            $this->readyForSave[] = [
                'filename'    => $file,
                'newFilename' => $newFileName,
                'source'      => (
                    $this->minify
                        ? $this->performMinification($source)
                        : $this->getComment() . $source
                ),
            ];
        }
    }

    /**
     * Create the stripped down CSS files
     */
    protected function createFiles(): void
    {
        foreach ($this->readyForSave as $fileData) {
            $this->createFile($fileData['newFilename'], $fileData['source']);
        }
    }

    /**
     * Scan the CSS files for all main elements
     */
    protected function scanCssFilesForAllElements(): void
    {
        foreach ($this->foundCssFiles as $file) {
            if (!file_exists($file)) continue;

            $content = file_get_contents($file);
            $breaks = explode('@media', $content);

            foreach ($breaks as $loop => $break) {
                $break = trim($break);

                if ($loop === 0) {
                    $key = $this->elementForNoMediaBreak;
                    $cssSectionOfBreakArray = [$break];
                } else {
                    $firstBrace = strpos($break, '{');
                    if ($firstBrace === false) continue;

                    $key = '@media ' . substr($break, 0, $firstBrace);
                    $cssSectionOfBreakToArrayize = substr($break, $firstBrace, strrpos($break, '}') ?: null);
                    $cssSectionOfBreakArray = $this->splitBlockIntoMultiple($cssSectionOfBreakToArrayize);
                }

                foreach ($cssSectionOfBreakArray as $counter => $cssSectionOfBreak) {
                    if ($counter > 0) {
                        $key = $this->elementForNoMediaBreak;
                    }

                    foreach ($this->regexForCssFiles as $regex) {
                        preg_match_all($regex, $cssSectionOfBreak, $matches, PREG_PATTERN_ORDER);

                        if (!empty($matches[1])) {
                            foreach ($matches[1] as $regexKey => $element) {
                                $cleanElement = trim(preg_replace('/\s+/', ' ', (string)$element));
                                $cleanValue = trim(preg_replace('/\s+/', ' ', (string)$matches[2][$regexKey]));
                                $this->foundCssStructure[$file][$key][$cleanElement] = $cleanValue;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Modernized splitting logic
     *
     * @return string[]
     */
    protected function splitBlockIntoMultiple(string $string = ''): array
    {
        $totalOpen   = 0;
        $totalClosed = 0;
        $counterMark = 0;
        $blocks      = [];
        $stringSoFar = '';

        // Using mb_str_split if multi-byte characters are expected, 
        // but str_split is fine for standard CSS
        foreach (str_split($string) as $counter => $character) {
            $stringSoFar .= $character;

            if ($character === '{') {
                $totalOpen++;
            }

            if ($character === '}') {
                $totalClosed++;

                if ($totalClosed === $totalOpen && $totalOpen > 0) {
                    $blocks[$counterMark] = $stringSoFar;
                    $stringSoFar = ''; 
                    $totalOpen = 0; 
                    $totalClosed = 0;
                    $counterMark = $counter;
                }
            }
        }

        $returnBlock = ['', ''];

        foreach ($blocks as $block) {
            $trimmed = trim($block);
            if (str_starts_with($trimmed, '{')) {
                $returnBlock[0] = $block;
            } else {
                $returnBlock[1] .= $block . "\n";
            }
        }

        return array_filter($returnBlock);
    }

    /**
     * Find all matching HTML css elements
     */
    protected function scanHtmlFilesForUsedElements(): void
    {
        foreach ($this->foundHtmlFiles as $file) {
            if (!file_exists($file)) continue;

            $content = file_get_contents($file);

            foreach ($this->regexForHtmlFiles as $config) {
                preg_match_all($config['regex'], $content, $matches, PREG_PATTERN_ORDER);

                if (!empty($matches[1])) {
                    foreach ($matches[1] as $match) {
                        foreach (explode(' ', (string)$match) as $explodedMatch) {
                            $formattedMatch = $config['stringPlaceBefore'] . trim($explodedMatch) . $config['stringPlaceAfter'];

                            // Using strict in_array
                            if (!in_array($formattedMatch, $this->foundUsedCssElements, true)) {
                                $this->foundUsedCssElements[] = $formattedMatch;
                            }
                        }
                    }
                }
            }
        }
    }
}
