<?php

declare(strict_types=1);

namespace League\Flysystem\WebDAV;

use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

final class InvalidResponseReceived extends RuntimeException implements FilesystemException
{
    /**
     * @var string
     */
    private $method;

    /**
     * @var string
     */
    private $location;

    public static function propFind(string $location, Throwable $previous): self
    {
        $e = new static("Invalid response from webdav server for propFind request to: {$location}. {$previous->getMessage()}", 0, $previous);
        $e->method = 'propfind';
        $e->location = $location;

        return $e;
    }
}