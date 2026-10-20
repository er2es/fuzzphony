<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Exception\InvalidArgument;
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

/** @internal The fuzzphony:analytics console command; its CLI is public, the class is not. */
#[AsCommand(name: 'fuzzphony:analytics', description: 'Report on the search log: the most searched texts, the ones that found nothing, or who searched what recently')]
final class AnalyticsCommand extends Command
{
    public function __construct(private readonly Fuzzphony $fuzzphony)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('index', InputArgument::REQUIRED, 'Index name')
            ->addOption('top', null, InputOption::VALUE_NONE, 'The most searched texts (the default)')
            ->addOption('zero', null, InputOption::VALUE_NONE, 'The texts that found nothing')
            ->addOption('recent', null, InputOption::VALUE_NONE, 'The most recent searches, newest first, with who searched (personal data: see SECURITY.md)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'With --recent: only the searches of this user')
            ->addOption('anonymous', null, InputOption::VALUE_NONE, 'With --recent: only the searches without a user')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'With --recent: only ok, denied or rate_limited searches')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Window: a number and h (hours), d (days) or w (weeks), e.g. 12h, 7d, 4w', '7d')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows to show (1 to 1000)', '20')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Report one tenant (a tenant-scoped index needs --tenant or --all-tenants)')
            ->addOption('all-tenants', null, InputOption::VALUE_NONE, 'Report across tenants')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $since = Coerce::str($input->getOption('since'));
        $interval = self::interval($since);
        if ($interval === null) {
            $io->error(sprintf('Cannot parse --since "%s"; use a number and h, d or w, e.g. 12h, 7d, 4w.', $since));

            return Command::INVALID;
        }
        $format = Coerce::str($input->getOption('format'));
        if ($format !== 'table' && $format !== 'json') {
            $io->error(sprintf('--format must be table or json, got "%s".', $format));

            return Command::INVALID;
        }
        if ($input->getOption('top') === true && $input->getOption('zero') === true) {
            $io->error('Choose --top or --zero, not both.');

            return Command::INVALID;
        }
        $tenant = $input->getOption('tenant');
        $allTenants = $input->getOption('all-tenants') === true;
        if ($tenant === '' || ($tenant !== null && $allTenants)) {
            $io->error($tenant === '' ? '--tenant needs a value.' : 'Choose --tenant or --all-tenants, not both.');

            return Command::INVALID;
        }
        $recent = $input->getOption('recent') === true;
        $user = $input->getOption('user');
        $anonymous = $input->getOption('anonymous') === true;
        $statusText = $input->getOption('status');
        $status = is_string($statusText) ? RecordStatus::tryFrom($statusText) : null;
        $recentError = match (true) {
            $recent && ($input->getOption('top') === true || $input->getOption('zero') === true) => 'Choose --top, --zero or --recent, not several.',
            !$recent && ($user !== null || $anonymous || $statusText !== null) => '--user, --anonymous and --status go with --recent.',
            $user === '' => '--user needs a value.',
            $user !== null && $anonymous => 'Choose --user or --anonymous, not both.',
            $statusText !== null && $status === null => sprintf('--status must be ok, denied or rate_limited, got "%s".', Coerce::str($statusText)),
            default => null,
        };
        if ($recentError !== null) {
            $io->error($recentError);

            return Command::INVALID;
        }

        try {
            $analytics = $this->fuzzphony->analytics();
            $from = (new \DateTimeImmutable())->sub($interval);
            $index = Coerce::str($input->getArgument('index'));
            $limit = Coerce::int($input->getOption('limit'));
            if ($recent) {
                $searches = $analytics->recentSearches($index, $from, null, $limit, is_string($tenant) ? $tenant : null, $allTenants, is_string($user) ? $user : null, $anonymous, $status);

                return $this->printRecent($io, $output, $searches, $format, $since, $tenant === null);
            }
            // the tenant goes through as the raw string: a digit string stays text (the store compares the text column)
            $stats = $input->getOption('zero') === true
                ? $analytics->zeroResultQueries($index, $from, null, $limit, is_string($tenant) ? $tenant : null, $allTenants)
                : $analytics->topQueries($index, $from, null, $limit, is_string($tenant) ? $tenant : null, $allTenants);
        } catch (InvalidArgument $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln(json_encode(array_map(static fn(QueryStat $s): array => [
                'query' => $s->query,
                'searches' => $s->searches,
                'average_hits' => $s->averageHits,
                'last_seen' => $s->lastSeen->format(\DATE_ATOM),
            ], $stats), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }
        if ($stats === []) {
            $io->writeln(sprintf('<comment>No searches in the last %s.</comment>', $since));

            return Command::SUCCESS;
        }
        $io->table(['query', 'searches', 'avg hits', 'last seen'], array_map(static fn(QueryStat $s): array => [
            OutputFormatter::escape($s->query),
            $s->searches,
            sprintf('%.1f', $s->averageHits),
            self::local($s->lastSeen),
        ], $stats));

        return Command::SUCCESS;
    }

    /**
     * @param list<LoggedSearch> $searches
     * @param bool               $showTenant no tenant was named: the rows may belong to several, so say which
     */
    private function printRecent(SymfonyStyle $io, OutputInterface $output, array $searches, string $format, string $since, bool $showTenant): int
    {
        if ($format === 'json') {
            $output->writeln(json_encode(array_map(static fn(LoggedSearch $s): array => [
                'at' => $s->at->format(\DATE_ATOM),
                'index' => $s->index,
                'tenant' => $s->tenant,
                'user' => $s->user,
                'ip' => $s->ip,
                'query' => $s->query,
                'hits' => $s->hits,
                'took_ms' => $s->tookMs,
                'used_fuzzy' => $s->usedFuzzy,
                'status' => $s->status->value,
            ], $searches), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }
        if ($searches === []) {
            $io->writeln(sprintf('<comment>No searches in the last %s.</comment>', $since));

            return Command::SUCCESS;
        }
        $io->table(
            ['time', ...($showTenant ? ['tenant'] : []), 'user', 'ip', 'status', 'hits', 'query'],
            array_map(static fn(LoggedSearch $s): array => [
                self::local($s->at),
                ...($showTenant ? [self::cell($s->tenant ?? '-')] : []),
                self::cell($s->user ?? '(anonymous)'),
                self::cell($s->ip ?? '-'),
                $s->status->value,
                $s->hits,
                self::cell($s->query),
            ], $searches),
        );

        return Command::SUCCESS;
    }

    /**
     * Text a visitor typed, printed to an operator's terminal: control characters (a newline, an ANSI escape) become U+FFFD,
     * so a search cannot break the table or write to the terminal, and console markup is printed as text.
     */
    private static function cell(string $text): string
    {
        return OutputFormatter::escape((string) preg_replace('/\p{Cc}/u', "\u{FFFD}", mb_scrub($text)));
    }

    /** The database returns the time in its session's zone: show it in PHP's zone, with the offset, so it is unambiguous. */
    private static function local(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s P');
    }

    private static function interval(string $since): ?\DateInterval
    {
        if (preg_match('/^(\d{1,6})([hdw])$/D', $since, $m) !== 1) {
            return null;
        }

        return new \DateInterval(match ($m[2]) {
            'h' => "PT{$m[1]}H",
            'd' => "P{$m[1]}D",
            default => "P{$m[1]}W",
        });
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('index')) {
            $suggestions->suggestValues(IndexArgument::names($this->fuzzphony));
        }
        if ($input->mustSuggestOptionValuesFor('status')) {
            $suggestions->suggestValues(array_map(static fn(RecordStatus $s): string => $s->value, RecordStatus::cases()));
        }
        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues(['table', 'json']);
        }
    }
}
