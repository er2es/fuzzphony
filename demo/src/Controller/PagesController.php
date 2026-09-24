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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PagesController extends AbstractController
{
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
            'languages' => ['english', 'german', 'french', 'spanish', 'italian', 'hungarian', 'dutch', 'simple'],
            'suggestion' => $suggestion,
            'exports' => $exports,
            'error' => $error,
        ]);
    }

    #[Route('/benchmark', name: 'benchmark')]
    public function benchmark(Fuzzphony $fuzzphony, Catalog $catalog): Response
    {
        $rows = [];
        foreach (CompareController::EXAMPLES + ['plain word' => 'wireless', 'two words' => 'wireless mouse'] as $label => $q) {
            $without = Measure::median(static fn (): array => $catalog->ilike($q));
            $with = Measure::median(static fn () => $fuzzphony->in('catalog')->query($q)->limit(20)->get());
            $rows[] = ['label' => $label, 'q' => $q, 'without' => $without, 'with' => $with];
        }

        return $this->render('benchmark.html.twig', ['rows' => $rows]);
    }

    #[Route('/doctor', name: 'doctor')]
    public function doctor(Request $request, Fuzzphony $fuzzphony): Response
    {
        return $this->render('doctor.html.twig', [
            'report' => $fuzzphony->inspect('catalog', new InspectOptions(deep: $request->query->getBoolean('deep'))),
            'deep' => $request->query->getBoolean('deep'),
        ]);
    }
}
