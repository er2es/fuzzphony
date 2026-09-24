<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Wizard\DefinitionSuggester;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Core\Wizard\Export\YamlExporter;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fuzzphony:wizard', description: 'Suggest a search index for a table, explain it, export it and optionally try it right away')]
final class WizardCommand extends Command
{
    public function __construct(
        private readonly SourceIntrospector $introspector,
        private readonly Engine $engine,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::OPTIONAL, 'Source table (asked interactively when omitted)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Index name (default: derived from the table)')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Stemming language: english, hungarian, german, simple, ...', 'english')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'yaml | builder | attributes', 'yaml')
            ->addOption('write', 'w', InputOption::VALUE_REQUIRED, 'Write the output to this file instead of printing it')
            ->addOption('try', null, InputOption::VALUE_NONE, 'Create the index now, reindex, run the doctor and a sample search');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $table = $input->getArgument('table');
        if (!is_string($table) || $table === '') {
            $tables = $this->introspector->tables();
            if ($tables === []) {
                $io->error('No tables found.');

                return Command::FAILURE;
            }
            $choices = array_map(static fn (array $t): string => sprintf('%s (~%s rows)', $t['table'], number_format($t['rows'])), $tables);
            $picked = (string) $io->askQuestion(new ChoiceQuestion('Which table should be searchable?', $choices, 0));
            $table = $tables[(int) array_search($picked, $choices, true)]['table'];
        }

        $profile = $this->introspector->describe($table);
        $suggestion = (new DefinitionSuggester())->suggest($profile, is_string($input->getOption('name')) ? $input->getOption('name') : null, (string) $input->getOption('language'));

        $io->title(sprintf('Suggested index for "%s" (~%s rows)', $table, number_format($profile->estimatedRows)));
        $io->table(['Column', 'Role', 'Why'], array_map(static fn ($d): array => [$d->column, $d->role, $d->reason], $suggestion->decisions));
        foreach ($suggestion->notes as $note) {
            $io->note($note);
        }
        $index = $suggestion->definition;
        if ($index === null) {
            $io->error('No usable index could be suggested; see the notes above.');

            return Command::FAILURE;
        }

        if ($input->isInteractive() && $index->fields !== []) {
            $fields = array_map(static fn ($f): string => $f->name, $index->fields);
            $keep = $io->askQuestion((new ChoiceQuestion('Searchable fields to keep (comma-separated, Enter = all)', $fields, implode(',', array_keys($fields))))->setMultiselect(true));
            $kept = array_values(array_filter($index->fields, static fn ($f): bool => in_array($f->name, (array) $keep, true)));
            if ($kept !== $index->fields && $kept !== []) {
                $index = $index->with(fields: $kept);
            }
        }

        $format = (string) $input->getOption('format');
        $attributes = new AttributeExporter();
        if ($format === 'attributes' && !$attributes->supports($index)) {
            $io->warning('Joined sources cannot be expressed with attributes; showing YAML instead.');
            $format = 'yaml';
        }
        $code = match ($format) {
            'builder' => (new BuilderExporter())->export($index),
            'attributes' => $attributes->export($index, ucfirst((string) preg_replace('/s$/', '', $index->name))),
            default => (new YamlExporter())->export($index),
        };

        $file = $input->getOption('write');
        if (is_string($file)) {
            file_put_contents($file, $code);
            $io->success(sprintf('Written to %s.', $file));
        } else {
            $io->section($format === 'yaml' ? 'config/packages/fuzzphony.yaml' : ucfirst($format));
            $output->writeln($code);
        }

        if ($input->getOption('try') === true) {
            $this->tryIt($io, $index);
        } else {
            $io->writeln('Next: add it to your configuration, then <info>fuzzphony:schema --apply</info>, <info>fuzzphony:reindex</info>, <info>fuzzphony:doctor</info>. Or re-run with <info>--try</info>.');
        }

        return Command::SUCCESS;
    }

    private function tryIt(SymfonyStyle $io, IndexDefinition $index): void
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $io->section('Trying it');
        $fuzzphony->schema()->apply($this->connection);
        $count = $fuzzphony->reindex($index->name, 5_000, static fn (int $done) => $io->write(sprintf("\r  indexed %s", number_format($done))));
        $io->newLine();
        $report = $fuzzphony->inspect($index->name);
        $io->writeln(sprintf('  %s documents, doctor: <%s>%s</>', number_format($count), $report->status() === CheckStatus::Ok ? 'info' : 'comment', $report->status()->value));

        $sample = $io->ask('Try a search (empty to finish)');
        while (is_string($sample) && $sample !== '') {
            $result = $fuzzphony->in($index->name)->query($sample)->limit(5)->get();
            $io->writeln(sprintf('  %d hit(s), %.1f ms%s: ids %s', $result->total, $result->tookMs, $result->usedFuzzy ? ', typo tolerant' : '', implode(', ', $result->ids())));
            $sample = $io->ask('Try a search (empty to finish)');
        }
        $io->note(sprintf('The index "%s" stays in the database. Remove it with: fuzzphony:schema %s --drop --apply (after adding it to the config).', $index->name, $index->name));
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('table')) {
            $suggestions->suggestValues(array_column($this->introspector->tables(), 'table'));
        }
        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues(['yaml', 'builder', 'attributes']);
        }
    }
}
