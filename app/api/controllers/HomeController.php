<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use System\Engine\BaseController;
use System\Helpers\Status;

class HomeController extends BaseController
{
    public function index()
    {
        return $this->response()->jsonResponse(Status::OK, []);
    }
}
