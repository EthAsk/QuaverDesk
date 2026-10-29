<?php

namespace Tests\Feature;

use App\Mail\Transport\GraphTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** Email through Microsoft 365 (Microsoft Graph), signed in as an app. */
class GraphMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'graph',
            'mail.from' => ['address' => 'noreply@quaverdesk.test', 'name' => 'QuaverDesk'],
            'mail.mailers.graph.tenant_id' => 'tenant-1',
            'mail.mailers.graph.client_id' => 'client-1',
            'mail.mailers.graph.client_secret' => 'secret-1',
        ]);
    }

    private function fakeGraph(array $more = []): void
    {
        Http::fake($more + [
            'login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            'graph.microsoft.com/v1.0/users/noreply%40quaverdesk.test/sendMail' => Http::response(null, 202),
        ]);
    }

    public function test_mail_is_sent_as_the_mailbox_with_bcc_kept_and_one_sign_in(): void
    {
        $this->fakeGraph();

        foreach (['First', 'Second'] as $subject) {
            Mail::html('<p>Hello</p>', fn (Message $m) => $m->to('ann@acme.test', 'Ann')->bcc('audit@acme.test')->subject($subject));
        }

        Http::assertSentCount(3); // one sign-in, two emails
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth2/v2.0/token')
            && $r['grant_type'] === 'client_credentials' && $r['scope'] === 'https://graph.microsoft.com/.default');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMail') && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['message']['subject'] === 'Second'
            && $r['message']['body'] === ['contentType' => 'HTML', 'content' => '<p>Hello</p>']
            && $r['message']['toRecipients'] === [['emailAddress' => ['address' => 'ann@acme.test', 'name' => 'Ann']]]
            && $r['message']['bccRecipients'] === [['emailAddress' => ['address' => 'audit@acme.test']]]
            && $r['message']['from'] === ['emailAddress' => ['address' => 'noreply@quaverdesk.test', 'name' => 'QuaverDesk']]
            && $r['saveToSentItems'] === false);
    }

    public function test_small_attachments_go_with_the_message(): void
    {
        $this->fakeGraph();

        Mail::raw('Voicemail attached', fn (Message $m) => $m->to('ann@acme.test')->subject('Voicemail')
            ->attachData('RIFF....', 'voicemail.wav', ['mime' => 'audio/wav']));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMail')
            && $r['message']['body'] === ['contentType' => 'Text', 'content' => 'Voicemail attached']
            && $r['message']['attachments'] === [[
                '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'voicemail.wav', 'contentType' => 'audio/wav',
                'contentBytes' => base64_encode('RIFF....'), 'isInline' => false,
            ]]);
    }

    public function test_a_long_voicemail_is_uploaded_in_pieces_then_sent(): void
    {
        $this->fakeGraph([
            'graph.microsoft.com/v1.0/users/noreply%40quaverdesk.test/messages' => Http::response(['id' => 'm1'], 201),
            'graph.microsoft.com/v1.0/users/noreply%40quaverdesk.test/messages/m1/attachments/createUploadSession' => Http::response(['uploadUrl' => 'https://outlook.office.com/upload/xyz']),
            'outlook.office.com/upload/xyz' => Http::response(null, 200),
            'graph.microsoft.com/v1.0/users/noreply%40quaverdesk.test/messages/m1/send' => Http::response(null, 202),
        ]);
        $audio = str_repeat('a', 4_000_000);

        Mail::raw('Voicemail attached', fn (Message $m) => $m->to('ann@acme.test')->subject('Voicemail')
            ->attachData($audio, 'voicemail.wav', ['mime' => 'audio/wav']));

        $chunk = GraphTransport::CHUNK;
        $last = 4_000_000 - 1;
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/users/noreply%40quaverdesk.test/messages') && ! isset($r['attachments']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/createUploadSession') && $r['AttachmentItem']['size'] === 4_000_000);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Content-Range', 'bytes 0-'.($chunk - 1).'/4000000')
            && ! $r->hasHeader('Authorization') && strlen($r->body()) === $chunk);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Content-Range', "bytes {$chunk}-{$last}/4000000"));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages/m1/send'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMail'));
    }

    public function test_a_refusal_is_reported_with_microsofts_reason(): void
    {
        $this->fakeGraph([
            'graph.microsoft.com/v1.0/users/noreply%40quaverdesk.test/sendMail' => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Microsoft 365 did not send the email: Access is denied.');

        Mail::raw('Hi', fn (Message $m) => $m->to('ann@acme.test')->subject('Hi'));
    }
}
