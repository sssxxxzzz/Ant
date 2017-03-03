<?php
namespace Ant\Foundation\Cgi;

use Ant\Http\Response;
use Ant\Routing\Router;
use Ant\Http\ServerRequest;
use Ant\Container\Interfaces\ContainerInterface;
use Ant\Container\Interfaces\ServiceProviderInterface;

/**
 * 基础服务提供者
 *
 * Class BaseServiceProvider
 * @package Ant\Foundation\Cgi
 */
class BaseServiceProvider implements ServiceProviderInterface
{
    /**
     * 注册服务
     *
     * @param ContainerInterface $container
     */
    public function register(ContainerInterface $container)
    {
        /**
         * 注册服务容器
         */
        $container->instance('app',$container);

        /**
         * 注册 配置信息信息集
         */
        $container->singleton('config',\Ant\Support\Collection::class);

        /**
         * 注册 Http Request 处理类
         */
        $container->singleton('request',function() {
            return (new ServerRequest)->keepImmutability(false);
        });

        /**
         * 注册 Http Response 类
         */
        $container->singleton('response',function($app) {
            return Response::prepare($app['request']);
        });

        /**
         * 注册 Ant Router 类
         */
        $container->singleton('router',function($app) {
            return new Router($app);
        });

        /**
         * 注册 Debug 对象
         */
        $container->bindIf('debug',\Ant\Foundation\Debug\ExceptionHandle::class);
    }
}