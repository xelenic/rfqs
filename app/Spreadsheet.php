<?php

namespace App;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the rows of a spreadsheet someone uploads — an Excel workbook
 * (.xlsx, its first sheet) or a CSV — and writes a plain one-sheet .xlsx to
 * download, with PHP's own zip and XML (an .xlsx is a zip of XML parts), so
 * the app needs no spreadsheet package. Values are read as Excel shows them
 * as text: no formulas worked out, no number formats or dates applied — what
 * a list of names needs. Used by JobCategoryImport.
 */
final class Spreadsheet
{
    /**
     * The file types read.
     *
     * @var array<int, string>
     */
    public const EXTENSIONS = ['xlsx', 'csv'];

    /**
     * The most an XML part of a workbook may unzip to — beyond it the file
     * is refused rather than read into memory.
     */
    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    private const MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * The rows of the file at $path, read as $extension (one of EXTENSIONS):
     * row number (as Excel numbers them) => its cells' text, column A first,
     * blank cells as ''. Rows with nothing in them are left out. Throws a
     * RuntimeException, saying why for the person who sent it, when it
     * can't be read, or has more than $maxRows rows with something in them.
     *
     * @return array<int, array<int, string>>
     */
    public static function rows(string $path, string $extension, int $maxRows): array
    {
        return match (strtolower($extension)) {
            'xlsx' => self::xlsxRows($path, $maxRows),
            'csv' => self::csvRows($path, $maxRows),
            default => throw new RuntimeException('Only Excel workbooks (.xlsx) and CSV files can be read.'),
        };
    }

    /**
     * A one-sheet .xlsx named $sheetName holding $rows (each a list of cell
     * texts), the first row in bold, written to a temporary file whose path
     * is returned — for the caller to send and delete. $widths are the
     * columns' widths, in characters.
     *
     * @param  array<int, array<int, string|null>>  $rows
     * @param  array<int, int>  $widths
     */
    public static function xlsx(array $rows, string $sheetName, array $widths = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not write the spreadsheet.');
        }

        $sheetData = '';

        foreach (array_values($rows) as $index => $cells) {
            $number = $index + 1;
            $sheetData .= '<row r="'.$number.'">';

            foreach (array_values($cells) as $column => $text) {
                $sheetData .= '<c r="'.self::columnLetters($column).$number.'" t="inlineStr"'.($index === 0 ? ' s="1"' : '').'>'
                    .'<is><t xml:space="preserve">'.self::xmlText((string) $text).'</t></is></c>';
            }

            $sheetData .= '</row>';
        }

        $cols = '';

        foreach (array_values($widths) as $column => $width) {
            $cols .= '<col min="'.($column + 1).'" max="'.($column + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        $declaration = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

        $zip->addFromString('[Content_Types].xml', $declaration
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', $declaration
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $declaration
            .'<workbook xmlns="'.self::MAIN_NAMESPACE.'" xmlns:r="'.self::RELATIONSHIPS_NAMESPACE.'">'
            .'<sheets><sheet name="'.self::xmlText(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $declaration
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $declaration
            .'<styleSheet xmlns="'.self::MAIN_NAMESPACE.'">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $declaration
            .'<worksheet xmlns="'.self::MAIN_NAMESPACE.'">'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.$sheetData.'</sheetData>'
            .'</worksheet>');
        $zip->close();

        return $path;
    }

    /**
     * The first sheet's rows: its cells' shared strings, inline strings and
     * values as text.
     *
     * @return array<int, array<int, string>>
     */
    private static function xlsxRows(string $path, int $maxRows): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That isn\'t an Excel workbook (.xlsx) — open it in Excel and save it as one, or as a CSV.');
        }

        try {
            $strings = $zip->locateName('xl/sharedStrings.xml') !== false
                ? array_map(fn (SimpleXMLElement $item) => self::richText($item), iterator_to_array(self::part($zip, 'xl/sharedStrings.xml')->si, false))
                : [];
            $sheet = self::part($zip, self::firstSheetPath($zip));
        } finally {
            $zip->close();
        }

        $rows = [];
        $previousRow = 0;

