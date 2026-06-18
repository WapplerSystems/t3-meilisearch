<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WapplerSystems\Meilisearch\Service\Import\SourceImporterRegistry;

/**
 * Dispatch-CLI for any registered {@see KnowledgeResourceSourceImporter}. The
 * `--importer=` slug picks the implementation (default: dita-ot for
 * backwards compatibility with the original LINEAR-Solutions workflow);
 * every other option is interpreted via the importer's `describeFields()`
 * schema. That way new importers (zip-bundle, url-crawl, …) become
 * usable on the CLI without touching this shell.
 *
 * The CLI's value-add over the BE form is a long-running SymfonyStyle
 * progress bar, plus the ability to run from cron / deploy scripts.
 */
#[AsCommand(
    name: 'ws_meilisearch:import-knowledge-resources',
    description: 'Import help docs from a registered source importer (DITA-OT XHTML by default).',
)]
final class ImportKnowledgeResourcesCommand extends Command
{
    public function __construct(
        private readonly SourceImporterRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'importer',
            null,
            InputOption::VALUE_REQUIRED,
            'Source importer slug. Use --list-importers to see the available ones.',
            'dita-ot',
        );
        // Generic --field=name=value pairs so new importers don't need
        // CLI changes. Repeatable. We parse them in collectConfig().
        $this->addOption(
            'field',
            'f',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Importer-specific field, format name=value. Repeat for multiple. e.g. -f path=... -f language=0',
        );
        // Friendlier aliases for the well-known DITA fields so the most
        // common command stays short and self-documenting.
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, '[dita-ot] Source path. Equivalent to -f path=...');
        $this->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'sys_language_uid. Equivalent to -f language=...');
        $this->addOption('langDir', null, InputOption::VALUE_REQUIRED, '[dita-ot] Language directory. Equivalent to -f langDir=...');
        $this->addOption('pid', null, InputOption::VALUE_REQUIRED, '[dita-ot] Storage pid.');
        $this->addOption('no-purge', null, InputOption::VALUE_NONE, '[dita-ot] Skip the purge step.');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, '[dita-ot] Only import the first N topics.');
        $this->addOption('list-importers', null, InputOption::VALUE_NONE, 'Print the list of registered importers and their accepted fields.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list-importers')) {
            $this->printImporters($io);
            return Command::SUCCESS;
        }

        $name = (string)$input->getOption('importer');
        if (!$this->registry->has($name)) {
            $known = array_map(static fn ($i) => $i->name(), $this->registry->all());
            $io->error(sprintf('Unknown importer "%s". Known: %s', $name, implode(', ', $known)));
            return Command::FAILURE;
        }
        $importer = $this->registry->get($name);

        $config = $this->collectConfig($input);

        $io->section(sprintf('Import via "%s"', $importer->label()));

        $progressBar = null;
        $onProgress = function (int $current, int $total, string $marker) use (&$progressBar, $io): void {
            if ($progressBar === null) {
                $io->writeln(sprintf('Processing %d item(s)…', $total));
                $progressBar = $io->createProgressBar($total);
                $progressBar->start();
            }
            $progressBar->setProgress($current);
        };

        try {
            $result = $importer->import($config, $onProgress);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($progressBar instanceof ProgressBar) {
            $progressBar->finish();
            $io->newLine(2);
        }
        // Surface per-item errors so the operator sees which inputs failed.
        // The BE controller bubbles these up via the flash message; the CLI
        // gets a dedicated table because the list can be long.
        $errors = (array)($result->extras['errors'] ?? []);
        if ($errors !== []) {
            $io->warning(sprintf('%d item(s) failed:', count($errors)));
            $io->listing(array_map(static fn ($e) => (string)$e, $errors));
        }
        $io->success($result->summary() . '. Run `ws_meilisearch:reindex` to push the records to Meilisearch.');
        return Command::SUCCESS;
    }

    /**
     * Build the $config array for the importer from CLI options. Combines
     * shorthand options (--path, --language, …) with generic -f name=value
     * pairs; -f wins when both are given.
     *
     * @return array<string, mixed>
     */
    private function collectConfig(InputInterface $input): array
    {
        $config = [];
        foreach (['path', 'language', 'langDir', 'pid', 'limit'] as $k) {
            $v = $input->getOption($k);
            if ($v !== null && $v !== '') {
                $config[$k] = $v;
            }
        }
        if ($input->getOption('no-purge')) {
            $config['purge'] = false;
        }
        foreach ((array)$input->getOption('field') as $pair) {
            $pair = (string)$pair;
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $config[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }
        return $config;
    }

    private function printImporters(SymfonyStyle $io): void
    {
        foreach ($this->registry->all() as $importer) {
            $io->section($importer->name() . ' — ' . $importer->label());
            $io->writeln($importer->description());
            $rows = [];
            foreach ($importer->describeFields() as $field) {
                $rows[] = [
                    $field['name'],
                    $field['type'],
                    !empty($field['required']) ? 'yes' : 'no',
                    isset($field['default']) ? (is_scalar($field['default']) ? (string)$field['default'] : '…') : '',
                    $field['help'] ?? '',
                ];
            }
            $io->table(['name', 'type', 'required', 'default', 'help'], $rows);
        }
    }
}