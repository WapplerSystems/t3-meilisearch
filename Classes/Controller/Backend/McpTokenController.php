<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpTokenRepository;

/**
 * Backend "MCP-Zugänge" tab — admin-only token management for the MCP endpoint.
 *
 * Tokens are bearer secrets stored only as SHA-256. Writes run as the selected
 * backend user, so that user's permission set (tables_modify on
 * tx_wsmeilisearch_knowledge_entry, web mounts) bounds what the token may change.
 * A dedicated non-admin user is the recommended way to limit write access.
 */
final class McpTokenController
{

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly McpTokenRepository $repository,
        private readonly SiteFinder $siteFinder,
        private readonly BackendContext $context,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function handle(ServerRequestInterface $request, string $action): ResponseInterface
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        return match ($action) {
            'mcpTokenCreate' => $this->createToken($request),
            'mcpTokenToggle' => $this->toggleToken($request),
            'mcpTokenRevoke' => $this->revokeToken($request),
            default => $this->listTokens($request),
        };
    }

    private function requireAdmin(): ?ResponseInterface
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication || !$beUser->isAdmin()) {
            $this->context->addFlash($this->context->label('be.flash.mcpAdminOnly'), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect();
        }
        return null;
    }

    private function listTokens(
        ServerRequestInterface $request,
        array $formValues = [],
        ?string $newToken = null,
        ?string $newTokenTitle = null,
    ): ResponseInterface {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $sites = $this->buildSites();
        $users = $this->buildBackendUsers();

        $siteMap = [];
        $userMap = [];
        foreach ($sites as $site) {
            $siteMap[$site['identifier']] = $site['title'];
        }
        foreach ($users as $user) {
            $userMap[(int)$user['uid']] = $user;
        }

        $moduleTemplate->assignMultiple([
            'tokens' => $this->buildTokens($this->repository->findAll(), $siteMap, $userMap),
            'sites' => $sites,
            'scopeOptions' => $this->buildScopeOptions($formValues['scopes'] ?? []),
            'siteOptions' => $this->buildSiteOptions($sites, $formValues['sites'] ?? []),
            'beUserOptions' => $this->buildBeUserOptions($users, (int)($formValues['be_user'] ?? 0)),
            'formValues' => [
                'title' => (string)($formValues['title'] ?? ''),
                'expires' => (string)($formValues['expires'] ?? ''),
            ],
            'newToken' => $newToken,
            'newTokenTitle' => $newTokenTitle,
            'createUrl' => $this->context->route('mcpTokenCreate'),
            'toggleUrl' => $this->context->route('mcpTokenToggle'),
            'revokeUrl' => $this->context->route('mcpTokenRevoke'),
            ...$this->context->tabNavData(),
            'mcpTokensUrl' => $this->context->route('mcpTokens'),
            'active' => 'mcp',
            'baseUrl' => $this->context->route('mcpTokens'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Overview/McpTokens');
    }

    private function createToken(ServerRequestInterface $request): ResponseInterface
    {
        if ($response = $this->context->requirePost($request, 'mcpTokens')) {
            return $response;
        }

        $body = (array)$request->getParsedBody();
        $formValues = [
            'title' => trim((string)($body['title'] ?? '')),
            'scopes' => array_values(array_unique(array_map(static fn($v): string => (string)$v, (array)($body['scopes'] ?? [])))),
            'sites' => array_values(array_unique(array_map(static fn($v): string => (string)$v, (array)($body['sites'] ?? [])))),
            'be_user' => (int)($body['be_user'] ?? 0),
            'expires' => trim((string)($body['expires'] ?? '')),
        ];

        $errors = $this->validateForm($formValues);
        if ($errors !== []) {
            $this->context->addFlash(implode(' ', $errors), ContextualFeedbackSeverity::ERROR);
            return $this->listTokens($request, $formValues);
        }

        $expires = $formValues['expires'] === '' ? 0 : $this->expiresTimestamp($formValues['expires']);
        $created = $this->repository->create(
            $formValues['title'],
            $formValues['scopes'],
            $formValues['sites'],
            $formValues['be_user'],
            $expires,
            (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
        );

        return $this->listTokens($request, [], $created['token'], mb_substr($formValues['title'], 0, 255));
    }

    private function toggleToken(ServerRequestInterface $request): ResponseInterface
    {
        if ($response = $this->context->requirePost($request, 'mcpTokens')) {
            return $response;
        }

        $uid = (int)($request->getParsedBody()['uid'] ?? 0);
        $token = null;
        foreach ($this->repository->findAll() as $row) {
            if ((int)$row['uid'] === $uid) {
                $token = $row;
                break;
            }
        }

        if ($token === null) {
            $this->context->addFlash($this->context->label('be.flash.mcpNotFound'), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('mcpTokens');
        }

        $disabled = (bool)($token['hidden'] ?? false);
        $this->repository->setDisabled($uid, !$disabled);
        $this->context->addFlash($this->context->label($disabled ? 'be.flash.mcpUnlocked' : 'be.flash.mcpLocked'), ContextualFeedbackSeverity::OK);

        return $this->context->redirect('mcpTokens');
    }

    private function revokeToken(ServerRequestInterface $request): ResponseInterface
    {
        if ($response = $this->context->requirePost($request, 'mcpTokens')) {
            return $response;
        }

        $uid = (int)($request->getParsedBody()['uid'] ?? 0);
        $this->repository->revoke($uid);
        $this->context->addFlash($this->context->label('be.flash.mcpRevoked'), ContextualFeedbackSeverity::OK);

        return $this->context->redirect('mcpTokens');
    }

    /**
     * @return list<string>
     */
    private function validateForm(array $form): array
    {
        $errors = [];

        $title = mb_substr($form['title'], 0, 255);
        if ($title === '') {
            $errors[] = $this->context->label('be.mcp.error.title');
        }

        $validScopes = array_values(array_intersect($form['scopes'], McpClient::ALL_SCOPES));
        if ($validScopes === []) {
            $errors[] = $this->context->label('be.mcp.error.noScope');
        } elseif (count($validScopes) !== count($form['scopes'])) {
            $errors[] = $this->context->label('be.mcp.error.unknownScope');
        }

        $availableSites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $availableSites[] = $site->getIdentifier();
        }
        $validSites = array_values(array_intersect($form['sites'], $availableSites));
        if (count($validSites) !== count($form['sites'])) {
            $errors[] = $this->context->label('be.mcp.error.unknownSite');
        }

        $userId = (int)$form['be_user'];
        $userMap = $this->getBackendUserMap();
        if ($userId !== 0 && !isset($userMap[$userId])) {
            $errors[] = $this->context->label('be.mcp.error.beUser');
        }

        if ($userId === 0 && in_array(McpClient::SCOPE_KNOWLEDGE_WRITE, $validScopes, true)) {
            $errors[] = $this->context->label('be.mcp.error.writeNeedsUser');
        }

        if ($form['expires'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $form['expires']);
            if ($date === false || $date->format('Y-m-d') !== $form['expires']) {
                $errors[] = $this->context->label('be.mcp.error.expires');
            }
        }

        return $errors;
    }

    private function expiresTimestamp(string $date): int
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $date)->setTime(23, 59, 59)->getTimestamp();
    }

    private function getBackendUserMap(): array
    {
        $map = [];
        foreach ($this->buildBackendUsers() as $user) {
            $map[(int)$user['uid']] = $user;
        }
        return $map;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildSites(): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            $sites[] = [
                'identifier' => $identifier,
                'title' => trim((string)($site->getConfiguration()['websiteTitle'] ?? '')) ?: $identifier,
                'endpoint' => rtrim((string)$site->getBase(), '/') . '/_ws_meilisearch/mcp',
                'mcpEnabled' => (bool)$site->getSettings()->get('meilisearch.mcp.enabled', false),
            ];
        }
        return $sites;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildBackendUsers(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select('uid', 'username', 'realName', 'admin')
            ->from('be_users')
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('disable', 0),
            )
            ->orderBy('username')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => !str_starts_with((string)$row['username'], '_cli_'),
        ));
    }

    /**
     * @param list<string> $selectedScopes
     * @return list<array{value:string,label:string,checked:bool}>
     */
    private function buildScopeOptions(array $selectedScopes): array
    {
        $options = [];
        foreach (McpClient::ALL_SCOPES as $scope) {
            $options[] = [
                'value' => $scope,
                'label' => $this->context->label('be.mcp.scope.' . str_replace(':', '.', $scope)),
                'checked' => in_array($scope, $selectedScopes, true),
            ];
        }
        return $options;
    }

    /**
     * @param list<array<string,mixed>> $sites
     * @param list<string> $selectedSites
     * @return list<array{identifier:string,title:string,checked:bool}>
     */
    private function buildSiteOptions(array $sites, array $selectedSites): array
    {
        $options = [];
        foreach ($sites as $site) {
            $options[] = [
                'identifier' => $site['identifier'],
                'title' => $site['title'],
                'checked' => in_array($site['identifier'], $selectedSites, true),
            ];
        }
        return $options;
    }

    /**
     * @param list<array<string,mixed>> $users
     * @return list<array{uid:int,label:string,selected:bool}>
     */
    private function buildBeUserOptions(array $users, int $selectedUid): array
    {
        $options = [
            [
                'uid' => 0,
                'label' => $this->context->label('be.mcp.beUserNone'),
                'selected' => $selectedUid === 0,
            ],
        ];

        foreach ($users as $user) {
            $uid = (int)$user['uid'];
            $username = (string)$user['username'];
            $realName = trim((string)$user['realName']);
            $label = $username;
            if ($realName !== '') {
                $label = $username . ' (' . $realName . ')';
            }
            if ((bool)$user['admin']) {
                $label .= ' [Admin]';
            }
            $options[] = [
                'uid' => $uid,
                'label' => $label,
                'selected' => $selectedUid === $uid,
            ];
        }

        return $options;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $siteMap
     * @param array<int,array<string,mixed>> $userMap
     * @return list<array<string,mixed>>
     */
    private function buildTokens(array $rows, array $siteMap, array $userMap): array
    {
        $now = time();
        $tokens = [];
        foreach ($rows as $row) {
            $scopeBadges = [];
            foreach ($row['scopes'] as $scope) {
                $scopeBadges[] = [
                    'value' => $scope,
                    'label' => $this->context->label('be.mcp.scope.' . str_replace(':', '.', $scope)),
                ];
            }

            $sites = $row['sites'];
            $siteLabels = $sites === [] ? [] : array_map(
                static fn(string $site): string => $siteMap[$site] ?? $site,
                $sites,
            );

            $uid = (int)$row['uid'];
            $beUser = (int)$row['be_user'];
            $createdBy = (int)$row['created_by'];
            $expires = (int)$row['expires'];

            $tokens[] = [
                'uid' => $uid,
                'crdate' => (int)$row['crdate'],
                'title' => (string)$row['title'],
                'tokenPrefix' => (string)$row['token_prefix'],
                'scopeBadges' => $scopeBadges,
                'sitesLabel' => $sites === [] ? $this->context->label('be.mcp.allSites') : implode(', ', $siteLabels),
                'beUserName' => $beUser > 0 ? (string)($userMap[$beUser]['username'] ?? '') : '',
                'createdByName' => $createdBy > 0 ? (string)($userMap[$createdBy]['username'] ?? '') : '',
                'expires' => $expires,
                'expired' => $expires > 0 && $expires < $now,
                'lastUsed' => (int)$row['last_used'],
                'hidden' => (bool)($row['hidden'] ?? false),
            ];
        }
        return $tokens;
    }
}
