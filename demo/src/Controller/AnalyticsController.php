<?php

declare(strict_types=1);

namespace App\Controller;

use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** What was searched in the catalogue during the last seven days, from the search log (fuzzphony.search_log). */
final class AnalyticsController extends AbstractController
{
    #[Route('/under-the-hood/analytics', name: 'analytics')]
    public function analytics(Fuzzphony $fuzzphony): Response
    {
        $from = new \DateTimeImmutable('-7 days');
        $top = $zero = [];
        $error = false;

        try {
            $top = $fuzzphony->analytics()->topQueries('catalog', $from, null, 15);
            $zero = $fuzzphony->analytics()->zeroResultQueries('catalog', $from, null, 15);
        } catch (FuzzphonyException) {
            // A demo database from before 0.9 has no search log table: say how to create it instead of failing.
            $error = true;
        }

        return $this->render('analytics.html.twig', ['top' => $top, 'zero' => $zero, 'error' => $error]);
    }
}
