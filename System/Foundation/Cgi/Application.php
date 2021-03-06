<?php
namespace Ant\Foundation\Cgi;

use Ant\Http\Body;
use Ant\Http\Request;
use Ant\Http\Response;
use Ant\Middleware\Pipeline;
use Ant\Container\Container;
use Ant\Support\Traits\Singleton;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ant\Container\Interfaces\ServiceProviderInterface;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Symfony\Component\Debug\Exception\FatalErrorException;

/**
 * Fast-Cgi模式下框架初始化程序
 *
 * Class Application
 * @package Ant\Foundation\Cgi
 */
class Application extends Container
{
    use Singleton;
    /**
     * 加载的中间件
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * 已注册服务提供者
     *
     * @var array
     */
    protected $loadedProviders = [];

    /**
     * 异常处理函数
     *
     * @var callable
     */
    protected $exceptionHandler;

    /**
     * 项目路径
     *
     * @var string
     */
    protected $basePath = '';

    /**
     * 默认配置信息
     *
     * @var array
     */
    protected $defaultConfig = [
        // 响应http头
        'header'    =>  [
            // 超时时间
            'Expires' => [0],
            // 程序支持
            'X-Powered-By' => ['Ant-Framework'],
            // 允许暴露给客户端访问的字段
            'Access-Control-Expose-Headers' => ['*'],
            // 设置跨域信息
            'Access-Control-Allow-Origin' => ['*'],
            // 是否允许携带认证信息
            'Access-Control-Allow-Credentials' => ['false'],
            // 缓存控制
            'Cache-Control' => ['no-cache'],
        ]
    ];

    /**
     * App constructor.
     *
     * @param string $path 项目路径
     */
    public function __construct($path = null)
    {
        $this->basePath = rtrim($path, DIRECTORY_SEPARATOR);
        // 初始化容器
        $this->bootstrapContainer();
        // 注册错误处理
        $this->registerErrorHandler();
        // 加载默认配置
        $this->make('config')->replace($this->defaultConfig);
        // 加载应用程序命名空间
        $this->registerNamespace('App', $this->basePath . DIRECTORY_SEPARATOR . 'App');
    }

    /**
     * 加载配置信息
     *
     * @param $config
     */
    public function loadConfig($config)
    {
        if (is_file($config) && file_exists($config)) {
            $ext = pathinfo($config, PATHINFO_EXTENSION);

            switch (mb_strtolower($ext)) {
                case "json":
                    $config = safeJsonDecode(file_get_contents($config), true);
                    break;
                case "xml":
                    $config = get_object_vars(simplexml_load_file($config));
                    break;
                case "php":
                    $config = require_once $config;
                    break;
            }
        }

        if (!is_array($config)) {
            throw new \RuntimeException("Config load failed");
        }

        $this['config']->replace($config);
    }

    /**
     * 注册服务提供者,如果服务提供者不符合规范将会跳过
     *
     * @param $provider
     */
    public function register($provider)
    {
        if (is_string($provider)) {
            $provider = new $provider;
        }

        if (array_key_exists($providerName = get_class($provider), $this->loadedProviders)) {
            return;
        }

        $this->loadedProviders[$providerName] = true;

        if ($provider instanceof ServiceProviderInterface) {
            $this->registerService($provider);
        }
    }

    /**
     * 初始化应用容器.
     */
    protected function bootstrapContainer()
    {
        static::setInstance($this);
        $this->registerService(new BaseServiceProvider);
        $this->registerContainerAliases();
    }

    /**
     * 注册服务别名
     */
    protected function registerContainerAliases()
    {
        $aliases = [
            \Ant\Foundation\Cgi\Application::class              => 'app',
            \Ant\Container\Container::class                     => 'app',
            \Ant\Container\Interfaces\ContainerInterface::class => 'app',
            \Ant\Routing\Router::class                          => 'router',
            \Psr\Http\Message\ServerRequestInterface::class     => 'request',
            \Ant\Http\Request::class                            => 'request',
            \Psr\Http\Message\ResponseInterface::class          => 'response',
            \Ant\Http\Response::class                           => 'response',
            \Ant\Foundation\Debug\ExceptionHandle::class        => 'debug',
        ];

        foreach ($aliases as $alias => $serviceName) {
            $this->alias($serviceName,$alias);
        }
    }

