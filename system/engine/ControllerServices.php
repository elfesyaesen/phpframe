<?php

declare(strict_types=1);

namespace System\Engine;

use System\Config\AppConfig;
use System\Http\Request;
use System\Http\Response;
use Twig\Environment;
use System\Security\Contract\GateInterface;
use System\Translation\Contract\TranslatorInterface;

final readonly class ControllerServices
{
    public function __construct(
        public Request $request,
        public Response $response,
        public Environment $twig,
        public TranslatorInterface $translator,
        public GateInterface $gate,
        public ViewPathRegistry $viewPaths,
        public AppConfig $app,
    ) {}
}
