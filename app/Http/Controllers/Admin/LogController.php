<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The log preview, for Admin: the application's log files (storage/logs —
 * wherever the "single" channel writes), newest entry first, by level and
 * search, each entry's stack trace or context a click away, and the file
 * itself to download. Read only. Only the end of a big file is read
 * (READ_BYTES): what's recent is what's looked for.
 */
class LogController extends Controller
{
    /**
     * The most read from the end of a file.
     */
    public const READ_BYTES = 8 * 1024 * 1024;

    public const PER_PAGE = 30;

    /**
     * The levels a log entry can have, most serious first, with the badge each
     * wears.
     *
     * @var array<string, string>
     */
    public const LEVELS = [
        'emergency' => 'badge-soft-danger',
        'alert' => 'badge-soft-danger',
        'critical' => 'badge-soft-danger',
        'error' => 'badge-soft-danger',
        'warning' => 'badge-soft-warning',
        'notice' => 'badge-soft-info',
        'info' => 'badge-soft-info',
        'debug' => 'badge-soft-secondary',
    ];

    public function index(Request $request): View
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can see the logs.');

        $files = $this->files();
        $file = array_key_exists((string) $request->query('file'), $files) ? (string) $request->query('file') : array_key_first($files);
        [$entries, $truncated] = $file !== null ? $this->entries($files[$file]['path']) : [collect(), false];

        $level = array_key_exists((string) $request->query('level'), self::LEVELS) ? (string) $request->query('level') : null;
        $search = $request->string('search')->trim()->toString();

        $shown = $entries
            ->when($level, fn (Collection $all) => $all->where('level', $level))
            ->when($search !== '', fn (Collection $all) => $all->filter(fn (array $entry) => Str::contains($entry['text'], $search, ignoreCase: true)))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('admin.logs.index', [
            'files' => $files,
            'file' => $file,
            'levelCounts' => $entries->countBy('level'),
            'total' => $entries->count(),
            'level' => $level,
            'search' => $search,
            'truncated' => $truncated,
            'entries' => new LengthAwarePaginator(
                $shown->forPage($page, self::PER_PAGE)->values(),
                $shown->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
        ]);
    }

    /**
     * One of the log files, to download whole.
     */
    public function download(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can download the logs.');

        $file = $this->files()[(string) $request->query('file')] ?? null;

        abort_if($file === null, 404);

        return response()->download($file['path']);
    }

    /**
     * Where the logs are: the folder the "single" channel writes to.
     */
    private function directory(): string
    {
        return dirname((string) config('logging.channels.single.path', storage_path('logs/laravel.log')));
    }

    /**
     * The .log files there — by name, the most recently written first. Only
     * these can be read or downloaded: a name sent in is looked up here,
     * never used as a path.
     *
     * @return array<string, array{path: string, size: int, modified: int}>
     */
    private function files(): array
    {
        return collect(glob($this->directory().'/*.log') ?: [])
            ->filter(fn (string $path) => is_file($path) && is_readable($path))
            ->mapWithKeys(fn (string $path) => [basename($path) => ['path' => $path, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)]])
            ->sortByDesc('modified')
            ->all();
    }

    /**
     * The entries in the end of $path (READ_BYTES of it), newest first — each
     * one's level, when, its first line, the rest (stack trace, context), and
     * for an email the log mailer wrote, who it was to and its subject — and
     * whether the start of the file was left unread.
     *
     * @return array{0: Collection<int, array{level: string, environment: string, at: ?CarbonImmutable, summary: string, details: string, text: string, email: ?array{to: string, subject: string}}>, 1: bool}
     */
    private function entries(string $path): array
    {
        $size = (int) filesize($path);
        $offset = max(0, $size - self::READ_BYTES);

        $handle = fopen($path, 'rb');
        fseek($handle, $offset);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        // Starting part way in: from the first whole line.
        if ($offset > 0) {
            $text = (string) substr($text, (int) strpos($text, "\n") + 1);
        }

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][0-9:.]+[^\]]*)\] ([\w.-]+)\.(\w+): /m', $text, $headers, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $entries = [];

        foreach ($headers as $index => $header) {
            $bodyStart = $header[0][1] + strlen($header[0][0]);
            $bodyEnd = $headers[$index + 1][0][1] ?? strlen($text);
            $body = rtrim(substr($text, $bodyStart, $bodyEnd - $bodyStart));
            [$summary, $details] = array_pad(explode("\n", $body, 2), 2, '');

            // The message alone up front: the context logged after it ({"exception": …}) goes with the details.
            if (preg_match('/^(.*?) (\{".*|\[\].*)$/s', $summary, $split)) {
                [$summary, $details] = [$split[1], ltrim($split[2]."\n".$details)];
            }

            $entries[] = [
                'level' => strtolower($header[3][0]),
                'environment' => $header[2][0],
                'at' => $this->when($header[1][0]),
                'summary' => $summary,
                'details' => $details,
                'text' => $body,
                'email' => $this->email($body),
            ];
        }

        return [collect(array_reverse($entries)), $offset > 0];
    }

    /**
     * When an entry was written — logged in the app's time zone, shown in
     * the working hours' own.
     */
    private function when(string $stamp): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($stamp, config('app.timezone'))->setTimezone(Setting::timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Who an email the log mailer wrote was to, and its subject — null for
     * anything else.
     *
     * @return array{to: string, subject: string}|null
     */
    private function email(string $body): ?array
    {
        if (! preg_match('/^Subject: (.+)$/m', $body, $subject) || ! preg_match('/^To: (.+)$/m', $body, $to)) {
            return null;
        }

        return ['to' => trim($to[1]), 'subject' => trim($subject[1])];
    }
}
