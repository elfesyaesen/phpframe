<?php
namespace Api\Controllers;

class HomeController extends \System\Engine\BaseController
{
    public function index(): void
    {
        require_once APP_ROOT . '/public/swagger.php';
    }
}