        foreach ($sheet->sheetData->row as $row) {
            $number = (int) $row['r'] ?: $previousRow + 1;
            $previousRow = $number;
            $cells = [];
            $previousColumn = -1;

            foreach ($row->c as $cell) {
                $column = isset($cell['r']) ? self::columnIndex((string) $cell['r']) : $previousColumn + 1;
                $previousColumn = $column;

                $cells[$column] = match ((string) $cell['t']) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => self::richText($cell->is),
                    'b' => (string) $cell->v === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $cell->v,
                };
            }

            if ($cells === [] || implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            if (count($rows) === $maxRows) {
                throw new RuntimeException('It has more than '.number_format($maxRows).' rows — split it into smaller files.');
            }

            $rows[$number] = array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
        }

        return $rows;
    }

    /**
     * Where the workbook's first sheet is: the target of the first <sheet>'s
     * relationship, or sheet1.xml where that can't be followed.
     */
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';

        if ($zip->locateName('xl/workbook.xml') === false || $zip->locateName('xl/_rels/workbook.xml.rels') === false) {
            return $fallback;
        }

        $first = self::part($zip, 'xl/workbook.xml')->sheets->sheet[0] ?? null;
        $id = $first ? (string) $first->attributes(self::RELATIONSHIPS_NAMESPACE)['id'] : '';

        foreach (self::part($zip, 'xl/_rels/workbook.xml.rels')->Relationship as $relationship) {
            if ((string) $relationship['Id'] === $id) {
                $target = (string) $relationship['Target'];

                return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
        }

        return $fallback;
    }

    /**
     * The workbook's XML part $name, read without fetching anything it
     * points to; refused when missing, too big or not XML.
     */
    private static function part(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            throw new RuntimeException('That workbook has no sheet to read — open it in Excel and save it again.');
        }

        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new RuntimeException('That workbook is too big to read — split it into smaller files.');
        }

        $xml = $zip->getFromName($name);
        $previous = libxml_use_internal_errors(true);
        $element = $xml === false ? false : simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false) {
            throw new RuntimeException('That workbook couldn\'t be read — open it in Excel and save it again.');
        }

        return $element;
    }

    /**
     * A string item's text: its <t>, or its runs' <t>s one after another —
     * the phonetic guides (<rPh>) left out.
     */
    private static function richText(?SimpleXMLElement $item): string
    {
        if ($item === null) {
            return '';
        }

        if (isset($item->t)) {
            return (string) $item->t;
        }

        $text = '';

        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * The CSV's records, by line — commas, semicolons or tabs between the
     * cells, whichever its first line has most of (Excel saves with
     * semicolons in some regions) — as UTF-8, a byte order mark dropped, and
     * text in Excel's older Windows encoding converted.
     *
     * @return array<int, array<int, string>>
     */
    private static function csvRows(string $path, int $maxRows): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('That file couldn\'t be read.');
        }

        $firstLine = (string) fgets($handle);
        rewind($handle);
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate) => substr_count($firstLine, $candidate))->first();

        $rows = [];
        $number = 0;

        try {
            while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;
                $cells = array_map(function (?string $cell) use ($number) {
                    $cell = (string) $cell;

                    if ($number === 1) {
                        $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell);
                    }

                    return mb_check_encoding($cell, 'UTF-8') ? $cell : mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
                }, $record);

                if (implode('', array_map('trim', $cells)) === '') {
                    continue;
                }

                if (count($rows) === $maxRows) {
                    throw new RuntimeException('It has more than '.number_format($maxRows).' rows — split it into smaller files.');
                }

                $rows[$number] = $cells;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * The 0-based column of a cell reference: A1 → 0, B7 → 1, AA3 → 26.
     */
    private static function columnIndex(string $reference): int
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $reference));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max($index - 1, 0);
    }

    /**
     * The letters of 0-based column $index: 0 → A, 26 → AA.
     */
    private static function columnLetters(int $index): string
    {
        $letters = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(65 + ($index - 1) % 26).$letters;
        }

        return $letters;
    }

    /**
     * $text escaped for XML, with the control characters XML can't hold
     * taken out.
     */
    private static function xmlText(string $text): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
