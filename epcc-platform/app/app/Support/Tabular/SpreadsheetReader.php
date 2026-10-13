<?php

declare(strict_types=1);

namespace App\Support\Tabular;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * A deliberately small XLSX/CSV reader.
 *
 * WHY NOT PhpSpreadsheet. It requires `ext-gd`, which this image does not have
 * and which an MTO import has no use for - we are reading a grid of text, not
 * rendering charts. Adding a C extension to the deployment so that a
 * spreadsheet library can draw images it will never draw is a bad trade on an
 * on-prem, air-gapped install where every dependency is a thing somebody has
 * to carry into the country.
 *
 * An XLSX is a ZIP of XML. The parts that matter are three:
 *   xl/workbook.xml        - sheet names and ids
 *   xl/sharedStrings.xml   - the string pool; cells reference it by index
 *   xl/worksheets/sheetN.xml - the cells
 *
 * WHAT THIS DOES NOT DO, on purpose: formulas (the cached value is read,
 * which is what Excel wrote and therefore what the engineer saw), styles,
 * merged cells, dates as dates. An MTO is text and numbers in a grid.
 *
 * THE TRAP THIS CLASS EXISTS TO AVOID: a sparse row. Excel omits empty cells
 * entirely, so the fifth `<c>` element is not necessarily column E. Cells
 * carry their reference (`r="E7"`) and it MUST be read, or a blank Size column
 * silently shifts every value one column left and the import looks plausible
 * while being wrong.
 */
final class SpreadsheetReader
{
    /**
     * Read a file into rows of strings.
     *
     * @return list<list<string>>
     */
    public function read(string $path, string $filename): array
    {
        return str_ends_with(strtolower($filename), '.csv')
            ? $this->readCsv($path)
            : $this->readXlsx($path);
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open the CSV file.');
        }

        // A BOM from Excel's "CSV UTF-8" export would otherwise become part of
        // the first header, so "Item code" never matches.
        $first = true;

        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($first) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($data[0] ?? '')) ?? '';
                $first = false;
            }

            $rows[] = array_map(static fn ($v): string => trim((string) $v), $data);
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not a readable XLSX archive.');
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheetPath = $this->firstSheetPath($zip);
            $xml = $zip->getFromName($sheetPath);

            if ($xml === false) {
                throw new RuntimeException('The workbook has no readable worksheet.');
            }

            return $this->parseSheet($xml, $shared);
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return []; // a workbook of numbers only is legal
        }

        $doc = new SimpleXMLElement($xml);
        $out = [];

        foreach ($doc->si as $si) {
            // A string may be split into several runs when part of it is
            // formatted differently. Concatenate, or "6\" PIPE" arrives as "6".
            $text = '';

            if (isset($si->t)) {
                $text .= (string) $si->t;
            }

            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }

            $out[] = $text;
        }

        return $out;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        // Sheet order in workbook.xml is the order the user sees; sheet1.xml is
        // not reliably the first tab. Resolve through the relationships so the
        // file the engineer looked at is the file we read.
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($wb !== false && $rels !== false) {
            $wbXml = new SimpleXMLElement($wb);
            $relXml = new SimpleXMLElement($rels);

            $firstSheet = $wbXml->sheets->sheet[0] ?? null;

            if ($firstSheet !== null) {
                $rid = (string) $firstSheet->attributes('r', true)->id;

                foreach ($relXml->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');

                        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private function parseSheet(string $xml, array $shared): array
    {
        $doc = new SimpleXMLElement($xml);
        $rows = [];

        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            $maxIndex = -1;

            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $index = $this->columnIndex($ref);

                $type = (string) $c['t'];

                $value = match ($type) {
                    // Shared string: the cell holds an INDEX, not the text.
                    's' => $shared[(int) $c->v] ?? '',
                    // Inline string, used by some exporters instead of the pool.
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    // A formula cell: read the cached value Excel stored, which
                    // is what the engineer actually saw on screen.
                    default => (string) ($c->v ?? ''),
                };

                $cells[$index] = trim($value);
                $maxIndex = max($maxIndex, $index);
            }

            // Fill the gaps. Excel omits empty cells, so without this the
            // columns shift left and every value lands under the wrong header.
            $out = [];

            for ($i = 0; $i <= $maxIndex; $i++) {
                $out[] = $cells[$i] ?? '';
            }

            $rows[] = $out;
        }

        return $rows;
    }

    /** Turn a cell reference such as "AB7" into a zero-based column index. */
    private function columnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);

        $letters = $m[1] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return $index - 1;
    }
}
