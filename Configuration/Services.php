<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Dashboard\Widgets\BarChartWidget;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;
use TYPO3\CMS\Dashboard\Widgets\ListWidget;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconWidget;
use WapplerSystems\ZabbixClient\Operation\IOperation;

/**
 * Integrations with optional packages. Services.yaml excludes
 * Classes/Integration/Dashboard and Classes/Integration/ZabbixClient; they
 * are registered here only when EXT:dashboard resp. zabbix_client is there,
 * so neither becomes a hard dependency.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure()->private();

    if (interface_exists(IOperation::class)) {
        $services->set(Integration\ZabbixClient\GetRagUsage::class);
        $services->set(Integration\ZabbixClient\GetMeilisearchHealth::class);
    }

    if (!class_exists(BarChartWidget::class)) {
        return;
    }
    $lll = 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:';
    $services->load('WapplerSystems\\Meilisearch\\Integration\\Dashboard\\', '../Classes/Integration/Dashboard/*')
        ->exclude('../Classes/Integration/Dashboard/TranslatesWidgetLabels.php');

    $widgets = [
        'wsMeilisearchTurnsPerDay' => [BarChartWidget::class, Integration\Dashboard\TurnsPerDayDataProvider::class, 'turnsPerDay', 'content-widget-chart-bar', 'medium', 'medium', []],
        'wsMeilisearchTurnsToday' => [NumberWithIconWidget::class, Integration\Dashboard\TurnsTodayDataProvider::class, 'today', 'content-widget-number', 'small', 'small', [
            'title' => $lll . 'widget.today.title',
            'subtitle' => $lll . 'widget.today.subtitle',
            'icon' => 'content-widget-number',
        ]],
        'wsMeilisearchRating' => [DoughnutChartWidget::class, Integration\Dashboard\RatingDataProvider::class, 'rating', 'content-widget-chart-pie', 'medium', 'small', []],
        'wsMeilisearchTopQuestions' => [ListWidget::class, Integration\Dashboard\TopQuestionsDataProvider::class, 'topQuestions', 'content-widget-list', 'medium', 'medium', []],
        'wsMeilisearchProblems' => [ListWidget::class, Integration\Dashboard\ProblemsDataProvider::class, 'problems', 'content-widget-list', 'medium', 'medium', []],
    ];
    foreach ($widgets as $identifier => [$class, $provider, $key, $icon, $height, $width, $options]) {
        $services->set('ws_meilisearch.widget.' . $identifier)
            ->class($class)
            ->arg('$dataProvider', new Reference($provider))
            ->arg('$options', $options + ['refreshAvailable' => true])
            ->tag('dashboard.widget', [
                'identifier' => $identifier,
                'groupNames' => 'wsMeilisearch',
                'title' => $lll . 'widget.' . $key . '.title',
                'description' => $lll . 'widget.' . $key . '.description',
                'iconIdentifier' => $icon,
                'height' => $height,
                'width' => $width,
            ]);
    }
};
