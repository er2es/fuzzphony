<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Support\Coerce;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal The fuzzphony:search console command; its CLI is public, the class is not. */
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
            // Long name can't be "profile": Symfony's own console Application (since it added
            // "--profile" as a global run-profiling flag) already reserves that name and throws
            // "An option named 'profile' already exists" the moment this command actually runs
            // through a real FrameworkBundle console app (a bare CommandTester never surfaces it).
            ->addOption('rank-profile', 'p', InputOption::VALUE_REQUIRED, 'Ranking profile', 'default')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Hits to show', '10')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override a threshold, e.g. --threshold fuzzy_mode=always')
            ->addOption('facet', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Count the values of a filter among the matches, e.g. -f in_stock -f category_id')
            ->addOption('exact', null, InputOption::VALUE_NONE, 'Count every match (an exact total and exact facets), not only the candidates: can be slow on a large index')
            ->addOption('as-you-type', null, InputOption::VALUE_NONE, 'Search-as-you-type: the last word also matches as the beginning of a longer one')
            ->addOption('suggest', null, InputOption::VALUE_NONE, 'Only complete the last word of the query from the vocabulary of the index (search-as-you-type)')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Print the SQL and the query plan')
            ->addOption('analyze', null, InputOption::VALUE_NONE, 'With --explain: EXPLAIN ANALYZE (executes the query)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('suggest') === true) {
            $completions = $this->fuzzphony->suggest(Coerce::str($input->getArgument('index')), Coerce::str($input->getArgument('query')), max(1, min(20, Coerce::int($input->getOption('limit')))));
            $io->writeln($completions === [] ? '<comment>No completions.</comment>' : array_map(static fn(string $c): string => OutputFormatter::escape($c), $completions));

            return Command::SUCCESS;
        }
        $search = $this->fuzzphony->in(Coerce::str($input->getArgument('index')))
            ->query(Coerce::str($input->getArgument('query')))
            ->profile(Coerce::str($input->getOption('rank-profile')))
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
            // "null" goes back to the default (fuzzy_similarity: by word length)
            $overrides[$key] = strtolower($value) === 'null' ? null : (is_numeric($value) ? $value + 0 : $value);
        }
        if ($overrides !== []) {
            $search = $search->thresholds($overrides);
        }
        $facets = array_map(Coerce::str(...), (array) $input->getOption('facet'));
        if ($facets !== []) {
            $search = $search->facets(...$facets);
        }
        if ($input->getOption('as-you-type') === true) {
            $search = $search->asYouType();
        }
        if ($input->getOption('exact') === true) {
            $search = $search->exactCounts();
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
            $io->writeln(' <comment>!</comment> ' . OutputFormatter::escape($warning));
        }
        if ($result->didYouMean !== null) {
            $io->writeln(sprintf(' Did you mean <info>%s</info>?', OutputFormatter::escape($result->didYouMean)));
        }

        $rows = [];
        foreach ($result->hits as $hit) {
            $b = $hit->breakdown;
            $rows[] = [$hit->id, sprintf('%.3f', $hit->score), sprintf('%.3f', $b->textRank), sprintf('%.3f', $b->fuzzySimilarity), sprintf('%.2f', $b->exactBonus + $b->prefixBonus), sprintf('%.3f', $b->boostBonus), sprintf('%.3f', $b->recencyBonus)];
        }
        $io->table(['id', 'score', 'text', 'fuzzy', 'exact/prefix', 'boost', 'recency'], $rows);

        foreach ($result->facets as $name => $values) {
            $io->writeln(sprintf('<info>%s</info>: %s', $name, $values === [] ? '(no values)' : implode(', ', array_map(
                static fn(\Fuzzphony\Core\Search\FacetValue $v): string => sprintf('%s (%d)', OutputFormatter::escape(match (true) {
                    $v->value === null => '(none)',
                    is_bool($v->value) => $v->value ? 'true' : 'false',
                    default => (string) $v->value,
                }), $v->count),
                $values,
            ))));
        }

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
