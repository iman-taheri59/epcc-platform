<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Translation\Translator;
use Morilog\Jalali\CalendarUtils;

/**
 * Substitutes Persian digits into translated strings that carry numbers.
 *
 * THE DEFECT THIS FIXES. Every admin table was rendering two digit systems at
 * once: our own columns went through JalaliDate::digits() and printed ۵ / ۴۰٪,
 * while Filament's own chrome - the pagination summary, the active-filter
 * count, the per-page selector - printed "نمایش 1 به 5 از 5 نتیجه" in Latin.
 * Persian body text with Latin numerals mid-sentence is the kind of thing a
 * reader notices immediately and a test never does. It affected all five
 * resources, not just the one being built when it was spotted.
 *
 * WHY HERE. The alternative was to chase every label in every table, which
 * leaves the next table to get it wrong again, and does nothing for strings
 * that live inside the Filament package. Laravel funnels every :parameter
 * substitution through makeReplacements(), so that is the one place where a
 * number is known to be a number and the locale is known to be Persian.
 *
 * WHY ONLY WHOLLY-NUMERIC VALUES. Converting the rendered line would corrupt
 * identifiers: PH-14-SS-2201 would come out as PH-۱۴-SS-۲۲۰۱, and equipment
 * tags are read off drawings and typed into search boxes in Latin. A value is
 * converted only when it is entirely digits, so counts and totals change and
 * document numbers, tags and dates-in-codes do not.
 */
class LocalisedNumberTranslator extends Translator
{
    /**
     * Signature is untyped on $line to match Illuminate\Translation\Translator,
     * which declares makeReplacements($line, array $replace) without a type.
     *
     * @param  string  $line
     * @param  array<string, mixed>  $replace
     */
    protected function makeReplacements($line, array $replace)
    {
        if ($this->locale === 'fa') {
            foreach ($replace as $key => $value) {
                if (is_int($value) || (is_string($value) && $value !== '' && ctype_digit($value))) {
                    $replace[$key] = CalendarUtils::convertNumbers((string) $value);
                }
            }
        }

        return parent::makeReplacements($line, $replace);
    }
}
