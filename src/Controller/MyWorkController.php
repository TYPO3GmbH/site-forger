<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\ElasticUtility;
use App\Elastic\UserWorkQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;

/**
 * Personal area: the issues and Gerrit changes of the logged-in user.
 */
#[Route(path: '/my', name: 'my_')]
#[IsGranted('ROLE_USER')]
class MyWorkController extends AbstractController
{
    #[Route(name: 'index')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $email = $user instanceof KeyCloakUser ? (string) $user->getEmail() : '';
        $name = $user instanceof KeyCloakUser ? (string) $user->getDisplayName() : '';
        $includeClosed = 'all' === $request->query->getString('state');

        $query = new UserWorkQuery($email, $name, $includeClosed);
        $reviews = ElasticUtility::getIndexByName('review')->createSearch($query->reviews())->search();
        $issues = ElasticUtility::getIndexByName('issue')->createSearch($query->issues())->search();

        return $this->render('my/index.html.twig', [
            'email' => $email,
            'name' => $name,
            'includeClosed' => $includeClosed,
            'reviews' => $reviews->getResults(),
            'reviewsTotal' => $reviews->getTotalHits(),
            'issues' => $issues->getResults(),
            'issuesTotal' => $issues->getTotalHits(),
        ]);
    }
}
