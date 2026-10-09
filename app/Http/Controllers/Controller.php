<?php

namespace App\Http\Controllers;

use App\Models\RfqCommentAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

abstract class Controller
{
    /**
     * The photos and files sent with a comment or a reason (attachments[]),
     * held to RfqCommentAttachment::rules() — or, if one won't do, back to
     * where it came from saying why, the way a missing reason is.
     *
     * @return array<int, UploadedFile>|RedirectResponse
     */
    protected function attachmentsFrom(Request $request): array|RedirectResponse
    {
        $validator = Validator::make($request->all(), RfqCommentAttachment::rules(), RfqCommentAttachment::messages());

        return $validator->fails()
            ? back()->withInput()->with('error', $validator->errors()->first())
            : array_values($request->file('attachments', []));
    }
}
