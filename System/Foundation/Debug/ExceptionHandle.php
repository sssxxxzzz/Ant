<?php
namespace Ant\Foundation\Debug;

use Exception;
use Ant\Http\Response;
use Ant\Http\Exception\HttpException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\Debug\ExceptionHandler as SymfonyExceptionHandler;

/**
 * 异常处理
 *
 * Class ExceptionHandle
 * @package Ant\Foundation\Debug
 */
class ExceptionHandle
{
    /**
     * @param Exception $exception
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param bool|true $debug
     * @return ResponseInterface
     */
    public function render (
        Exception $exception,
        RequestInterface $request,
        ResponseInterface $response,
        $debug = true
    ) {
        $fe = (!$exception instanceof HttpException)
            ? FlattenException::create($exception)
            : FlattenException::create($exception,$exception->getStatusCode(),$exception->getHeaders());

        // 处理异常
        $handler = new SymfonyExceptionHandler($debug);

        // 设置响应码
        $response->withStatus($fe->getStatusCode());

        // 添加响应头
        $headers = $fe->getHeaders();
        $debug && $headers += $this->getExceptionInfo($exception);
        foreach($headers as $name => $value){
            $response->withAddedHeader($name,$value);
        }

        if(false === $result = $this->tryResponseClientAcceptType($exception, $response, $debug)) {
            // 无法返回客户端想要的类型时,默认返回html格式
            $response->getBody()->write(
                $this->decorate($handler->getContent($fe), $handler->getStylesheet($fe))
            );

            $result = $response;
        }

        return $result;
    }

    /**
     * 尝试响应客户端请求的类型
     *
     * @param Exception $e
     * @param ResponseInterface $res
     * @param $debug
     * @return false|ResponseInterface
     */
    protected function tryResponseClientAcceptType (
        Exception $e,
        ResponseInterface $res,
        $debug
    ) {
        if(!$res instanceof Response) {
            return false;
        }

        if($e instanceof HttpException){
            $message = $e->getMessage() ?: $res->getReasonPhrase();
        }else{
            $message = $debug && $e->getMessage() ? $e->getMessage() : 'error';
        }

        try{
            $errorInfo = [
                'code'      =>  $e->getCode(),
                'message'   =>  $message
            ];

            return $res->setContent($errorInfo)->decorate();
        }catch(\Exception $e){
            return false;
        }
    }

    /**
     * 装饰错误信息.
     *
     * @param  string  $content
     * @param  string  $css
     * @return string
     */
    protected function decorate($content, $css)
    {
        return <<<EOF
<!DOCTYPE html>
<html>
    <head>
        <meta name="robots" content="noindex,nofollow" />
        <style>
            $css
        </style>
    </head>
    <body>
        $content
    </body>
</html>
EOF;
    }

    /**
     * 获取错误信息
     *
     * @param $exception
     * @return array
     */
    protected function getExceptionInfo(\Exception $exception)
    {
        if($exception->getPrevious()){
            // 返回异常链中的前一个异常的信息
            return $this->getExceptionInfo($exception->getPrevious());
        }

        $exceptionInfo = [];
        $exceptionInfo['X-Exception-Message'] = $exception->getMessage();

        foreach(explode("\n",$exception->getTraceAsString()) as $index => $line) {
            $key = sprintf('X-Exception-Trace-%02d', $index);
            $exceptionInfo[$key] = $line;
        }

        array_pop($exceptionInfo);

        return $exceptionInfo;
    }
}