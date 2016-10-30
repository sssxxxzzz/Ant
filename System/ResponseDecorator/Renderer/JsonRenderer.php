<?php
namespace Ant\ResponseDecorator\Renderer;

use Ant\ResponseDecorator\Renderer;
use Psr\Http\Message\ResponseInterface;

class JsonRenderer extends Renderer
{
    public function renderResponse(ResponseInterface $response)
    {
        $response->getBody()->write($this->toJson());
        $response->withAddedHeader('Content-Type', 'application/json;charset=utf-8');

        return $response;
    }

    public function toJson()
    {
        $output = json_encode($this->wrapped);

        if ($output === false && json_last_error() !== JSON_ERROR_NONE) {
            throw new \UnexpectedValueException(json_last_error_msg(), json_last_error());
        }

        return $output;
    }
}