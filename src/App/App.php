<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types=1);

namespace Cawa\App;

use Cawa\Core\DI;
use Cawa\Error\Handler as ErrorHandler;
use Cawa\Events\DispatcherFactory;
use Cawa\Events\Event;
use Cawa\Http\ServerRequest;
use Cawa\Http\ServerResponse;
use Cawa\Log\Output\StdErr;
use Cawa\Router\Router;
use Psr\Log\LogLevel;

class App
{
    use DispatcherFactory;

    /**
     * @var bool
     */
    private $init = false;

    /**
     * @return bool
     */
    public static function isInit() : bool
    {
        return self::$instance && self::$instance->init;
    }

    /**
     * @var App
     */
    private static $instance;

    /**
     * @return App
     */
    public static function instance()
    {
        if (!self::$instance) {
            throw new \LogicException('App is not created');
        }

        return self::$instance;
    }
    /**
     * @param string $appRoot
     * @param ServerRequest|null $request
     *
     * @return static
     */
    public static function create(string $appRoot, ServerRequest $request = null) : self
    {
        if (self::$instance) {
            throw new \LogicException('App is already created');
        }

        self::$instance = new static($appRoot, $request);

        return self::$instance;
    }

    /**
     * @var string
     */
    private $appRoot;

    /**
     * @return string
     */
    public static function getAppRoot() : string
    {
        return self::$instance->appRoot;
    }

    /**
     * Environnement development
     */
    const DEV = 'development';

    /**
     * Environnement production
     */
    const PROD = 'production';

    /**
     * Environnement testing
     */
    const TEST = 'testing';

    /**
     * @var string
     */
    private $env = 'production';

    /**
     * @return string
     */
    public static function env() : string
    {
        return self::$instance->env;
    }

    /**
     * @var Router
     */
    private $router;

    /**
     * @return Router
     */
    public static function router() : Router
    {
        return self::$instance->router;
    }

    /**
     * @var ServerRequest
     */
    public $request;

    /**
     * @return ServerRequest
     */
    public static function request() : ServerRequest
    {
        return self::$instance->request;
    }

    /**
     * @var ServerResponse
     */
    public $response;

    /**
     * @return ServerResponse
     */
    public static function response() : ServerResponse
    {
        return self::$instance->response;
    }

    /**
     * App constructor.
     *
     * @param string $appRoot
     */
    private function __construct(string $appRoot)
    {
        self::$instance = $this;

        $this->appRoot = $appRoot;
        $this->env = getenv('APP_ENV') ? getenv('APP_ENV') : self::DEV;

        ErrorHandler::register();

        ob_start();

        $this->router = new Router();
    }

    /**
     * Load config / route & request
     *
     * @param ServerRequest|null $request
     */
    public function init(ServerRequest $request = null)
    {
        if ($this->init == true) {
            throw new \LogicException("Can't reinit App");
        }

        if ($request === null) {
            $request = ServerRequest::createFromGlobals();
        }

        $this->request = $request;

        if (file_exists($this->appRoot . '/config/config.php')) {
            DI::config()->add(require $this->appRoot . '/config/config.php');
        }

        $this->addLoggerListeners();

        $this->response = new ServerResponse();

        if (file_exists($this->appRoot . '/config/route.php')) {
            $this->router->addRoutes(require $this->appRoot . '/config/route.php');
        }

        if (file_exists($this->appRoot . '/config/uri.php')) {
            $this->router->addUris(require $this->appRoot . '/config/uri.php');
        }

        $this->init = true;
    }

    /**
     * @return bool
     */
    private function addLoggerListeners() : bool
    {
        $loggers = DI::config()->getIfExists('logger');

        // StdErr default logger
        $logger = new StdErr();
        $logger->setMinimumLevel(LogLevel::WARNING);
        $loggers[] = $logger;

        if (!is_array($loggers)) {
            throw new \InvalidArgumentException(
                sprintf("Invalid logger configuration, expected array got '%s'", gettype($loggers))
            );
        }

        foreach ($loggers as $logger) {
            self::dispatcher()->addListenerByClass('Cawa\\Log\\Event', [$logger, 'receive']);
        }

        return !$loggers;
    }

    /**
     * @var Module[]
     */
    private $modules = [];

    /**
     * @param Module $module
     */
    public function registerModule(Module $module)
    {
        if ($module->init()) {
            $this->modules[] = $module;
        }
    }

    /**
     * @param string $class
     *
     * @throws \InvalidArgumentException
     *
     * @return Module
     */
    public function getModule(string $class) : Module
    {
        foreach ($this->modules as $module) {
            if ($module instanceof $class) {
                return $module;
            }
        }

        throw new \InvalidArgumentException("No register module modules with class '%s'", get_class($class));
    }

    /**
     * @return void
     */
    public function handle()
    {
        $retturn = $this->router->handle();

        // hack to display trace on development env
        $debug = (App::env() == App::DEV && ob_get_length() > 0);

        if ($retturn instanceof \SimpleXMLElement) {
            if ($debug == false) {
                $this->response->addHeaderIfNotExist('Content-Type', 'text/xml; charset=utf-8');
            }

            $this->response->setBody($retturn->asXML());
        }
        if (gettype($retturn) == 'array') {
            if ($debug == false) {
                $this->response->addHeaderIfNotExist('Content-Type', 'application/json; charset=utf-8');
            }

            $this->response->setBody(json_encode($retturn));
        } else {
            $this->response->addHeaderIfNotExist('Content-Type', 'text/html; charset=utf-8');
            $this->response->setBody($retturn);
        }
    }

    /**
     * @return void
     */
    public static function end()
    {
        self::dispatcher()->emit(new Event('app.end'));

        echo self::instance()->response()->send();

        $exitCode = self::instance()->response()->getStatus() >= 500 &&
            self::instance()->response()->getStatus() < 600 ? 1 : 0;

        exit($exitCode);
    }
}
