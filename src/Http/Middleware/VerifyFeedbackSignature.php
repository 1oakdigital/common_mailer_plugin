<?php

namespace OneOakDigital\CommonMailer\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use OneOakDigital\CommonMailer\Feedback\FeedbackSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets through only feedback signed by the mailer with this site's token
 * (common-mailer.token), within common-mailer.feedback.tolerance seconds.
 */
class VerifyFeedbackSignature
{
    public function __construct(private Repository $config) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isGenuine = FeedbackSignature::verify(
            $request->header(FeedbackSignature::HEADER),
            (string) $request->getContent(),
            (string) $this->config->get('common-mailer.token'),
            (int) $this->config->get('common-mailer.feedback.tolerance', 300),
        );

        if ( ! $isGenuine) {
            return new Response('{"message":"Invalid signature."}', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/json']);
        }

        return $next($request);
    }
}
