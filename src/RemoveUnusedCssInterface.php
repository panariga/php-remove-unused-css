<?php

declare(strict_types=1);

namespace Momentum81\PhpRemoveUnusedCss;

/**
 * Remove unused CSS from a stylesheet
 */
interface RemoveUnusedCssInterface
{
    /**
     * Refactor the CSS and remove the unused elements.
     * 
     * Uses 'static' return type to ensure method chaining points 
     * to the implementing class.
     */
    public function refactor();

    /**
     * Save the new CSS files.
     */
    public function saveFiles();

    /**
     * Instead of saving as files, return the CSS 
     * in an array of strings (per file).
     *
     * @return array<string>
     */
    public function returnAsText(): array;
}
