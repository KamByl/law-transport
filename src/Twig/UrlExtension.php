<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class UrlExtension extends AbstractExtension
{
    private ?Request $request = null;
    private ?RequestContext $requestContext = null;
    private ?RouteCollection $routes = null;
    private ?UrlGenerator $urlGenerator = null;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('absolute_url', [$this, 'absoluteUrl']),
            new TwigFunction('path', [$this, 'path']),
            new TwigFunction('configured_field_text', [$this, 'configuredFieldText']),
        ];
    }


    /**
     * Builds additional read-only text for configurable module fields.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $values
     */
    public function configuredFieldText(string $provider, array $field = [], array $values = []): string
    {
        return match (trim($provider)) {
            'system_cron_command' => $this->buildSystemCronCommand(),
            default => '',
        };
    }

    private function buildSystemCronCommand(): string
    {
        $baseUrl = 'https://twojadomena.pl';
        if ($this->request instanceof Request) {
            $baseUrl = $this->request->getSchemeAndHttpHost();
        }

        $projectRoot = dirname(__DIR__, 2);

        return sprintf(
            '* * * * * /usr/bin/php %s/bin/cron-run.php --base-url="%s" >> /var/log/cms2-cron.log 2>&1',
            $projectRoot,
            $baseUrl
        );
    }

    public function setRequest(?Request $request): void
    {
        $this->request = $request;

        if ($request instanceof Request) {
            $this->requestContext = (new RequestContext())->fromRequest($request);
        } else {
            $this->requestContext = null;
        }

        $this->urlGenerator = null;
    }

    public function absoluteUrl(string $path): string
    {
        $trimmedPath = trim($path);
        if ($trimmedPath === '') {
            return '';
        }

        if (
            str_starts_with($trimmedPath, 'http://')
            || str_starts_with($trimmedPath, 'https://')
            || str_starts_with($trimmedPath, '//')
        ) {
            return $trimmedPath;
        }

        $normalizedPath = str_starts_with($trimmedPath, '/') ? $trimmedPath : '/' . $trimmedPath;
        $request = $this->request;

        if ($request instanceof Request) {
            return rtrim($request->getSchemeAndHttpHost(), '/') . $normalizedPath;
        }

        $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
        if ($host === '') {
            return $normalizedPath;
        }

        $scheme = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        ) {
            $scheme = 'https';
        }

        return $scheme . '://' . $host . $normalizedPath;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function path(string $routeName, array $parameters = []): string
    {
        if (trim($routeName) === '') {
            return '';
        }

        try {
            return $this->getUrlGenerator()->generate($routeName, $parameters);
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    private function getUrlGenerator(): UrlGenerator
    {
        if ($this->urlGenerator instanceof UrlGenerator) {
            return $this->urlGenerator;
        }

        if (!$this->routes instanceof RouteCollection) {
            /** @var RouteCollection $routes */
            $routes = require dirname(__DIR__, 2) . '/config/routes.php';
            $this->routes = $routes;
        }

        $context = $this->requestContext ?? new RequestContext();
        $this->urlGenerator = new UrlGenerator($this->routes, $context);

        return $this->urlGenerator;
    }
}
