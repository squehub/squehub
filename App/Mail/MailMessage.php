<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Fluent application content. Builder calls mutate this object; Mailer sends
 * a snapshot so defaults and transport work never change the caller's draft.
 * The object deliberately offers no generic serialization of private content.
 */
final class MailMessage
{
    private ?MailAddress $from = null;
    /** @var list<MailAddress> */
    private array $to = [];
    /** @var list<MailAddress> */
    private array $cc = [];
    /** @var list<MailAddress> */
    private array $bcc = [];
    /** @var list<MailAddress> */
    private array $replyTo = [];
    private ?string $subject = null;
    private ?string $text = null;
    private ?string $html = null;
    /** @var list<MailAttachment> */
    private array $attachments = [];

    public function from(string $address, ?string $name = null): self
    {
        $this->from = new MailAddress($address, $name);
        return $this;
    }

    public function to(string $address, ?string $name = null): self
    {
        $this->to[] = new MailAddress($address, $name);
        return $this;
    }

    public function cc(string $address, ?string $name = null): self
    {
        $this->cc[] = new MailAddress($address, $name);
        return $this;
    }

    public function bcc(string $address, ?string $name = null): self
    {
        $this->bcc[] = new MailAddress($address, $name);
        return $this;
    }

    public function replyTo(string $address, ?string $name = null): self
    {
        $this->replyTo[] = new MailAddress($address, $name);
        return $this;
    }

    public function subject(string $subject): self
    {
        if (trim($subject) === '' || strlen($subject) > 998 || preg_match('/[\x00-\x1F\x7F]/', $subject)) {
            throw new MailConfigurationException('Invalid mail subject.');
        }
        $this->subject = $subject;
        return $this;
    }

    public function text(string $body): self { $this->text = $body; return $this; }
    public function html(string $body): self { $this->html = $body; return $this; }

    public function attachBytes(
        string $contents,
        string $filename,
        string $mime = 'application/octet-stream'
    ): self {
        $this->attachments[] = new MailAttachment($contents, $filename, $mime);
        return $this;
    }

    /** Value objects and strings are immutable, so clone isolates all draft changes. */
    public function snapshot(): self { return clone $this; }

    public function sender(): ?MailAddress { return $this->from; }
    /** @return list<MailAddress> */
    public function recipients(): array { return $this->to; }
    /** @return list<MailAddress> */
    public function carbonCopies(): array { return $this->cc; }
    /** @return list<MailAddress> */
    public function blindCopies(): array { return $this->bcc; }
    /** @return list<MailAddress> */
    public function replyAddresses(): array { return $this->replyTo; }
    public function subjectLine(): ?string { return $this->subject; }
    public function textBody(): ?string { return $this->text; }
    public function htmlBody(): ?string { return $this->html; }
    /** @return list<MailAttachment> */
    public function attachments(): array { return $this->attachments; }

    /** Reject incomplete content before a transport opens a network connection. */
    public function validate(): void
    {
        if ($this->from === null) throw new MailConfigurationException('Mail sender is not configured.');
        if ($this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw new MailConfigurationException('Mail needs at least one recipient.');
        }
        if ($this->subject === null) throw new MailConfigurationException('Mail subject is required.');
        if (($this->text === null || $this->text === '') && ($this->html === null || $this->html === '')) {
            throw new MailConfigurationException('Mail needs a non-empty text or HTML body.');
        }
    }
}
