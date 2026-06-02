<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\Messenger;

use Lochmueller\Index\Queue\Message\FileMessage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Integration\ExtIndex\Context\IndexingContext;

/**
 * Pushes the Site of the in-flight indexing message onto IndexingContext
 * before the handler runs and pops it again after.
 *
 * EXT:index's FileExtractionInterface is site-agnostic (`getFileContent(File)`)
 * but our TikaFileExtractor needs the Site to read per-site `meilisearch.tika.*`
 * settings. The FileMessage carries `siteIdentifier`; this middleware bridges
 * the gap without monkey-patching upstream.
 */
final class IndexingContextMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly IndexingContext $context,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $site = $this->resolveSite($envelope->getMessage());
        if ($site !== null) {
            $this->context->push($site);
        }
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            if ($site !== null) {
                $this->context->pop();
            }
        }
    }

    private function resolveSite(object $message): ?Site
    {
        if (!$message instanceof FileMessage) {
            return null;
        }
        try {
            return $this->siteFinder->getSiteByIdentifier($message->siteIdentifier);
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'IndexingContextMiddleware: could not resolve site {id}: {message}',
                ['id' => $message->siteIdentifier, 'message' => $e->getMessage()],
            );
            return null;
        }
    }
}
