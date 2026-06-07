<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend\Support;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Shared infrastructure for every backend module controller in this
 * extension: route building, tab nav data, CSRF token extraction,
 * flash message dispatch, redirect-to-action.
 *
 * Extracted out of OverviewController so the four sub-controllers
 * (Index/Reindex, Test, Diagnose, HelpDoc) don't each carry their
 * own copy of the same helpers.
 *
 * Why not a trait: the helpers need DI-injected dependencies
 * (BackendUriBuilder, FlashMessageService). Sharing them through a
 * trait would force every using class to mirror the same constructor
 * boilerplate; a service stays testable and decoupled.
 */
final class BackendContext
{
    /**
     * The module's route identifier — every BE URL on this module
     * derives from it. Centralised so a future rename doesn't have
     * to grep through every controller.
     */
    private const ROUTE_NAME = 'system_wsmeilisearch';

    public function __construct(
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    /**
     * Build a URL on this module. Pass `null` for the overview page,
     * a slug for any sub-action, optionally extra query parameters.
     */
    public function route(?string $action = null, array $extra = []): string
    {
        $params = $action !== null ? array_merge(['action' => $action], $extra) : $extra;
        return (string)$this->backendUriBuilder->buildUriFromRoute(self::ROUTE_NAME, $params);
    }

    public function redirect(?string $action = null): ResponseInterface
    {
        return new RedirectResponse($this->route($action));
    }

    /**
     * Tab nav data assigned by every page template. The CSRF token is
     * pulled out of the URL the BackendUriBuilder generates because
     * HTML5 GET-forms discard the action URL's query string — the form
     * has to ship the token as a hidden field instead. See the long
     * comment on commonTabUrls() in the pre-split controller for the
     * incident history.
     *
     * @return array<string,string>
     */
    public function tabNavData(): array
    {
        $testUrl = $this->route('test');
        parse_str((string)parse_url($testUrl, PHP_URL_QUERY), $query);
        return [
            'indexUrl' => $this->route(),
            'testUrl' => $testUrl,
            'diagnoseUrl' => $this->route('diagnose'),
            'helpdocsUrl' => $this->route('helpdocs'),
            'token' => (string)($query['token'] ?? ''),
        ];
    }

    public function addFlash(string $message, ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage(new FlashMessage($message, '', $severity, true));
    }
}
