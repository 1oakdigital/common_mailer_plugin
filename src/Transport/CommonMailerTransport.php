<?php

namespace OneOakDigital\CommonMailer\Transport;

use Illuminate\Support\Str;
use OneOakDigital\CommonMailer\CommonMailerClient;
use OneOakDigital\CommonMailer\Exceptions\CommonMailerException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport "common-mailer": hands each email to the mailer's
 * POST /api/v1/messages. The mailer queues and sends it; the SentMessage's
 * message id becomes the mailer's mail id (for CommonMailerClient::message()).
 */
class CommonMailerTransport extends AbstractTransport
{
    public function __construct(private CommonMailerClient $client, private ?string $defaultProfile = null)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'common-mailer';
    }

    /**
     * @throws CommonMailerException
     */
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $queued = $this->client->send(
            MessagePayload::fromEmail($email, $this->defaultProfile),
            MessagePayload::idempotencyKey($email) ?? (string) Str::uuid(),
        );

        $message->setMessageId($queued['id']);
    }
}
