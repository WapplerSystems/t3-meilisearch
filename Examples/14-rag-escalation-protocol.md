# 14 — Escalation to a human and the chat protocol

When the assistant cannot help, the chat shows an "ask a human" card under the
answer. Which turns get it is decided by `meilisearch.rag.fallback.show`
(`onlyEmpty` = no answer, a clarifying question, or an answer without a single
citation). Its default content comes from `meilisearch.rag.fallback.*`.

## Store every turn

```yaml
meilisearch:
  rag:
    protocol:
      enabled: true
      retentionDays: 90   # 0 = keep forever
```

Every finished turn lands in `tx_wsmeilisearch_rag_protocol` under the
conversation id (32 hex digits, deliberately not the session id). The backend
tab **Chat-Protokoll** lists conversations, filters for escalated or
problematic ones and opens a single one by its id. Schedule the prune command
daily ("Execute console commands" task):

```bash
vendor/bin/typo3 ws_meilisearch:rag:protocol:prune [site] [--dry-run]
```

The protocol holds what visitors typed — mention it in the privacy policy.

## Link to a form with the conversation id

Without code: placeholders in `meilisearch.rag.fallback.ticketUrl` are
substituted URL-encoded — `{conversationId}`, `{language}`, `{languageId}`,
`{question}`:

```yaml
meilisearch.rag.fallback.ticketUrl: 'https://example.org/contact?chat={conversationId}&lang={language}'
```

## Own card per language — `RagEscalationEvent`

```php
use TYPO3\CMS\Core\Attribute\AsEventListener;
use WapplerSystems\Meilisearch\Event\RagEscalationEvent;
use WapplerSystems\Meilisearch\Service\Rag\Escalation\EscalationAction;

final class ContactFormEscalation
{
    #[AsEventListener]
    public function __invoke(RagEscalationEvent $event): void
    {
        $language = $event->language ?? $event->site->getDefaultLanguage();
        $href = (string)$event->site->getRouter()->generateUri(42, [
            '_language' => $language,
            'chat' => $event->conversationId,
        ]);
        $event->escalation = $event->escalation
            ->withHeading($language->getLanguageId() === 0 ? 'Keine Antwort gefunden' : 'No answer found')
            ->withAddedAction(EscalationAction::link('Contact form', $href), prepend: true);
        // $event->show = true;  // also offer it under grounded answers
    }
}
```

The event fires for every finished turn on both the streamed and the
synchronous path. `$event->reason` tells why: `no_context`, `failed`,
`clarify`, `uncited`, `answered`, or `static` for the always-on card. Hrefs
other than http(s), mailto, tel or relative ones are dropped.

To get the transcript into a form mail, read it with
`ChatProtocolRepository::findByConversation($id, $siteIdentifier)` and render
it with `ChatProtocolTranscript::toText()` — e.g. from a `MailBeforeSendingEvent`
listener of `wapplersystems/form`, attached only to the mail to the site owner,
never to the visitor's confirmation.
