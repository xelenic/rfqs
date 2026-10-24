<?php

use App\JobCategoryImport;
use App\Models\JobCategory;
use App\Models\Rfq;
use App\Spreadsheet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * An upload named $name holding $contents.
 */
function categoryUpload(string $name, string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * A workbook laid out as Excel saves one: shared strings (one in rich-text
 * runs, with a phonetic guide), inline and formula strings, a header row
 * naming the columns out of order, and the categories on the first sheet —
 * which isn't sheet1.xml, its relationship pointing elsewhere by an absolute
 * path — with a second sheet that mustn't be read.
 */
function excelBuiltWorkbook(): UploadedFile
{
    $strings = [
        '<si><t>Description</t></si>',
        '<si><t>Category</t></si>',
        '<si><r><t xml:space="preserve">Fire </t></r><r><rPr><b/></rPr><t>Safety</t></r><rPh sb="0" eb="1"><t>ignored</t></rPh></si>',
        '<si><t>Alarms and sprinklers</t></si>',
        '<si><t>electrical works</t></si>',
        '<si><t>'.str_repeat('x', 101).'</t></si>',
        '<si><t>fire safety</t></si>',
        '<si><t>Only a description</t></si>',
    ];
    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="'.$main.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Categories" sheetId="2" r:id="rId3"/><sheet name="Notes" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId3" Type="worksheet" Target="/xl/worksheets/sheet2.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="'.$main.'" count="8" uniqueCount="8">'.implode('', $strings).'</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="'.$main.'"><sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>Should not be read</t></is></c></row></sheetData></worksheet>');
    $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0"?><worksheet xmlns="'.$main.'"><sheetData>'
        .'<row r="1"><c r="B1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
        .'<row r="2"><c r="B2" t="s"><v>3</v></c><c r="C2" t="s"><v>2</v></c></row>'
        .'<row r="3"><c r="B3" t="inlineStr"><is><t>New wiring description</t></is></c><c r="C3" t="s"><v>4</v></c></row>'
        .'<row r="4"><c r="B4" s="1"/><c r="C4" s="1"/></row>'
        .'<row r="5"><c r="C5" t="inlineStr"><is><t xml:space="preserve">Solar'."\u{00A0}".' Panels   </t></is></c></row>'
        .'<row r="6"><c r="C6" t="s"><v>5</v></c></row>'
        .'<row r="7"><c r="C7" t="s"><v>6</v></c></row>'
        .'<row r="8"><c r="B8" t="s"><v>7</v></c></row>'
        .'<row r="9"><c r="C9" t="str"><f>"Pest "&amp;"Control"</f><v>Pest Control</v></c></row>'
        .'</sheetData></worksheet>');
    $zip->close();

    return new UploadedFile($path, 'categories.xlsx', null, null, true);
}

it('imports the categories on an Excel workbook\'s first sheet, skipping what it can\'t add and saying why', function () {
    $admin = tap(userWithRole('Admin'))->update(['name' => 'Ada Admin']);
    JobCategory::query()->create(['name' => 'Electrical Works', 'description' => 'Wiring']);

    test()->actingAs($admin)->from(route('admin.categories.index'))
        ->post(route('admin.categories.import'), ['file' => excelBuiltWorkbook()])
        ->assertRedirect(route('admin.categories.index'));

    expect(JobCategory::query()->orderBy('name')->pluck('description', 'name')->all())->toBe([
        'Electrical Works' => 'Wiring',
        'Fire Safety' => 'Alarms and sprinklers',
        'Pest Control' => null,
        'Solar Panels' => null,
    ])->and(JobCategory::query()->where('name', 'Fire Safety')->value('created_by'))->toBe($admin->id);

    $html = test()->actingAs($admin)->get(route('admin.categories.index'))->assertOk()
        ->assertSee('Imported "categories.xlsx": 3 added, 1 already on the list, 3 rows skipped.')
        ->assertSee('Rows not imported')
        ->getContent();

    expect(Str::betweenFirst($html, 'data-skipped-row="6"', '</tr>'))->toContain('Name over 100 characters')
        ->and(Str::betweenFirst($html, 'data-skipped-row="7"', '</tr>'))->toContain('fire safety')->toContain('Same name as row 2')
        ->and(Str::betweenFirst($html, 'data-skipped-row="8"', '</tr>'))->toContain('No name')
        ->and($html)->not->toContain('data-skipped-row="4"');

    // Again, updating descriptions: only the one the file has a new one for.
    test()->actingAs($admin)->post(route('admin.categories.import'), ['file' => excelBuiltWorkbook(), 'update_descriptions' => '1'])
        ->assertRedirect(route('admin.categories.index'));

    expect(JobCategory::query()->count())->toBe(4)
        ->and(JobCategory::query()->where('name', 'Electrical Works')->value('description'))->toBe('New wiring description')
        ->and(JobCategory::query()->where('name', 'Fire Safety')->value('description'))->toBe('Alarms and sprinklers');
    test()->actingAs($admin)->get(route('admin.categories.index'))
        ->assertSee('Imported "categories.xlsx": 1 description updated, 3 already on the list, 3 rows skipped.');
});

