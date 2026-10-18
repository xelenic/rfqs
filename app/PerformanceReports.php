<?php

namespace App;

use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind Admin's performance reports (Admin\PerformanceReportController):
 * Sourcing's daily report and monthly evaluation, Data Entry's daily report.
 * Every figure is worked out from the records the workflow keeps, so it
 * matches the RFQs as they stand:
 *
 * - An RFQ counts once per person however many of its parts they had, and
 *   once in a total however many people had it.
 * - Its items are the RFQ's number of items (rfqs.number_of_items).
 * - A part was "received" by a Sourcing member when their first stretch on it
 *   started (rfq_steps), or — with none — when it was assigned.
 * - Finalized is the member's Finalize (rfq_user.finalized_at, finalized_by),
 *   which is when their quotation is complete. Data Entry's completion is
 *   their Send to Finalize (data_entry_completed_at, data_entry_completed_by).
 * - Pending is a part its member had received, and hadn't finalized, at the
 *   end of the day; one waiting for the customer's clarification — its hold
 *   said so (Rfq::changeStatus()) — is counted apart, and a cancelled one not
 *   at all. Holds are read back from the thread, so a past day is as it was.
 * - Times are working time (Setting::workingSecondsBetween(), in the person's
 *   own hours), and days are days in Setting::timezone().
 */
class PerformanceReports
{
    /**
     * The most days a daily report covers at once.
     */
    public const MAX_DAYS = 93;

    /**
     * How the person reports' rows can be ordered.
     *
     * @var array<string, string>
     */
    public const SORTS = ['name' => 'Name', 'date' => 'Date', 'rfqs' => 'RFQ count', 'items' => 'Item count'];

    /**
     * The thread actions that stop or restart an RFQ or a part.
     *
     * @var array<int, string>
     */
    private const STOP_ACTIONS = ['put_on_hold', 'cancelled', 'resumed', 'reopened'];

