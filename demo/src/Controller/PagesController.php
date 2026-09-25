<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Measure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\InspectOptions;
use Fuzzphony\Core\Wizard\DefinitionSuggester;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Core\Wizard\Export\YamlExporter;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PagesController extends AbstractController
{
    private const array LANGUAGES = ['english', 'german', 'french', 'spanish', 'italian', 'hungarian', 'dutch', 'simple'];

    #[Route('/playground', name: 'playground')]
    public function playground(): Response
    {
        return $this->render('playground.html.twig');
    }

    #[Route('/wizard', name: 'wizard')]
    public function wizard(Request $request, SourceIntrospector $introspector): Response
    {
        $tables = $introspector->tables();
        $table = (string) $request->query->get('table', $tables[0]['table'] ?? '');
        $language = (string) $request->query->get('language', 'english');
        // Only the introspector's own list (user tables, never pg_catalog or other system relations) is described.
        if ($table !== '' && !in_array($table, array_column($tables, 'table'), true)) {
            throw $this->createNotFoundException(sprintf('Unknown table "%s".', $table));
        }
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new BadRequestHttpException(sprintf('Unknown language "%s".', $language));
        }
        $suggestion = $exports = null;
        $error = null;

        if ($table !== '') {
            try {
                $suggestion = (new DefinitionSuggester())->suggest($introspector->describe($table), null, $language);
                if ($suggestion->definition !== null) {
                    $attributes = new AttributeExporter();
                    $exports = [
                        'yaml' => (new YamlExporter())->export($suggestion->definition),
                        'builder' => (new BuilderExporter())->export($suggestion->definition),
                        'attributes' => $attributes->supports($suggestion->definition)
                            ? $attributes->export($suggestion->definition)
                            : '// Joined sources cannot be expressed with attributes; use the YAML or builder version.',
                    ];
                }
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('wizard.html.twig', [
            'tables' => $tables,
            'table' => $table,
            'language' => $language,
            'languages' => self::LANGUAGES,
            'suggestion' => $suggestion,
            'exports' => $exports,
            'error' => $error,
        ]);
    }

    /** The page shell is instant; every row is measured by its own request (see sequence_controller.js). */
    #[Route('/benchmark', name: 'benchmark')]
    public function benchmark(): Response
    {
        return $this->render('benchmark.html.twig', ['queries' => self::benchmarkQueries()]);
    }

    /** One benchmark row, requested one at a time so the measurements never compete with each other. */
    #[Route('/benchmark/row/{index}', name: 'benchmark_row', requirements: ['index' => '\d+'])]
    public function benchmarkRow(int $index, Fuzzphony $fuzzphony, Catalog $catalog): Response
    {
        $entry = array_slice(self::benchmarkQueries(), $index, 1, true);
        if ($entry === []) {
            throw $this->createNotFoundException();
        }
        $label = (string) array_key_first($entry);
        $q = $entry[$label];
        $without = Measure::median(static fn (): array => $catalog->ilike($q));
        $with = Measure::median(static fn () => $fuzzphony->in('catalog')->query($q)->limit(20)->get());

        return $this->render('benchmark_row.html.twig', ['r' => ['label' => $label, 'q' => $q, 'without' => $without, 'with' => $with]]);
    }

    /** @return array<string, string> label => query */
    private static function benchmarkQueries(): array
    {
        return CompareController::EXAMPLES + ['plain word' => 'wireless', 'two words' => 'wireless mouse'];
    }

    /** Exact counts (?deep=1) scan the whole catalogue, so they are only offered when DEMO_ALLOW_DEEP_DOCTOR=1. */
    #[Route('/doctor', name: 'doctor')]
    public function doctor(
        Request $request,
        Fuzzphony $fuzzphony,
        #[Autowire('%env(bool:DEMO_ALLOW_DEEP_DOCTOR)%')] bool $deepAllowed,
    ): Response {
        $deep = $deepAllowed && $request->query->getBoolean('deep');

        return $this->render('doctor.html.twig', [
            'report' => $fuzzphony->inspect('catalog', new InspectOptions(deep: $deep)),
            'deep' => $deep,
            'deep_allowed' => $deepAllowed,
            'deep_refused' => !$deepAllowed && $request->query->getBoolean('deep'),
        ]);
    }
}
