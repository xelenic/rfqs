<?php

namespace App;

use App\Models\JobCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Job categories from a spreadsheet's rows (Spreadsheet::rows()) —
 * Configuration → Job Categories → Import (JobCategoryController::import()).
 * Column A is the name and B the description, unless a header row names
 * them ("Name"/"Category", "Description"), in any columns. A name not on
 * the list yet, whatever its case, is added; one already on it is left as
 * it is, or given the file's description when $updateDescriptions. A row
 * that can't be — no name, too long, a name an earlier row had — is skipped
 * and said why, by its row number in the file. All or nothing.
 */
final class JobCategoryImport
{
    /**
     * The most rows a file may have.
     */
    public const MAX_ROWS = 1000;

    /**
     * The header texts that mark a row as the header, by the column they name.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADERS = [
        'name' => ['name', 'names', 'category', 'categories', 'category name', 'job category', 'job categories'],
        'description' => ['description', 'descriptions', 'details', 'desc'],
    ];

    /**
     * Categories added.
     */
    public int $added = 0;

    /**
     * Categories already on the list given the file's description.
     */
    public int $updated = 0;

    /**
     * Categories already on the list, left as they are.
     */
    public int $alreadyListed = 0;

    /**
     * The rows skipped: row number, the name it had, and why.
     *
     * @var array<int, array{row: int, name: string, reason: string}>
     */
    public array $skipped = [];

    /**
     * Imports $rows (row number => cells) as categories added by $by.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    public static function run(array $rows, User $by, bool $updateDescriptions = false): self
    {
        $import = new self;
        [$nameColumn, $descriptionColumn, $headerRow] = self::columns($rows);

        DB::transaction(function () use ($import, $rows, $nameColumn, $descriptionColumn, $headerRow, $by, $updateDescriptions) {
            $listed = JobCategory::query()->get()->keyBy(fn (JobCategory $category) => mb_strtolower($category->name));
            $seen = [];

            foreach ($rows as $number => $cells) {
                if ($number === $headerRow) {
                    continue;
                }

                $name = self::clean($cells[$nameColumn] ?? '');
                $description = $descriptionColumn === null ? '' : trim((string) ($cells[$descriptionColumn] ?? ''));
                $key = mb_strtolower($name);

                $reason = match (true) {
                    $name === '' && $description === '' => false,
                    $name === '' => 'No name',
                    mb_strlen($name) > 100 => 'Name over 100 characters',
                    $name === JobCategory::NEW_OPTION => 'Not a name a category can have',
                    mb_strlen($description) > 500 => 'Description over 500 characters',
                    isset($seen[$key]) => 'Same name as row '.$seen[$key],
                    default => null,
                };

                if ($reason === false) {
                    continue;
                }

                if ($reason !== null) {
                    $import->skipped[] = ['row' => $number, 'name' => $name, 'reason' => $reason];

                    continue;
                }

                $seen[$key] = $number;
                $category = $listed->get($key);

                if ($category === null) {
                    JobCategory::query()->create(['name' => $name, 'description' => $description !== '' ? $description : null, 'created_by' => $by->id]);
                    $import->added++;
                } elseif ($updateDescriptions && $description !== '' && $description !== $category->description) {
                    $category->update(['description' => $description]);
                    $import->updated++;
                } else {
                    $import->alreadyListed++;
                }
            }
        });

        return $import;
    }

    /**
     * Whether the file had any category in it at all.
     */
    public function foundAny(): bool
    {
        return $this->added + $this->updated + $this->alreadyListed + count($this->skipped) > 0;
    }

    /**
     * What it did, in a sentence: "3 added, 1 description updated, 2 already
     * on the list, 1 row skipped."
     */
    public function summary(): string
    {
        $parts = array_filter([
            $this->added > 0 ? $this->added.' added' : null,
            $this->updated > 0 ? $this->updated.' '.($this->updated === 1 ? 'description' : 'descriptions').' updated' : null,
            $this->alreadyListed > 0 ? $this->alreadyListed.' already on the list' : null,
            count($this->skipped) > 0 ? count($this->skipped).' '.(count($this->skipped) === 1 ? 'row' : 'rows').' skipped' : null,
        ]);

        return $parts === [] ? 'Nothing to import.' : ucfirst(implode(', ', $parts)).'.';
    }

    /**
     * The name's and description's columns, and the header row's number:
     * from the first row when it's a header naming the name's column, else
     * A and B, with no header.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array{0: int, 1: ?int, 2: ?int}
     */
    private static function columns(array $rows): array
    {
        $firstRow = array_key_first($rows);
        $headers = array_map(fn (string $cell) => mb_strtolower(self::clean($cell)), $rows[$firstRow] ?? []);
        $nameColumn = self::columnNamed($headers, 'name');

        if ($nameColumn === null) {
            return [0, 1, null];
        }

        return [$nameColumn, self::columnNamed($headers, 'description'), $firstRow];
    }

    /**
     * The column in $headers that names $field, if one does.
     *
     * @param  array<int, string>  $headers
     */
    private static function columnNamed(array $headers, string $field): ?int
    {
        foreach ($headers as $column => $header) {
            if (in_array($header, self::HEADERS[$field], true)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * $text on one line, its runs of spaces — non-breaking ones and line
     * breaks too — as one, trimmed.
     */
    private static function clean(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '');
    }
}
