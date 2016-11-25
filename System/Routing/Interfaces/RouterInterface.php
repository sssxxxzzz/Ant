<?php
namespace Ant\Routing\Interfaces;

use Ant\Http\Request;
use Ant\Http\Response;

/**
 * 路由器接口类
 *
 * Interface RouterInterface
 * @package Ant\Interfaces\Router
 */
Interface RouterInterface
{
    /**
     * 创建一组路由,共用路由属性
     *
     * @param array $attributes
     * @param \Closure $action
     */
    public function group(array $attributes,\Closure $action);

    /**
     * 创建一条路由映射
     *
     * @param $method
     * @param $uri
     * @param $action
     */
    public function map($method,$uri,$action);

    /**
     * 路由分发
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function dispatch(Request $request, Response $response);
}