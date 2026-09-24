<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Support\Coerce;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fuzzphony:search', description: 'Try a search from the terminal and see why each hit ranks where it does')]
final class SearchCommand extends Command
{
    public function __construct(private readonly Fuzzphony $fuzzphony)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('index', InputArgument::REQUIRED, 'Index name')
            ->addArgument('query', InputArgument::OPTIONAL, 'Search text, e.g. \'wireless mouse -cable\'', '')
            ->addOption('where', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter as "name<op>value", e.g. -w "price<=20000" -w "in_stock=true"')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Ranking profile', 'default')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Hits to show', '10')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override a threshold, e.g. --threshold fuzzy_mode=always')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Print the SQL and the query plan')
            ->addOption('analyze', null, InputOption::VALUE_NONE, 'With --explain: EXPLAIN ANALYZE (executes the query)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $search = $this->fuzzphony->in(Coerce::str($input->getArgument('index')))
            ->query(Coerce::str($input->getArgument('query')))
            ->profile(Coerce::str($input->getOption('profile')))
            ->limit(max(1, Coerce::int($input->getOption('limit'))));

        foreach ((array) $input->getOption('where') as $where) {
            $where = Coerce::str($where);
            if (preg_match('/^([a-z_][a-z0-9_]*)\s*(<=|>=|!=|=|<|>)\s*(.*)$/', $where, $m) !== 1) {
                $io->error(sprintf('Cannot parse filter "%s"; use name<op>value.', $where));

                return Command::INVALID;
            }
            $value = match (strtolower($m[3])) {
                'true' => true,
                'false' => false,
                default => $m[3],
            };
            $search = $search->where($m[1], $m[2], $value);
        }
        $overrides = [];
        foreach ((array) $input->getOption('threshold') as $pair) {
            [$key, $value] = array_pad(explode('=', Coerce::str($pair), 2), 2, '');
            $overrides[$key] = is_numeric($value) ? $value + 0 : $value;
        }
        if ($overrides !== []) {
            $search = $search->thresholds($overrides);
        }

        $explain = $input->getOption('explain') === true;
        $explanation = $explain ? $search->explain($input->getOption('analyze') === true) : null;
        $result = $explanation !== null ? $explanation->result : $search->get();

        $io->writeln(sprintf(
            'Interpreted as <info>%s</info> · %s%s hit(s) · %.1f ms%s',
            $result->interpretedAs ?? '(no text: browsing)',
            number_format($result->total),
            $result->totalIsLowerBound ? '+' : '',
            $result->tookMs,
            $result->usedFuzzy ? ' · typo-tolerant' : '',
        ));
        foreach ($result->warnings as $warning) {
            $io->writeln(' <comment>!</comment> ' . $warning);
        }

        $rows = [];
        foreach ($result->hits as $hit) {
            $b = $hit->breakdown;
            $rows[] = [$hit->id, sprintf('%.3f', $hit->score), sprintf('%.3f', $b->textRank), sprintf('%.3f', $b->fuzzySimilarity), sprintf('%.2f', $b->exactBonus + $b->prefixBonus), sprintf('%.3f', $b->boostBonus), sprintf('%.3f', $b->recencyBonus)];
        }
        $io->table(['id', 'score', 'text', 'fuzzy', 'exact/prefix', 'boost', 'recency'], $rows);

        if ($explanation !== null) {
            foreach ($explanation->statements as $statement) {
                $io->section('SQL: ' . $statement['label']);
                $io->writeln($statement['sql']);
                $io->writeln('params: ' . json_encode($statement['params'], JSON_UNESCAPED_UNICODE));
            }
            $io->section('Plan');
            $io->writeln($explanation->plan);
        }

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('index')) {
            $suggestions->suggestValues(IndexArgument::names($this->fuzzphony));
        }
    }
}
