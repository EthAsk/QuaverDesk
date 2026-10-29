<?php

namespace App\Mail\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends mail through Microsoft 365 (Microsoft Graph) as one mailbox, signed in
 * as an app registration (client credentials), so no mailbox password is kept.
 * The sender is MAIL_FROM_ADDRESS; the app should be allowed to send as that
 * mailbox only (Exchange RBAC for Applications).
 *
 * Mail goes as a structured message, not raw MIME, so Bcc recipients are kept.
 * Graph takes at most about 3 MB of attachments in one request; larger ones
 * (a long voicemail) are uploaded to a draft in pieces, then the draft is sent.
 */
class GraphTransport extends AbstractTransport
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    public const INLINE_LIMIT = 3_000_000;
    public const CHUNK = 327_680 * 10; // upload pieces must be multiples of 320 KiB

    /** Kept in memory only, never in the cache or database. */
    private ?string $token = null;
    private int $tokenExpires = 0;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $mailbox = rawurlencode($message->getEnvelope()->getSender()->getAddress());
        $recipients = $message->getEnvelope()->getRecipients();
        $attachments = $email->getAttachments();

        $json = $this->message($email, $recipients);
        $size = array_sum(array_map(fn (DataPart $a) => strlen($a->getBody()), $attachments));

        if ($size <= self::INLINE_LIMIT) {
            $json['attachments'] = array_map($this->attachment(...), $attachments);
            $this->check($this->graph()->post(self::GRAPH."/users/{$mailbox}/sendMail", ['message' => $json, 'saveToSentItems' => false]));

            return;
        }

        $id = $this->check($this->graph()->post(self::GRAPH."/users/{$mailbox}/messages", $json))->json('id');
        foreach ($attachments as $part) {
            strlen($part->getBody()) <= self::INLINE_LIMIT
                ? $this->check($this->graph()->post(self::GRAPH."/users/{$mailbox}/messages/{$id}/attachments", $this->attachment($part)))
                : $this->upload("/users/{$mailbox}/messages/{$id}", $part);
        }
        $this->check($this->graph()->post(self::GRAPH."/users/{$mailbox}/messages/{$id}/send"));
    }

    /**
     * @param  list<Address>  $recipients  everyone the mail goes to, Bcc included
     * @return array<string, mixed>
     */
    private function message(Email $email, array $recipients): array
    {
        $to = $this->addresses($email->getTo());
        $cc = $this->addresses($email->getCc());
        $shown = array_map(fn (array $r) => strtolower($r['emailAddress']['address']), [...$to, ...$cc]);
        $bcc = array_values(array_filter($this->addresses($recipients), fn (array $r) => ! in_array(strtolower($r['emailAddress']['address']), $shown, true)));
        $html = $email->getHtmlBody();

        return array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => $html !== null
                ? ['contentType' => 'HTML', 'content' => is_resource($html) ? stream_get_contents($html) : $html]
                : ['contentType' => 'Text', 'content' => (string) (is_resource($text = $email->getTextBody()) ? stream_get_contents($text) : $text)],
            'from' => $this->addresses($email->getFrom())[0] ?? null,
            'toRecipients' => $to,
            'ccRecipients' => $cc,
            'bccRecipients' => $bcc,
            'replyTo' => $this->addresses($email->getReplyTo()),
        ], fn ($v) => $v !== [] && $v !== null);
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{emailAddress: array{address: string, name?: string}}>
     */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $a) => ['emailAddress' => array_filter(['address' => $a->getAddress(), 'name' => $a->getName()])], $addresses);
    }

    /** @return array<string, mixed> */
    private function attachment(DataPart $part): array
    {
        return array_filter([
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $part->getFilename() ?? 'attachment',
            'contentType' => $part->getContentType(),
            'contentBytes' => base64_encode($part->getBody()),
            'isInline' => $part->getDisposition() === 'inline',
            'contentId' => $part->getDisposition() === 'inline' ? $part->getContentId() : null,
        ], fn ($v) => $v !== null);
    }

    private function upload(string $message, DataPart $part): void
    {
        $body = $part->getBody();
        $size = strlen($body);
        $url = $this->check($this->graph()->post(self::GRAPH."{$message}/attachments/createUploadSession", ['AttachmentItem' => [
            'attachmentType' => 'file', 'name' => $part->getFilename() ?? 'attachment', 'size' => $size, 'contentType' => $part->getContentType(),
        ]]))->json('uploadUrl');

        // The upload address carries its own authorisation; no token is sent with it.
        for ($from = 0; $from < $size; $from += self::CHUNK) {
            $piece = substr($body, $from, self::CHUNK);
            $to = $from + strlen($piece) - 1;
            $this->check(Http::withHeaders(['Content-Range' => "bytes {$from}-{$to}/{$size}"])
                ->withBody($piece, 'application/octet-stream')->timeout(60)->put($url));
        }
    }

    private function graph(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(30);
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires) {
            return $this->token;
        }

        $response = Http::asForm()->timeout(15)->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
        ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new TransportException('Microsoft 365 sign-in failed: '.($response->json('error_description') ?? 'HTTP '.$response->status()));
        }
        $this->tokenExpires = time() + (int) $response->json('expires_in', 3600) - 300;

        return $this->token = $response->json('access_token');
    }

    private function check(Response $response): Response
    {
        if (! $response->successful()) {
            if ($response->status() === 401) {
                $this->token = null;
            }
            throw new TransportException('Microsoft 365 did not send the email: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return $response;
    }

    public function __toString(): string
    {
        return 'graph';
    }
}
