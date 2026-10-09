<?php

namespace App\Models;

use Database\Factories\RfqCommentAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A photo or file attached to a comment on an RFQ's thread (RfqComment) — a
 * comment of anyone's own, or the reason an action gave. Kept on the private
 * disk, and only ever served through RfqCommentController::attachment(), to
 * those who can see RFQs: an image shown in place, anything else downloaded.
 */
class RfqCommentAttachment extends Model
{
    /** @use HasFactory<RfqCommentAttachmentFactory> */
    use HasFactory;

    /**
     * The disk the files are kept on — private, never served directly.
     */
    public const DISK = 'local';

    /**
     * How many files a comment can carry, and how big each can be (KB).
     */
    public const MAX_FILES = 5;

    public const MAX_KILOBYTES = 10240;

    /**
     * What can be attached: photos (no SVG — it can carry script), and the
     * documents that go with an RFQ.
     *
     * @var array<int, string>
     */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];

    protected $fillable = [
        'rfq_id',
        'rfq_comment_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The file goes with it.
        static::deleted(fn (self $attachment) => Storage::disk(self::DISK)->delete($attachment->path));
    }

    /**
     * The rules a comment's attachments[] are held to.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'mimes:'.implode(',', self::EXTENSIONS), 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'attachments.max' => 'Attach up to '.self::MAX_FILES.' files at a time.',
            'attachments.*.file' => 'One of those attachments didn\'t upload — try it again.',
            'attachments.*.uploaded' => 'One of those attachments didn\'t upload — try it again.',
            'attachments.*.mimes' => 'Attach photos (JPG, PNG, GIF, WebP), PDFs, or Word, Excel, CSV or text files.',
            'attachments.*.max' => 'Keep each attachment under '.(self::MAX_KILOBYTES / 1024).' MB.',
        ];
    }

    /**
     * Whether it's a photo, to show in place rather than download.
     */
    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /**
     * Where it's served from — downloaded, rather than shown, with $download.
     */
    public function url(bool $download = false): string
    {
        return route('admin.rfqs.attachments.show', array_filter(['attachment' => $this, 'download' => $download ? 1 : null]));
    }

    /**
     * Its size as it reads — "840 KB", "2.4 MB".
     */
    public function sizeLabel(): string
    {
        return $this->size >= 1024 * 1024
            ? round($this->size / 1024 / 1024, 1).' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }

    /**
     * The Bootstrap icon for a file that isn't a photo.
     */
    public function icon(): string
    {
        return match (strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION))) {
            'pdf' => 'bi-file-earmark-pdf',
            'doc', 'docx' => 'bi-file-earmark-word',
            'xls', 'xlsx', 'csv' => 'bi-file-earmark-spreadsheet',
            default => 'bi-file-earmark-text',
        };
    }

    /**
     * The comment it's attached to.
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(RfqComment::class, 'rfq_comment_id');
    }
}
