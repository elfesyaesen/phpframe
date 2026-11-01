<?php

declare(strict_types=1);

namespace System\Engine;

use System\Exceptions\BadRequestException;
use System\Http\Request;
use System\Http\Response;
use Twig\Environment;
use System\Security\Contract\GateInterface;
use System\Translation\Contract\TranslatorInterface;

abstract class BaseController implements ServicesAwareInterface
{
    protected array $data = [];

    private const MODULE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private ?ControllerServices $services = null;

    public function setServices(ControllerServices $services): void
    {
        $this->services = $services;
    }

    private function services(): ControllerServices
    {
        if ($this->services === null) {
            throw new \RuntimeException(
                'ControllerServices set edilmedi: Router, controller\'ı resolve ettikten '
                . 'sonra setServices() çağırmalı.'
            );
        }

        return $this->services;
    }

    protected function request(): Request
    {
        return $this->services()->request;
    }

    protected function response(): Response
    {
        return $this->services()->response;
    }

    protected function twig(): Environment
    {
        return $this->services()->twig;
    }

    protected function translator(): TranslatorInterface
    {
        return $this->services()->translator;
    }

    protected function gate(): GateInterface
    {
        return $this->services()->gate;
    }

    protected function can(string $permission): bool
    {
        return $this->gate()->allows($permission);
    }

    /**
     * @throws \System\Exceptions\ForbiddenException
     */
    protected function authorize(string $permission): void
    {
        $this->gate()->authorize($permission);
    }

    /**
     * @throws BadRequestException
     */
    public function view(string $path, string $file, array $data = []): string
    {
        $services = $this->services();

        $data['APP_URL'] = $services->app->url;
        $path = $this->validateViewPath($path);

        $file = $this->sanitizeViewFile($file);

        $viewPath = $services->app->path(ModuleRegistry::DIR, $path, 'views');

        $services->viewPaths->add($viewPath, $path);

        $twig = $this->twig();
        $template = '@' . $path . '/' . $file . '.twig';

        return $twig->render($template, $data);
    }

    /**
     * @throws BadRequestException
     */
    private function validateViewPath(string $path): string
    {
        $path = trim($path, '/');

        if (!preg_match(self::MODULE_PATTERN, $path)) {
            throw new BadRequestException(
                'Geçersiz view path',
                ['path' => $path]
            );
        }

        return $path;
    }

    private function sanitizeViewFile(string $file): string
    {
        $file = str_replace(["\0", '..'], '', $file);

        $file = str_replace('\\', '/', $file);

        $file = trim($file, '/');

        if (str_ends_with($file, '.twig')) {
            $file = substr($file, 0, -5);
        }

        return $file;
    }
}
