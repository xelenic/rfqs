<?php

namespace App\Http\Middleware;

use App\Models\Rfq;
use App\Models\RfqCommentAttachment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an RFQ — its page, its photos, every action on it — from whoever
 * sees only the RFQs they created (User::seesOnlyOwnRfqs()) unless it's one
 * of theirs (Rfq::isVisibleTo()), saying so. Reads the route's bound {rfq},
 * or the RFQ of its {attachment}.
 */
class EnsureRfqIsVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->seesOnlyOwnRfqs()) {
            $rfq = $request->route('rfq');
            $attachment = $request->route('attachment');
            $rfq = $rfq instanceof Rfq ? $rfq : ($attachment instanceof RfqCommentAttachment ? $attachment->rfq : null);

            abort_if($rfq !== null && ! $rfq->isVisibleTo($user), 403, 'This RFQ isn\'t one of yours — you can only open the RFQs you created.');
        }

        return $next($request);
    }
}
