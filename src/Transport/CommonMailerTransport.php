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
    /**
     * @param  array{profile?: string|null}  $options  "profile": the profile handle used when the
     *                                                 email does not choose one (X-Mailer-Profile)
     */
    public function __construct(private CommonMailerClient $client, private array $options = [])
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'common-mailer';
    }

    /**
     * Get the transport options.
     *
     * @return array{profile?: string|null}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set the transport options.
     *
     * @param  array{profile?: string|null}  $options
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @throws CommonMailerException
     */
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $queued = $this->client->send(
            MessagePayload::fromEmail($email, $this->options['profile'] ?? null),
            MessagePayload::idempotencyKey($email) ?? (string) Str::uuid(),
        );

        $message->setMessageId($queued['id']);
    }
}
