<?php

namespace App\Console\Commands;

use App\RfqDataEraser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

#[Signature('rfq:clear {--force : Skip the confirmation prompt (and allow running in production)}')]
#[Description('Delete every RFQ along with its Sourcing parts, comments, and any other rfq_id-linked rows')]
class ClearRfqData extends Command
{
    use ConfirmableTrait;

    /**
     * Empties the rfqs table and every table that hangs off it — see
     * App\RfqDataEraser, which Settings → Danger Zone uses too.
     */
    public function handle(): int
    {
        $this->components->info('This will permanently delete all rows from: '.implode(', ', RfqDataEraser::tables()));

        if (! $this->confirmToProceed('Delete ALL RFQ data?', fn (): bool => true)) {
            return self::FAILURE;
        }

        foreach (RfqDataEraser::clear() as $table => $count) {
            $this->components->twoColumnDetail($table, "{$count} rows deleted");
        }

        $this->components->success('All RFQ data cleared.');

        return self::SUCCESS;
    }
}
