<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\RagAnswer;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\Rag\Turn;

/**
 * Ad-hoc CLI for the RAG pipeline. Useful for verifying provider connectivity
 * and prompt tuning without round-tripping the frontend.
 */
#[AsCommand(
    name: 'ws_meilisearch:ask',
    description: 'Run a RAG question against a site and print the answer + cited sources.',
)]
final class AskCommand extends Command
{
    public function __construct(
        private readonly RagService $ragService,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('question', InputArgument::REQUIRED, 'The question to ask.')
            ->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (defaults to the first configured site).')
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Restrict retrieval to a specific site language id (e.g. 0 for default). '
                . 'Without this, RAG retrieval scans every language — files indexed in N '
                . 'languages then crowd the top-K context with N copies of the same record. '
                . 'Default: 0 (the site default language).',
                '0',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Print the result as one JSON object (status, answer, cited ids, topic, '
                . 'out-of-scope flag, notes, suggestions) — for scripted test runs over a question catalogue.',
            )
            ->addOption(
                'follow-up',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Ask this in the same conversation after the question (repeatable), like a visitor '
                . 'who replies to the answer. Only the last answer is printed; with --json every '
                . 'round is printed as one JSON object per line.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $question = (string)$input->getArgument('question');
        $siteId = $input->getArgument('site');

        $site = $siteId !== null
            ? $this->siteFinder->getSiteByIdentifier($siteId)
            : ($this->siteFinder->getAllSites()[array_key_first($this->siteFinder->getAllSites())] ?? null);
        if ($site === null) {
            $io->error('No site available.');
            return Command::FAILURE;
        }

        $io->section('Site: ' . $site->getIdentifier());
        $io->writeln('<comment>Q:</comment> ' . $question);

        $options = [];
        $languageOption = $input->getOption('language');
        if ($languageOption !== '' && $languageOption !== null) {
            $options['filters'] = ['language' => [(int)$languageOption]];
            // Pin the LLM answer to the same language as the retrieval
            // filter so it doesn't drift to English on multi-language
            // context. Matches the FE controller behaviour.
            $options['language'] = (int)$languageOption;
        }

        // Follow-ups run in one conversation, carried the way the controller
        // carries it between requests, so test catalogues can check how a
        // reply to an answer ("verstehe ich nicht") is handled.
        $questions = array_merge([$question], array_values(array_filter(
            (array)$input->getOption('follow-up'),
            static fn ($q): bool => trim((string)$q) !== '',
        )));
        $conversation = Conversation::empty()->withId();
        $maxTurns = max(1, (int)$site->getSettings()->get('meilisearch.rag.conversation.maxTurns', 3));
        foreach ($questions as $index => $current) {
            $answer = $this->ragService->ask($site, (string)$current, ['internal' => true, 'conversation' => $conversation] + $options);
            if (($answer->status === 'ok' || $answer->status === 'clarify') && $index < count($questions) - 1) {
                $conversation = $conversation->withTurn(new Turn(
                    (string)$current,
                    $answer->answer,
                    $answer->citedIds,
                    $answer->status === 'clarify' ? Turn::KIND_CLARIFICATION : Turn::KIND_ANSWER,
                ), $maxTurns);
            }
            if ($input->getOption('json') && $index < count($questions) - 1) {
                $output->writeln($this->toJson((string)$current, $answer));
            }
            $question = (string)$current;
        }
        if ($input->getOption('json')) {
            $output->writeln($this->toJson($question, $answer));
            return $answer->status === 'failed' ? Command::FAILURE : Command::SUCCESS;
        }
        if ($answer->status === 'clarify') {
            // Triage asked a clarifying question back instead of answering;
            // the question is carried in ->answer. Show it so the test
            // operator sees what the assistant would ask the visitor.
            $io->warning('Status: clarify — the assistant asked for clarification instead of answering');
            $io->writeln('<info>Clarifying question:</info>');
            $io->writeln($answer->answer);
            return Command::SUCCESS;
        }
        if ($answer->status !== 'ok') {
            $io->warning('Status: ' . $answer->status . ($answer->error !== null ? ' — ' . $answer->error : ''));
            return $answer->status === 'failed' ? Command::FAILURE : Command::SUCCESS;
        }

        $io->writeln('');
        $io->writeln('<info>Answer:</info>');
        $io->writeln($answer->answer);
        $io->writeln('');

        if ($answer->sources !== []) {
            $io->writeln('<info>Sources fed into context (cited ' . count($answer->citedIds) . ' of ' . count($answer->sources) . '):</info>');
            foreach ($answer->sources as $hit) {
                $id = (string)($hit['id'] ?? '?');
                $marker = in_array($id, $answer->citedIds, true) ? '✓' : ' ';
                $io->writeln(sprintf('  %s [%s] %s', $marker, $id, (string)($hit['title'] ?? '')));
            }
        }
        return Command::SUCCESS;
    }

    private function toJson(string $question, RagAnswer $answer): string
    {
        return json_encode([
            'question' => $question,
            'status' => $answer->status,
            'error' => $answer->error,
            'answer' => $answer->answer,
            'citedIds' => $answer->citedIds,
            'sources' => array_map(static fn (array $hit): array => [
                'id' => (string)($hit['id'] ?? ''),
                'type' => (string)($hit['type'] ?? ''),
                'title' => (string)($hit['title'] ?? ''),
            ], $answer->sources),
            'topic' => $answer->topic,
            'outOfScope' => $answer->outOfScope,
            'notes' => $answer->notes,
            'suggestions' => $answer->suggestions,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