    /**
     * 注册错误信息
     */
    public function registerErrorHandler()
    {
        error_reporting(E_ALL);

        set_error_handler(function ($level, $message, $file = '', $line = 0) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        register_shutdown_function(function () {
            if (
                !is_null($error = error_get_last())
                && in_array($error['type'],[E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])
            ) {
                // 如果是错误造成的脚本结束,获取错误信息,并将错误包装为异常进行处理
                throw new FatalErrorException(
                    $error['message'], $error['type'], 0, $error['file'], $error['line']
                );
            }
        });

        set_exception_handler(function ($e) {
            $this->end($this->handleUncaughtException($e));
        });
    }

    /**
     * 处理未捕获异常
     *
     * @param $exception
     * @param Request|null $request
     * @param Response|null $response
     * @return Response
     */
    protected function handleUncaughtException($exception, Request $request = null, Response $response = null)
    {
        // 此处是为了兼容PHP7
        // PHP7中错误可以跟异常都实现了Throwable接口
        // 所以错误也会跟异常一起被捕获
        // 此处将捕获到的错误转换成异常
        if ($exception instanceof \Error) {
            $exception = new FatalThrowableError($exception);
        }

        $request = $request ?: $this->make('request');
        $response = $response ?: $this->make('response');

        // 返回异常处理结果
        return $this['debug']->render(
            $exception,
            $request,
            $response->withBody(new Body()),
            $this['config']->get('debug',true)
        );
    }

    /**
     * 注册自定义异常处理方式
     *
     * @param callable $handler
     * @return $this
     */
    public function registerExceptionHandler(callable $handler)
    {
        $this->exceptionHandler = $handler;

        return $this;
    }

    /**
     * 获取异常处理方法
     *
     * @return callable|\Closure
     */
    protected function getExceptionHandler()
    {
        if (is_callable($this->exceptionHandler)) {
            return $this->exceptionHandler;
        }

        // 如果开发者没有处理异常
        // 异常将会交由框架进行处理
        return function ($exception,$request,$response) {
            return $this->handleUncaughtException($exception,$request,$response);
        };
    }

    /**
     * 注册命名空间
     *
     * @param $namespace
     * @param $path
     */
    public function registerNamespace($namespace, $path)
    {
        $namespace = trim($namespace, '\\');
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        spl_autoload_register(function ($className) use ($namespace, $path) {
            // 如果已经存在,直接返回
            if (class_exists($className, false) || interface_exists($className, false)) {
                return true;
            }

            $className = trim($className, '\\');

            // 检查类是否存在于此命名空间之下
            if ($namespace && stripos($className, $namespace) !== 0) {
                return false;
            }

            // 根据命名空间截取出文件路径
            $filename = trim(substr($className, strlen($namespace)), '\\');
            // 拼接路径
            $filename = $path.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $filename).'.php';

            if (!file_exists($filename)) {
                return false;
            }
            // 引入文件
            \Composer\Autoload\includeFile($filename);

            return class_exists($className, false) || interface_exists($className, false);
        });
    }

    /**
     * 添加应用中间件
     *
     * @param callable $middleware
     */
    public function addMiddleware(callable $middleware)
    {
        $this->middleware[] = $middleware;
    }

    /**
     * 启动框架
     */
    public function run()
    {
        $result = $this->process(
            $this->make('request'),
            $this->make('response')
        );

        $this->end($result);
    }

    /**
     * 处理一个请求
     *
     * @param $request
     * @param $response
     * @return \Ant\Http\Response
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response)
    {
        try {
            // 回调应用程序中间件
            $result = $this->sendThroughPipeline([$request, $response], function ($req) {
                $router = $this->make('router');
                // 设置路由基础路径
                if (is_callable([$req, 'getRoutePath']) && method_exists($router, 'setRoutePath')) {
                    $router->setRoutePath($req->getRoutePath());
                }
                // 进行路由匹配,并且回调
                return $router->dispatch(...func_get_args());
            });
        } catch (\Exception $exception) {
            $result = call_user_func($this->getExceptionHandler(), $exception, $request, $response);
        } catch (\Throwable $error) {
            $result = call_user_func($this->getExceptionHandler(), $error, $request, $response);
        }

        return $result;
    }

    /**
     * 发送请求与响应通过中间件到达回调函数
     *
     * @param array $args
     * @param \Closure $then
     * @return mixed
     */
    protected function sendThroughPipeline(array $args, \Closure $then)
    {
        if (count($this->middleware) > 0) {
            return (new Pipeline)
                ->send(...$args)
                ->through($this->middleware)
                ->then($then);
        }

        return $then(...$args);
    }

    /**
     * 向客户端发送数据
     *
     * @param mixed $result
     */
    public function end($result)
    {
        $response = $result;

        if (!$response instanceof ResponseInterface) {
            $response = $this->make(ResponseInterface::class);

            if (!is_string($result) && !is_int($result)) {
                throw new \RuntimeException("Response content must be string");
            }

            $response->write($result);
        }

        $this->sendHeader($response)->sendContent($response);

        if (function_exists("fastcgi_finish_request")) {
            fastcgi_finish_request();
        } elseif ('cli' != PHP_SAPI) {
            $this->closeOutputBuffers(0,true);
        }
    }

    /**
     * 发送头信息
     *
     * @return $this
     */
    protected function sendHeader(Response $response)
    {
        if (!headers_sent()) {
            header(sprintf(
                'HTTP/%s %s %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ));

            foreach ($response->getHeaders() as $name => $value) {
                $name = implode('-',array_map('ucfirst',explode('-',$name)));
                // 输出Http头
                header(sprintf("%s: %s", $name, implode(',', $value)));
            }

            // 写入cookie内容
            foreach ($response->getCookies() as $cookie) {
                if (!is_int($cookie['expires'])) {
                    $cookie['expires'] = (new \DateTime($cookie['expires']))->getTimestamp();
                }

                setcookie(
                    $cookie['name'],
                    $cookie['value'],
                    $cookie['expires'],
                    $cookie['path'],
                    $cookie['domain'],
                    $cookie['secure'],
                    $cookie['httponly']
                );
            }
        }

        return $this;
    }

    /**
     * 发送消息主体
     *
     * @return $this
     */
    protected function sendContent(Response $response)
    {
        if (!$response->isEmpty()) {
            echo (string) $response->getBody();
        }else{
            echo '';
        }

        return $this;
    }

    /**
     * 关闭并输出缓冲区
     *
     * @param $targetLevel
     * @param $flush
     */
    protected function closeOutputBuffers($targetLevel, $flush)
    {
        $status = ob_get_status(true);
        $level = count($status);
        $flags = PHP_OUTPUT_HANDLER_REMOVABLE | ($flush ? PHP_OUTPUT_HANDLER_FLUSHABLE : PHP_OUTPUT_HANDLER_CLEANABLE);

        while (
            $level-- > $targetLevel
            && ($s = $status[$level])
            && (!isset($s['del']) ? !isset($s['flags']) || $flags === ($s['flags'] & $flags) : $s['del'])
        ){
            if ($flush) {
                ob_end_flush();
            } else {
                ob_end_clean();
            }
        }
    }
}