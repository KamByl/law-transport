<?php

namespace App\Controller;

use App\Service\InstallationSeeder;
use App\Service\ModuleDefinitionLoader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends BaseController
{
    public function login(Request $request): Response
    {
        $error = null;
        $statusCode = Response::HTTP_OK;
        $retryAfter = null;

        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email');
            $password = (string) $request->request->get('password');
            $ipAddress = $request->getClientIp();
            $userAgent = $request->headers->get('User-Agent');

            $throttle = $this->authService->getLoginThrottle($email, $ipAddress);
            if ($throttle['isBlocked']) {
                $statusCode = Response::HTTP_TOO_MANY_REQUESTS;
                $retryAfter = $throttle['retryAfter'];
                $error = sprintf('Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za około %d minut.', max(1, (int) ceil($retryAfter / 60)));
            } elseif ($this->authService->attemptLogin($email, $password, $ipAddress, $userAgent)) {
                $definitionLoader = new ModuleDefinitionLoader();
                $seeder = new InstallationSeeder($this->entityManager, $definitionLoader);
                $seeder->seed();

                return new RedirectResponse('/cms');
            } else {
                $postFailureThrottle = $this->authService->getLoginThrottle($email, $ipAddress);
                if ($postFailureThrottle['isBlocked']) {
                    $statusCode = Response::HTTP_TOO_MANY_REQUESTS;
                    $retryAfter = $postFailureThrottle['retryAfter'];
                    $error = sprintf('Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za około %d minut.', max(1, (int) ceil($retryAfter / 60)));
                } else {
                    $error = 'Niepoprawny login lub hasło.';
                }
            }
        }

        $response = $this->render('backend/login.html.twig', [
            'error' => $error,
        ], $statusCode);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    public function logout(): Response
    {
        $this->authService->logout();

        return new RedirectResponse('/cms/login');
    }
}