    /**
     * Everyone who holds $role — deactivated accounts too, for the work they
     * did — by name.
     *
     * @return Collection<int, User>
     */
    public static function people(string $role): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($roles) => $roles->where('name', $role))
            ->orderBy('name')
            ->get();
    }

    /**
     * The days from $from to $to ("Y-m-d"), no later than today.
     *
     * @return array<int, string>
     */
    public static function daysBetween(string $from, string $to): array
    {
        $today = Setting::today();
        $days = [];

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= min($to, $today); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * Sourcing's daily report: for each of $people on each day from $from to
     * $to that was a working day for them (or they finalized something on),
     * the RFQs they finalized and their items, and — at the end of the day —
     * the ones still pending with them and their items, and the ones waiting
     * for the customer's clarification. With each person's figures across the
     * whole range (pending and waiting as at its last day), and the total.
     *
     * @param  Collection<int, User>  $people
     * @return array{rows: Collection<int, array<string, mixed>>, people: Collection<int, array<string, mixed>>, total: array<string, mixed>, asOf: string}
     */
    public function sourcingDaily(Collection $people, string $from, string $to): array
    {
        $ids = $people->pluck('id')->all();
        $parts = $this->parts(fn ($query) => $query->where(fn ($q) => $q->whereIn('rfq_user.user_id', $ids)->orWhereIn('rfq_user.finalized_by', $ids)));
        $receipts = $this->receipts($ids, $parts);
        $events = $this->stopEvents($parts->pluck('rfq_id')->unique()->all());
        $items = $parts->pluck('items', 'rfq_id');
        $days = self::daysBetween($from, $to);
        $asOf = end($days) ?: $to;

        $finalizedBy = fn (User $person, CarbonInterface $start, CarbonInterface $end) => $parts
            ->filter(fn (object $part) => $part->finalized_at !== null
                && (int) ($part->finalized_by ?? $part->user_id) === $person->id
                && $part->finalized_at->between($start, $end))
            ->pluck('rfq_id')->unique()->values();

        $openWith = function (User $person, CarbonInterface $at) use ($parts, $receipts, $events): array {
            $open = ['pending' => collect(), 'customer' => collect()];

            foreach ($parts->where('user_id', $person->id) as $part) {
                $received = $receipts->get("{$part->rfq_id}|{$part->part_number}|{$person->id}")['at'] ?? $part->assigned_at;

                if ($received > $at || ($part->finalized_at !== null && $part->finalized_at <= $at)) {
                    continue;
                }

                match ($this->stateAt($events[$part->rfq_id] ?? [], $part->part_number, $at)) {
                    'cancelled' => null,
                    'customer' => $open['customer']->push($part->rfq_id),
                    default => $open['pending']->push($part->rfq_id),
                };
            }

            return ['pending' => $open['pending']->unique()->values(), 'customer' => $open['customer']->unique()->values()];
        };

        $figures = fn (Collection $finalized, array $open) => [
            'finalized' => $finalized->count(),
            'finalized_items' => $this->itemsOf($finalized, $items),
            'pending' => $open['pending']->count(),
            'pending_items' => $this->itemsOf($open['pending'], $items),
            'customer' => $open['customer']->count(),
            'ids' => ['finalized' => $finalized, 'pending' => $open['pending'], 'customer' => $open['customer']],
        ];

        $rows = collect();
        $summaries = collect();

        foreach ($people as $person) {
            foreach ($days as $day) {
                [$start, $end] = $this->dayBounds($day);
                $finalized = $finalizedBy($person, $start, $end);

                if ($finalized->isEmpty() && ! $this->isWorkingDay($day, $person)) {
                    continue;
                }

                $rows->push(['user' => $person, 'date' => $day] + $figures($finalized, $openWith($person, min($end, now()))));
            }

            [$rangeStart] = $this->dayBounds($from);
            [, $rangeEnd] = $this->dayBounds($asOf);
            $summaries->push(['user' => $person] + $figures($finalizedBy($person, $rangeStart, $rangeEnd), $openWith($person, min($rangeEnd, now()))));
        }

        $union = fn (string $key) => $summaries->flatMap(fn (array $summary) => $summary['ids'][$key])->unique()->values();

        return [
            'rows' => $rows,
            'people' => $summaries,
            'total' => $figures($union('finalized'), ['pending' => $union('pending'), 'customer' => $union('customer')]),
            'asOf' => $asOf,
        ];
    }

    /**
     * Data Entry's daily report: for each of $people on each day from $from
     * to $to that was a working day for them (or they sent something to
     * finalize on), the RFQs they sent to finalize, those RFQs' items, the
     * line items they added, and their working time on Data Entry that day.
     * With each person's figures across the range, and the total.
     *
     * @param  Collection<int, User>  $people
     * @return array{rows: Collection<int, array<string, mixed>>, people: Collection<int, array<string, mixed>>, total: array<string, mixed>}
     */
    public function dataEntryDaily(Collection $people, string $from, string $to): array
    {
        $ids = $people->pluck('id')->all();
        $parts = $this->parts(fn ($query) => $query->whereIn('rfq_user.data_entry_completed_by', $ids));
        $items = $parts->pluck('items', 'rfq_id');
        $stretches = RfqStep::query()->where('step', 'data_entry')->whereIn('worked_by', $ids)->get();
        $days = self::daysBetween($from, $to);

        $figures = function (User $person, CarbonInterface $start, CarbonInterface $end) use ($parts, $items, $stretches): array {
            $completed = $parts->filter(fn (object $part) => (int) $part->data_entry_completed_by === $person->id
                && $part->data_entry_completed_at !== null
                && $part->data_entry_completed_at->between($start, $end));

            $seconds = $stretches->where('worked_by', $person->id)->sum(function (RfqStep $stretch) use ($start, $end, $person): int {
                $from = max($stretch->started_at, $start);
                $to = min($stretch->ended_at ?? now(), $end);

                return $from < $to ? Setting::workingSecondsBetween($from, $to, $person->id) : 0;
            });

            return [
                'completed' => $completed->pluck('rfq_id')->unique()->count(),
                'items' => $this->itemsOf($completed->pluck('rfq_id'), $items),
                'line_items' => (int) $completed->sum('data_entry_entries'),
                'seconds' => (int) $seconds,
                'ids' => $completed->pluck('rfq_id')->unique()->values(),
            ];
        };

        $rows = collect();
        $summaries = collect();

        foreach ($people as $person) {
            foreach ($days as $day) {
                [$start, $end] = $this->dayBounds($day);
                $ofTheDay = $figures($person, $start, $end);

                if ($ofTheDay['completed'] === 0 && $ofTheDay['seconds'] === 0 && ! $this->isWorkingDay($day, $person)) {
                    continue;
                }

                $rows->push(['user' => $person, 'date' => $day] + $ofTheDay);
            }

            [$rangeStart] = $this->dayBounds($from);
            [, $rangeEnd] = $this->dayBounds(end($days) ?: $to);
            $summaries->push(['user' => $person] + $figures($person, $rangeStart, $rangeEnd));
        }

        $completed = $summaries->flatMap(fn (array $summary) => $summary['ids'])->unique()->values();

        return [
            'rows' => $rows,
            'people' => $summaries,
            'total' => [
                'completed' => $completed->count(),
                'items' => $this->itemsOf($completed, $items),
                'line_items' => (int) $summaries->sum('line_items'),
                'seconds' => (int) $summaries->sum('seconds'),
            ],
        ];
    }

    /**
     * Sourcing's monthly evaluation for $month ("Y-m"): for each of $people,
     * the RFQs they received in it and those RFQs' items, the quotations they
     * completed (finalized) in it, and the average working time from
     * receiving a part to finalizing it. With the total.
     *
     * @param  Collection<int, User>  $people
     * @return array{rows: Collection<int, array<string, mixed>>, total: array<string, mixed>}
     */
    public function sourcingMonthly(Collection $people, string $month): array
    {
        $ids = $people->pluck('id')->all();
        $parts = $this->parts(fn ($query) => $query->where(fn ($q) => $q->whereIn('rfq_user.user_id', $ids)->orWhereIn('rfq_user.finalized_by', $ids)));
        $receipts = $this->receipts($ids, $parts);
        $items = $parts->pluck('items', 'rfq_id');
        $start = CarbonImmutable::parse("{$month}-01", Setting::timezone())->startOfMonth();
        $end = $start->endOfMonth();

        $rows = $people->map(function (User $person) use ($parts, $receipts, $items, $start, $end): array {
            $received = $receipts
                ->filter(fn (array $receipt) => $receipt['user_id'] === $person->id && $receipt['at']->between($start, $end))
                ->pluck('rfq_id')->unique()->values();

            $finalized = $parts->filter(fn (object $part) => $part->finalized_at !== null
                && (int) ($part->finalized_by ?? $part->user_id) === $person->id
                && $part->finalized_at->between($start, $end));

            $durations = $finalized->map(fn (object $part) => Setting::workingSecondsBetween(
                min($receipts->get("{$part->rfq_id}|{$part->part_number}|{$person->id}")['at'] ?? $part->assigned_at, $part->finalized_at),
                $part->finalized_at,
                $person->id,
            ));

            return [
                'user' => $person,
                'received' => $received->count(),
                'completed' => $finalized->pluck('rfq_id')->unique()->count(),
                'average' => $durations->isEmpty() ? null : (int) round($durations->avg()),
                'items' => $this->itemsOf($received, $items),
                'durations' => $durations,
                'ids' => ['received' => $received, 'completed' => $finalized->pluck('rfq_id')->unique()->values()],
            ];
        })->values();

        $received = $rows->flatMap(fn (array $row) => $row['ids']['received'])->unique()->values();
        $durations = $rows->flatMap(fn (array $row) => $row['durations']);

        return [
            'rows' => $rows,
            'total' => [
                'received' => $received->count(),
                'completed' => $rows->flatMap(fn (array $row) => $row['ids']['completed'])->unique()->count(),
                'average' => $durations->isEmpty() ? null : (int) round($durations->avg()),
                'items' => $this->itemsOf($received, $items),
            ],
        ];
    }

    /**
     * Of the RFQs given, the ones waiting for the customer's clarification
     * now — the RFQ, or one of its parts, on a hold that said so.
     *
     * @param  array<int, int>  $rfqIds
     * @return array<int, bool> RFQ id => whether it's waiting
     */
    public function waitingOnCustomer(array $rfqIds): array
    {
        $events = $this->stopEvents($rfqIds);
        $parts = DB::table('rfq_user')->whereIn('rfq_id', $rfqIds)->get(['rfq_id', 'part_number'])->groupBy('rfq_id');
        $now = now();

        return collect($rfqIds)->mapWithKeys(function (int $id) use ($events, $parts, $now) {
            // An RFQ with no parts yet can only be held as a whole.
            $partNumbers = collect($parts->get($id))->map(fn (object $part) => (int) $part->part_number)->whenEmpty(fn () => collect([0]));

            return [$id => $partNumbers->contains(fn (int $part) => $this->stateAt($events[$id] ?? [], $part, $now) === 'customer')];
        })->all();
    }

    /**
     * Orders a person report's rows: by name then date, by date then name,
     * or by their RFQ or item count ($counts: the keys they're read from),
     * then name and date. Ascending, or descending with $descending.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{rfqs: string, items: string}  $counts
     * @return Collection<int, array<string, mixed>>
     */
    public static function sort(Collection $rows, string $sort, bool $descending, array $counts): Collection
    {
        $byName = fn (array $row) => mb_strtolower($row['user']->name);
        $byDate = fn (array $row) => $row['date'] ?? '';

        $keys = match ($sort) {
            'date' => [$byDate, $byName],
            'rfqs' => [fn (array $row) => $row[$counts['rfqs']], $byName, $byDate],
            'items' => [fn (array $row) => $row[$counts['items']], $byName, $byDate],
            default => [$byName, $byDate],
        };

        return $rows->sort(function (array $one, array $other) use ($keys, $descending): int {
            foreach ($keys as $index => $key) {
                $order = $key($one) <=> $key($other);

                if ($order !== 0) {
                    // Only the chosen order turns round; the tie-breakers stay as they are.
                    return $descending && $index === 0 ? -$order : $order;
                }
            }

            return 0;
        })->values();
    }

    /**
     * Every part the query leaves, with its RFQ's items, its times as dates.
     *
     * @param  callable(Builder): mixed  $scope
     * @return Collection<int, object>
     */
    private function parts(callable $scope): Collection
    {
        $query = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->select([
                'rfq_user.rfq_id', 'rfq_user.part_number', 'rfq_user.user_id', 'rfq_user.created_at as assigned_at',
                'rfq_user.finalized_at', 'rfq_user.finalized_by',
                'rfq_user.data_entry_completed_at', 'rfq_user.data_entry_completed_by', 'rfq_user.data_entry_entries',
                'rfqs.number_of_items as items',
            ]);
        $scope($query);

        return $query->get()->map(function (object $part) {
            foreach (['assigned_at', 'finalized_at', 'data_entry_completed_at'] as $column) {
                $part->{$column} = $part->{$column} !== null ? CarbonImmutable::parse($part->{$column}, config('app.timezone')) : null;
            }
            $part->part_number = (int) $part->part_number;

            return $part;
        });
    }

    /**
     * When each of $ids first had each part — their first stretch at
     * Sourcing's steps on it — keyed "rfq|part|user", for the parts given
     * and any they had before it was given to someone else. A part they hold
     * without a stretch yet counts from when it was assigned.
     *
     * @param  array<int, int>  $ids
     * @param  Collection<int, object>  $parts
     * @return Collection<string, array{rfq_id: int, part: int, user_id: int, at: CarbonImmutable}>
     */
    private function receipts(array $ids, Collection $parts): Collection
    {
        $receipts = DB::table('rfq_steps')
            ->whereIn('step', RfqStep::ROLE_STEPS['Sourcing'])
            ->whereIn('assignee_id', $ids)
            ->groupBy('rfq_id', 'part_number', 'assignee_id')
            ->selectRaw('rfq_id, part_number, assignee_id, min(started_at) as received_at')
            ->get()
            ->mapWithKeys(fn (object $step) => ["{$step->rfq_id}|{$step->part_number}|{$step->assignee_id}" => [
                'rfq_id' => (int) $step->rfq_id,
                'part' => (int) $step->part_number,
                'user_id' => (int) $step->assignee_id,
                'at' => CarbonImmutable::parse($step->received_at, config('app.timezone')),
            ]]);

        foreach ($parts->whereIn('user_id', $ids) as $part) {
            $receipts->put("{$part->rfq_id}|{$part->part_number}|{$part->user_id}", $receipts->get("{$part->rfq_id}|{$part->part_number}|{$part->user_id}") ?? [
                'rfq_id' => (int) $part->rfq_id,
                'part' => $part->part_number,
                'user_id' => (int) $part->user_id,
                'at' => $part->assigned_at,
            ]);
        }

        return $receipts;
    }

    /**
     * Each RFQ's holds, cancellations and restarts, oldest first, from its
     * thread: when, which part (null: the whole RFQ), and what it left it —
     * 'hold', 'customer' (a hold waiting for the customer's clarification),
     * 'cancelled' or 'running'.
     *
     * @param  array<int, int>  $rfqIds
     * @return array<int, array<int, array{at: CarbonImmutable, part: ?int, state: string}>>
     */
    private function stopEvents(array $rfqIds): array
    {
        $events = [];

        DB::table('rfq_comments')
            ->whereIn('rfq_id', $rfqIds)
            ->whereIn('action', self::STOP_ACTIONS)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['rfq_id', 'action', 'meta', 'created_at'])
            ->each(function (object $comment) use (&$events) {
                $meta = json_decode((string) $comment->meta, true) ?: [];

                $events[$comment->rfq_id][] = [
                    'at' => CarbonImmutable::parse($comment->created_at, config('app.timezone')),
                    'part' => isset($meta['part']) ? (int) $meta['part'] : null,
                    'state' => match ($comment->action) {
                        'put_on_hold' => empty($meta['customer_clarification']) ? 'hold' : 'customer',
                        'cancelled' => 'cancelled',
                        default => 'running',
                    },
                ];
            });

        return $events;
    }

    /**
     * Where $part stood at $at by $events (stopEvents()): cancelled if it or
     * its RFQ was, else waiting for the customer if either's hold said so,
     * else on hold, else running.
     *
     * @param  array<int, array{at: CarbonImmutable, part: ?int, state: string}>  $events
     */
    private function stateAt(array $events, int $part, CarbonInterface $at): string
    {
        $whole = 'running';
        $own = 'running';

        foreach ($events as $event) {
            if ($event['at'] > $at) {
                break;
            }

            if ($event['part'] === null) {
                $whole = $event['state'];
            } elseif ($event['part'] === $part) {
                $own = $event['state'];
            }
        }

        foreach (['cancelled', 'customer', 'hold'] as $state) {
            if ($whole === $state || $own === $state) {
                return $state;
            }
        }

        return 'running';
    }

    /**
     * The items of the RFQs given, each once.
     *
     * @param  Collection<int, int>  $rfqIds
     * @param  Collection<int, int|null>  $items  RFQ id => its items
     */
    private function itemsOf(Collection $rfqIds, Collection $items): int
    {
        return (int) $rfqIds->unique()->sum(fn (int $id) => (int) ($items[$id] ?? 0));
    }

    /**
     * The start and end of $day ("Y-m-d") in the working hours' time zone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayBounds(string $day): array
    {
        $start = CarbonImmutable::parse($day, Setting::timezone())->startOfDay();

        return [$start, $start->endOfDay()];
    }

    /**
     * Whether $day is one of $person's working days (Setting::weekOn()).
     */
    private function isWorkingDay(string $day, User $person): bool
    {
        return (bool) (Setting::weekOn($day, $person->id)[strtolower(CarbonImmutable::parse($day)->format('l'))]['working'] ?? false);
    }
}