it('imports a CSV — semicolons, a byte order mark, Excel\'s older Windows encoding — with no header', function () {
    $admin = userWithRole('Admin');
    $csv = "\xEF\xBB\xBFCaf\xE9 Supplies;Coffee machines and beans\n;\nLandscaping;\n";

    test()->actingAs($admin)->post(route('admin.categories.import'), ['file' => categoryUpload('categories.csv', $csv)])
        ->assertRedirect(route('admin.categories.index'));

    expect(JobCategory::query()->orderBy('name')->pluck('description', 'name')->all())->toBe([
        'Café Supplies' => 'Coffee machines and beans',
        'Landscaping' => null,
    ]);
});

it('refuses a file it can\'t import, back in the Import popup saying why', function (Closure $file, string $message) {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->from(route('admin.categories.index'))
        ->post(route('admin.categories.import'), array_filter(['file' => $file()]))
        ->assertRedirect(route('admin.categories.index'));

    test()->actingAs($admin)->get(route('admin.categories.index'))->assertOk()
        ->assertSee($message)
        ->assertSee('getElementById(\'importCategoriesModal\')).show()', false);

    expect(JobCategory::query()->count())->toBe(0);
})->with([
    'no file' => [fn () => null, 'Choose the file to import.'],
    'an old .xls' => [fn () => categoryUpload('categories.xls', 'binary'), 'An older .xls? Open it in Excel and save it as .xlsx.'],
    'not a workbook' => [fn () => categoryUpload('categories.xlsx', 'Electrical Works'), 'That isn\'t an Excel workbook (.xlsx)'],
    'only a header' => [fn () => categoryUpload('categories.csv', "Name,Description\n"), 'There\'s no category in that file'],
    'too many rows' => [fn () => categoryUpload('categories.csv', implode("\n", array_map(fn (int $row) => "Category {$row}", range(1, JobCategoryImport::MAX_ROWS + 1)))), 'It has more than 1,000 rows'],
]);

it('downloads the list as an Excel template, for those who manage the categories only', function () {
    JobCategory::query()->create(['name' => 'Plumbing & Sanitary', 'description' => 'Pipes <and> pumps']);
    JobCategory::query()->create(['name' => 'Electrical Works']);
    $operations = userWithRole('Senior Operations');

    test()->actingAs($operations)->get(route('admin.categories.index'))->assertOk()
        ->assertSee('action="'.route('admin.categories.import').'"', false)
        ->assertSee('href="'.route('admin.categories.template').'"', false);

    $response = test()->actingAs($operations)->get(route('admin.categories.template'))->assertOk()
        ->assertDownload('job-categories.xlsx');

    expect(Spreadsheet::rows($response->baseResponse->getFile()->getPathname(), 'xlsx', 10))->toBe([
        1 => ['Name', 'Description'],
        2 => ['Electrical Works', ''],
        3 => ['Plumbing & Sanitary', 'Pipes <and> pumps'],
    ]);

    $sourcing = userWithRole('Sourcing');
    test()->actingAs($sourcing)->get(route('admin.categories.template'))->assertForbidden();
    test()->actingAs($sourcing)->post(route('admin.categories.import'), ['file' => excelBuiltWorkbook()])->assertForbidden();
    expect(JobCategory::query()->count())->toBe(2);
});
