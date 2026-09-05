<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * 415 — the request used a format Close does not accept. Everything this
 * package sends is application/json, so seeing this means the request was
 * built wrongly.
 */
class UnsupportedMediaTypeException extends ResponseException
{
}
