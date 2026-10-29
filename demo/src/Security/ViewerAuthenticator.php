<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * "Viewing as": signs the request in as one of the four fixed viewers of DemoUser, the one the `as` parameter names or,
 * without it, the one kept in the session, or Ada for a first visit (the page opens on an answer, not on a refusal). "anonymous"
 * (and anything else) is nobody: the request is served, not signed in. The only thing a visitor can choose is which one; no role or
 * company ever comes from the request.
 */
final class ViewerAuthenticator extends AbstractAuthenticator
{
    private const string SESSION_KEY = 'demo_viewer';
    private const string DEFAULT = 'ada';

    public function supports(Request $request): bool
    {
        return $this->viewer($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge((string) $this->viewer($request)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // go on to the page
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null; // not reachable with a fixed list; the page is served without a viewer
    }

    /**
     * The identifier of the viewer for this request, or null for the anonymous visitor. A valid `as` is remembered in
     * the session (that is how the choice survives the next click); a request without one keeps the remembered choice, and a visitor
     * who has chosen nothing yet is Ada.
     */
    private function viewer(Request $request): ?string
    {
        $asked = $request->query->get('as');
        if (is_string($asked) && ($asked === DemoUser::ANONYMOUS || DemoUser::find($asked) !== null) && $request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $asked);
        } elseif ($request->hasSession() && $request->hasPreviousSession()) {
            $asked = $request->getSession()->get(self::SESSION_KEY, self::DEFAULT);
        } else {
            $asked = self::DEFAULT;
        }

        return is_string($asked) && DemoUser::find($asked) !== null ? $asked : null;
    }
}
