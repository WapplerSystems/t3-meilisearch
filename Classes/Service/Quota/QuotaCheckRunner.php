<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Quota;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Mailer\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Walks every site, fans out to the registered QuotaProviders for
 * whichever commercial backends the site uses (meilisearch.rag.provider,
 * meilisearch.embedder.source), and emits a warning email when the
 * site-specific threshold is exceeded. Cron-friendly: idempotent,
 * mail only when over threshold.
 *
 * The dispatch only checks each (site, provider) once even if the
 * site uses the same provider for both RAG and the embedder — we
 * skip duplicate slugs per site so a single Infomaniak product
 * doesn't get hit twice per run.
 */
final class QuotaCheckRunner implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Default warning threshold (percent of cap) when not set per site. */
    public const DEFAULT_THRESHOLD = 80;

    /** @var list<QuotaProviderInterface> */
    private array $providers;

    /**
     * @param iterable<QuotaProviderInterface> $providers DI tagged_iterator
     */
    public function __construct(
        iterable $providers,
        private readonly SiteFinder $siteFinder,
        private readonly MailerInterface $mailer,
    ) {
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    /**
     * @param ?string $siteFilter restrict to one site identifier; null = all
     * @param bool $sendEmail emit warning emails for over-threshold results
     * @return list<array{site:string, status:QuotaStatus, threshold:int, overThreshold:bool, mailed:bool}>
     */
    public function check(?string $siteFilter = null, bool $sendEmail = true): array
    {
        $sites = $siteFilter !== null && $siteFilter !== ''
            ? [$this->siteFinder->getSiteByIdentifier($siteFilter)]
            : $this->siteFinder->getAllSites();

        $out = [];
        foreach ($sites as $site) {
            foreach ($this->providersForSite($site) as $provider) {
                $status = $provider->checkQuota($site);
                $threshold = $this->threshold($site);
                $over = !$status->isError() && $status->usedPercent >= $threshold;
                $mailed = $over && $sendEmail
                    ? $this->sendWarning($site, $provider, $status, $threshold)
                    : false;
                $out[] = [
                    'site' => $site->getIdentifier(),
                    'status' => $status,
                    'threshold' => $threshold,
                    'overThreshold' => $over,
                    'mailed' => $mailed,
                ];
            }
        }
        return $out;
    }

    /**
     * @return list<QuotaProviderInterface>
     */
    private function providersForSite(Site $site): array
    {
        $settings = $site->getSettings();
        // De-duplicate so a site using Infomaniak for both embedder
        // AND RAG only calls the quota API once.
        $slugs = array_unique(array_filter([
            strtolower(trim((string)$settings->get('meilisearch.rag.provider', ''))),
            strtolower(trim((string)$settings->get('meilisearch.embedder.source', ''))),
        ]));
        $matches = [];
        foreach ($slugs as $slug) {
            if ($slug === '') {
                continue;
            }
            foreach ($this->providers as $provider) {
                if ($provider->supports($slug)) {
                    $matches[$provider::class] = $provider;
                }
            }
        }
        return array_values($matches);
    }

    private function threshold(Site $site): int
    {
        $configured = (int)$site->getSettings()->get('meilisearch.quota.threshold', 0);
        return $configured > 0 ? $configured : self::DEFAULT_THRESHOLD;
    }

    private function sendWarning(Site $site, QuotaProviderInterface $provider, QuotaStatus $status, int $threshold): bool
    {
        $recipient = trim((string)$site->getSettings()->get('meilisearch.quota.recipient', ''));
        if ($recipient === '') {
            $this->logger?->warning('Quota over threshold for {provider} on {site} but no meilisearch.quota.recipient set', [
                'provider' => $provider->name(),
                'site' => $site->getIdentifier(),
                'percent' => $status->usedPercent,
            ]);
            return false;
        }
        $subject = sprintf(
            '[%s] %s quota %.1f%% (cap %s)',
            $site->getIdentifier(),
            $provider->name(),
            $status->usedPercent,
            number_format($status->limit, 0, '.', ','),
        );
        $body = sprintf(
            "Site: %s\nProvider: %s\nPeriod: %s\nUsage: %s / %s %s (%.2f%%)\nWarning threshold: %d%%\n\nRaise threshold or top up the provider's plan to avoid hitting the hard cap.\n",
            $site->getIdentifier(),
            $provider->name(),
            $status->period,
            number_format($status->used, 0, '.', ','),
            number_format($status->limit, 0, '.', ','),
            $status->unit,
            $status->usedPercent,
            $threshold,
        );
        try {
            $message = new MailMessage();
            $message
                ->to($recipient)
                ->subject($subject)
                ->text($body);
            // TYPO3 v14 removed MailMessage::send(); the canonical path
            // is the Symfony MailerInterface (DI-injected). Same fix the
            // watchdog uses.
            $this->mailer->send($message);
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send quota warning to {to}: {error}', [
                'to' => $recipient,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
