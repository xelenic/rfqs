{{--
    The log preview (Admin\LogController): one of the application's log files,
    newest entry first — its level, when (in the working hours' time zone),
    its first line, and the rest of it (stack trace, context) a click away.
    By level and search; the file whole to download. Read only.

    Expects: $files, $file, $levelCounts, $total, $level, $search,
    $truncated, $entries (paginated).
--}}
@extends('layouts.app')

@section('title', 'Logs')

@section('content')
    @php
        $query = fn (array $change) => route('admin.logs.index', array_filter(array_merge(['file' => $file, 'level' => $level, 'search' => $search !== '' ? $search : null], $change), fn ($value) => $value !== null));
        $sizeLabel = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format(max(1, $bytes) / 1024, 0).' KB';
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                @if (count($files) > 1)
                    <select name="file" class="form-select form-select-sm w-auto" aria-label="Log file" onchange="this.form.submit()">
                        @foreach ($files as $name => $info)
                            <option value="{{ $name }}" @selected($name === $file)>{{ $name }} · {{ $sizeLabel($info['size']) }}</option>
                        @endforeach
                    </select>
                @elseif ($file)
                    <input type="hidden" name="file" value="{{ $file }}">
                @endif
                @if ($level)
                    <input type="hidden" name="level" value="{{ $level }}">
                @endif
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search the log…" style="min-width:240px;">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </form>

            @if ($file)
                <a href="{{ route('admin.logs.download', ['file' => $file]) }}" class="btn btn-sm btn-outline-secondary" download>
                    <i class="bi bi-download"></i> Download {{ $file }}
                </a>
            @endif
        </div>

        @if ($file)
            <div class="log-levels">
                <a href="{{ $query(['level' => null, 'page' => null]) }}" @class(['log-level-chip', 'is-active' => $level === null])>
                    All <span class="rfq-tab-count">{{ $total }}</span>
                </a>
                @foreach (\App\Http\Controllers\Admin\LogController::LEVELS as $name => $badge)
                    @if ($levelCounts->has($name))
                        <a href="{{ $query(['level' => $name, 'page' => null]) }}" @class(['log-level-chip', 'is-active' => $level === $name])>
                            <span class="badge {{ $badge }}">{{ ucfirst($name) }}</span> <span class="rfq-tab-count">{{ $levelCounts[$name] }}</span>
                        </a>
                    @endif
                @endforeach
                @if ($truncated)
                    <span class="text-muted-soft small ms-auto">
                        <i class="bi bi-info-circle"></i> The last {{ $sizeLabel(\App\Http\Controllers\Admin\LogController::READ_BYTES) }} of {{ $sizeLabel($files[$file]['size']) }} — download it for the rest.
                    </span>
                @endif
            </div>
        @endif

        <div class="table-responsive">
            <table class="table mb-0 log-table">
                <thead>
                    <tr>
                        <th>Level</th>
                        <th>When</th>
                        <th>Message</th>
                        <th class="text-end"><span class="visually-hidden">Details</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $index => $entry)
                        @php $detailsId = 'log-entry-'.$entries->firstItem().'-'.$index; @endphp
                        <tr>
                            <td><span class="badge {{ \App\Http\Controllers\Admin\LogController::LEVELS[$entry['level']] ?? 'badge-soft-secondary' }}">{{ ucfirst($entry['level']) }}</span></td>
                            <td class="text-nowrap text-muted-soft small">
                                @if ($entry['at'])
                                    <span title="{{ $entry['at']->format('Y-m-d H:i:s T') }}">{{ $entry['at']->format('M d, g:i:s A') }}</span>
                                    <div>{{ $entry['at']->diffForHumans() }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="log-message">
                                @if ($entry['email'])
                                    <span class="badge badge-soft-primary"><i class="bi bi-envelope"></i> Email</span>
                                    <span class="fw-semibold">{{ $entry['email']['subject'] }}</span>
                                    <span class="text-muted-soft">to {{ $entry['email']['to'] }}</span>
                                @else
                                    {{ \Illuminate\Support\Str::limit($entry['summary'], 300) }}
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($entry['details'] !== '' || $entry['email'] || mb_strlen($entry['summary']) > 300)
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-bs-toggle="collapse" data-bs-target="#{{ $detailsId }}" aria-expanded="false" aria-controls="{{ $detailsId }}">
                                        <i class="bi bi-chevron-down"></i> Details
                                    </button>
                                @endif
                            </td>
                        </tr>
                        <tr class="log-details-row">
                            <td colspan="4" class="p-0 border-0">
                                <div class="collapse" id="{{ $detailsId }}">
                                    <pre class="log-details">{{ $entry['text'] }}</pre>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted-soft py-4">
                                @if (! $file)
                                    There are no log files yet.
                                @elseif ($level || $search !== '')
                                    Nothing in {{ $file }} matches. <a href="{{ $query(['level' => null, 'search' => null, 'page' => null]) }}" class="fw-semibold">Show everything</a>
                                @else
                                    {{ $file }} is empty.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->hasPages())
            <div class="card-footer bg-white">
                {{ $entries->links() }}
            </div>
        @endif
    </div>
@endsection
