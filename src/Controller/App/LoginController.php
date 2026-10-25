<?php

namespace App\Controller\App;

use App\Service\InstallationSeeder;
use App\Service\ModuleDefinitionLoader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends AppBaseController
{
    public function login(Request $request): Response
    {
        $next = $this->safeReturnPath($request->query->get('next', $request->request->get('next')), '/');
        if ($this->authService->user() !== null) {
            return new RedirectResponse($next);
        }

        $error = null;
        $status = Response::HTTP_OK;
        $email = '';

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            $password = (string) $request->request->get('password');
            $ip = $request->getClientIp();

            $throttle = $this->authService->getLoginThrottle($email, $ip);
            if ($throttle['isBlocked']) {
                $status = Response::HTTP_TOO_MANY_REQUESTS;
                $error = sprintf('Zbyt wiele nieudanych prób. Spróbuj ponownie za około %d min.', max(1, (int) ceil($throttle['retryAfter'] / 60)));
            } elseif ($this->authService->attemptLogin($email, $password, $ip, $request->headers->get('User-Agent'))) {
                (new InstallationSeeder($this->entityManager, new ModuleDefinitionLoader()))->seed();

                return new RedirectResponse($next);
            } else {
                $error = 'Niepoprawny e-mail lub hasło.';
            }
        }

        return $this->renderApp('app/login.html.twig', [
            'error' => $error,
            'email' => $email,
            'next' => $next,
        ], $status);
    }

    public function logout(): Response
    {
        $this->authService->logout();

        return new RedirectResponse('/logowanie');
    }
}
