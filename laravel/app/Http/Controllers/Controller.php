<?php namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use View;
use Config;

abstract class Controller extends BaseController
{
	use DispatchesCommands, ValidatesRequests;

    public function __construct()
    {
        $paths = new \TYPO3Fluid\Fluid\View\TemplatePaths();
        $paths->setTemplateRootPaths(array(
            base_path() . '/resources/Private/Templates/'
        ));
        $paths->setLayoutRootPaths(array(
            base_path() . '/resources/Private/Layouts/'
        ));
        $paths->setPartialRootPaths(array(
            base_path() . '/resources/Private/Partials/'
        ));
        $this->view = new \TYPO3Fluid\Fluid\View\TemplateView($paths);
        $context = $this->view->getRenderingContext();
        $context->setControllerName($this->determineControllerName());
        $this->view->setRenderingContext($context);
    }

    /**
     * Determines the current controller name and passes it to Fluid
     *
     * @return mixed
     */
    protected function determineControllerName()
    {
        $className = get_class($this);
        $namespaceParts = explode('\\', $className);
        $fullControllerName = array_pop($namespaceParts);
        $controllerName = str_replace('Controller', '', $fullControllerName);
        return $controllerName;
    }
}
