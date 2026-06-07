<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
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
        private readonly FormProtectionFactory $formProtectionFactory,
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
     * Tab nav data assigned by every page template — URLs for the four
     * tabs plus the CSRF token that GET forms need to embed as a hidden
     * field (HTML5 spec discards the action URL's query string on form
     * GET submit, so the token has to ride in the form body).
     *
     * Token generation mirrors what BackendUriBuilder does internally
     * (FormProtectionFactory::createForType('backend')->generateToken(
     * 'route', $routeName)) so we don't have to parse-and-extract from
     * the URL — that worked but quietly broke if TYPO3 ever moved the
     * token to a header or cookie.
     *
     * @return array<string,string>
     */
    public function tabNavData(): array
    {
        return [
            'indexUrl' => $this->route(),
            'testUrl' => $this->route('test'),
            'diagnoseUrl' => $this->route('diagnose'),
            'helpdocsUrl' => $this->route('helpdocs'),
            'token' => $this->formProtectionFactory
                ->createForType('backend')
                ->generateToken('route', self::ROUTE_NAME),
        ];
    }

    public function addFlash(string $message, ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage(new FlashMessage($message, '', $severity, true));
    }

    /**
     * Guard for action methods that must only run via POST. Returns the
     * redirect response to send back if the request method is wrong,
     * `null` if the request is fine and the caller may proceed. Pass
     * the slug of the tab the operator should land on after the
     * redirect (defaults to the Overview / index tab).
     *
     * Usage:
     *   if ($r = $this->context->requirePost($request, 'helpdocs')) { return $r; }
     */
    public function requirePost(ServerRequestInterface $request, ?string $redirectAction = null): ?ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'POST') {
            return null;
        }
        return $this->redirect($redirectAction);
    }
}
