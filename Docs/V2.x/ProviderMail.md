# HTTP Mail providers

SqueHub's Mailer supports named `resend` and `postmark` transports alongside `smtp` and `array`. Both HTTP adapters passed deterministic local HTTP contract tests on Windows and user-run guarded live provider API tests. Provider acceptance does not prove inbox delivery. SMTP, Array Mail, Notification mail channels, and queued Mail retain their existing APIs.

| Transport | Send endpoint and authentication | Accepted response | Synchronous attachments |
| --- | --- | --- | --- |
| Resend | `POST https://api.resend.com/emails`; `Authorization: Bearer <API key>` | Successful HTTP status and JSON `id` | Filename and Base64 content; only the default `application/octet-stream` MIME |
| Postmark | `POST https://api.postmarkapp.com/email`; `X-Postmark-Server-Token: <token>` | HTTP 200, JSON `ErrorCode: 0`, and `MessageID` | Filename, Base64 content, and MIME type |

The adapters use each provider's single-message JSON contract. Resend sends lowercase `from`, `to`, optional `cc`, `bcc`, `reply_to`, `text`, `html`, and `attachments`; Postmark uses `From`, `To`, optional `Cc`, `Bcc`, `ReplyTo`, `TextBody`, `HtmlBody`, and `Attachments`. Both include the subject. They never submit to a caller-selected URL. See the official [Resend send-email API](https://resend.com/docs/api-reference/emails/send-email), [Resend API schema](https://github.com/resend/resend-openapi/blob/main/resend.yaml), [Postmark email API](https://postmarkapp.com/developer/api/email-api), and [Postmark API overview](https://postmarkapp.com/developer/api/overview).

## Select a transport

The shipped `Config/Mail.php` declares inert named transports. Set one as the default with `MAIL_TRANSPORT=resend` or `MAIL_TRANSPORT=postmark`, or name it for one send:

```php
use App\Plugins\{Mail, MailMessage};

Mail::send(
    (new MailMessage())
        ->to('recipient@example.test')
        ->subject('Welcome')
        ->text('Hello'),
    via: 'resend'
);
```

For Resend, set `RESEND_API_KEY`; for Postmark, set `POSTMARK_SERVER_TOKEN`. Set `MAIL_FROM_ADDRESS` to a sender approved by that provider. The existing `MAIL_TIMEOUT` controls a finite request timeout. Credentials are required only when the selected transport sends; an unselected provider with no credentials does not contact the network or break normal application boot. Configured provider endpoints are fixed HTTPS hosts, redirects are disabled, and peer verification is required. Do not put API keys in application routes, MailMessage content, Queue payloads, or checked-in configuration.

Both providers use the existing outgoing HTTP Client. Mail diagnostics count one attempt, one sent/failure outcome, and transport time; HTTP Client diagnostics record the outbound attempt. Diagnostic snapshots do not retain recipients, subject, body, API token, or provider response body. Provider failures surface as safe categories such as authentication, rate limit, validation, network, server, malformed response, or rejection. A provider HTTP acceptance is not proof that the recipient inbox received the message.

## Queued Mail and Notifications

`Mail::queue($message, via: 'postmark', connection: 'database', queue: 'mail')` stores the logical transport name. The Queue worker reconstructs the message and resolves the worker's current Mail configuration at delivery time. No provider token or HTTP client object enters the Queue payload. The worker uses the same Mailer and HTTP adapter as synchronous sends. Queue owns durable retry/backoff and failed-job recording; the transport makes one bounded HTTP attempt and adds no hidden retry loop. Delivery remains at least once, so duplicates are possible after retries or a crash before acknowledgement. `sync` Queue delivery executes immediately.

The existing queued-Mail contract rejects attachments before persistence. Synchronous provider sends accept supported byte attachments within a bounded request payload. Postmark preserves each attachment's filename and MIME type. Resend's documented send request has filename and Base64 content but no MIME field; the adapter accepts only the default `application/octet-stream` MIME and rejects a custom MIME rather than silently losing it. Neither adapter persists a file handle or streams an unbounded remote object into Mail. A conservative size preflight rejects oversized drafts before Base64/JSON copies; the exact request cap is 10 MiB of encoded JSON. Large messages fail before HTTP dispatch. Provider-specific delivery limits can be stricter.

Both adapters require at least one `To` recipient, even though SMTP/Array Mail can send a BCC-only message. Recipient validation remains in `MailMessage`; each adapter caps the combined To, CC, and BCC recipients at 50. There is no new Mail receipt API: `Mail::send()` and `mailer()->send()` still return `void`, and provider-assigned IDs are checked internally without being stored as mutable last-send state.

## Qualification boundaries

`Tests/Unit/ApiMailTransportTest.php` checks request shape, credentials, bounds, responses, attachments, and safe errors through a fake HTTP transport. `Tests/Integration/MailProviderTest.php` checks named transport selection and Mailer integration. `Tests/Integration/ApiMailLoopbackTest.php` sends the generated requests over a real local HTTP connection using a test-only transport that checks the fixed provider URL before routing to a one-shot loopback receiver. These local tests verify SqueHub's request and response handling; they do not prove provider account setup, external acceptance, or inbox delivery.

`Tests/Integration/ApiMailLiveOptInTest.php` is guarded. Resend requires `SQUEHUB_TEST_RESEND_ENABLED=1`, `SQUEHUB_TEST_RESEND_API_KEY`, `SQUEHUB_TEST_RESEND_FROM`, and `SQUEHUB_TEST_RESEND_TO`. Postmark uses `SQUEHUB_TEST_POSTMARK_ENABLED=1`, `SQUEHUB_TEST_POSTMARK_SERVER_TOKEN`, `SQUEHUB_TEST_POSTMARK_FROM`, and `SQUEHUB_TEST_POSTMARK_TO`. Only an operator with disposable test credentials, an approved sender, and a recipient they control should run either path. Do not set these variables in normal CI or copy real keys into support conversations. The guarded test's Postmark test token alone is not evidence of external delivery.

The reported opted-in live runs used the actual SqueHub adapters. Resend's adapter passed 1 test and 1 assertion after a direct provider request returned HTTP 200. Postmark Server API authentication returned HTTP 200, and its adapter passed 1 test and 1 assertion sending from `hello@squehub.com` to `no-reply@squehub.com`. An earlier cross-domain recipient attempt returned HTTP 422 with Postmark ErrorCode 412 because that account was pending approval; this was a provider-account restriction, not an adapter defect. Cross-domain Postmark sending remains unqualified under that restriction. The test variables were removed after qualification. Neither API acceptance result establishes recipient inbox arrival.

See [Mail](Mail.md), [Notifications](Notifications.md), [Queue](Queue.md), [HTTP Client](HttpClient.md), and [deployment](Deployment.md) for message construction, queued delivery, outbound TLS, and operational configuration.
