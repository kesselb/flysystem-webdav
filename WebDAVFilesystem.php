<?php

declare(strict_types=1);

namespace League\Flysystem\WebDAV;

use Generator;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientException;
use Sabre\HTTP\ClientHttpException;
use Sabre\HTTP\Request;
use Sabre\HTTP\ResponseInterface;

class WebDAVFilesystem implements FilesystemAdapter
{
    const DAV_GETCONTENTLENGTH = '{DAV:}getcontentlength';
    const DAV_GETCONTENTTYPE = '{DAV:}getcontenttype';
    const DAV_GETLASTMODIFIED = '{DAV:}getlastmodified';
    const DAV_RESOURCETYPE = '{DAV:}resourcetype';
    const DAV_ISCOLLECTION = '{DAV:}iscollection';

    /** @var array */
    protected $properties = [
        '{DAV:}displayname',
        self::DAV_GETCONTENTLENGTH,
        self::DAV_GETCONTENTTYPE,
        self::DAV_GETLASTMODIFIED,
        self::DAV_ISCOLLECTION,
        self::DAV_RESOURCETYPE,
    ];

    /** @var Client */
    private $client;

    /** @var PathPrefixer */
    private $prefixer;

    public function __construct(Client $client, string $prefix = '/')
    {
        $this->client = $client;
        $this->prefixer = new PathPrefixer($prefix);
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        $response = $this->client->request('HEAD', $this->prefixer->prefixPath($path));

        return $response['statusCode'] === 200;
    }

    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeObject($path, $contents, $config);
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->writeObject($path, $contents, $config);
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        return $this->getObject($path)->getBodyAsString();
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        return $this->getObject($path)->getBodyAsStream();
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        $response = $this->client->request('DELETE', $this->prefixer->prefixPath($path));

        if ($response['statusCode'] !== 204 && $response['statusCode'] !== 404) {
            throw UnableToDeleteFile::atLocation($path, $response['body']);
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        $response = $this->client->request('DELETE', $this->prefixer->prefixPath($path));

        if ($response['statusCode'] !== 204 && $response['statusCode'] !== 404) {
            throw UnableToDeleteDirectory::atLocation($path, $response['body']);
        }
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);

        if ($this->fileExists($location)) {
            return;
        }

        $response = $this->client->request('MKCOL', $location);

        if ($response['statusCode'] !== 201) {
            throw UnableToCreateDirectory::atLocation($path, $response['body']);
        }
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path);
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $path): FileAttributes
    {
        if (!$this->fileExists($path)) {
            throw UnableToRetrieveMetadata::visibility($path);
        }

        return new FileAttributes($path, null, Visibility::PUBLIC);
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->fetchMetadata($path, FileAttributes::ATTRIBUTE_MIME_TYPE);
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->fetchMetadata($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->fetchMetadata($path, FileAttributes::ATTRIBUTE_FILE_SIZE);
    }

    /**
     * @inheritDoc
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $generators = $this->propFind($path, $deep);

        foreach ($generators as $response) {
            foreach ($response as $itemPath => $item) {
                $itemPath = trim($itemPath, '/');

                if ($this->isDirectory($item)) {
                    yield new DirectoryAttributes($itemPath, null);
                } else {
                    yield $this->parseItem($itemPath, $item);
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $sourcePath = $this->prefixer->prefixPath($source);
        $destinationPath = $this->prefixer->prefixPath($destination);

        $response = $this->client->request('MOVE', $sourcePath, null, [
            'Destination' => $this->client->getAbsoluteUrl($destinationPath)
        ]);

        if ($response['statusCode'] !== 201 && $response['statusCode'] !== 204) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $sourcePath = $this->prefixer->prefixPath($source);
        $destinationPath = $this->prefixer->prefixPath($destination);

        $response = $this->client->request('COPY', $sourcePath, null, [
            'Destination' => $this->client->getAbsoluteUrl($destinationPath)
        ]);

        if ($response['statusCode'] !== 201 && $response['statusCode'] !== 204) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * @param string $path
     * @param string|resource $body
     * @param Config $config
     */
    private function writeObject(string $path, $body, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);
        try {
            $this->createDirectory(dirname($location), $config);
        } catch (FilesystemException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        try {
            $response = $this->client->send(new Request('PUT', $this->client->getAbsoluteUrl($location), [], $body));
        } catch (ClientException|ClientHttpException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        if ($response->getStatus() !== 201) {
            throw UnableToWriteFile::atLocation($path, $response->getStatusText());
        }
    }

    /**
     * @param string $path
     * @return ResponseInterface
     */
    private function getObject(string $path): ResponseInterface
    {
        try {
            $response = $this->client->send(new Request('GET', $this->client->getAbsoluteUrl($this->prefixer->prefixPath($path)), [], null));
        } catch (ClientException|ClientHttpException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        if ($response->getStatus() !== 200) {
            throw UnableToReadFile::fromLocation($path, $response->getStatusText());
        }

        return $response;
    }

    /**
     * @param string $path
     * @param bool $deep
     * @return Generator
     */
    private function propFind(string $path, bool $deep): Generator
    {
        $location = $this->prefixer->prefixPath($path);
        $response = $this->client->propFind($location, $this->properties, 1);
        array_shift($response);

        foreach ($response as $itemPath => $item) {
            if ($deep && $this->isDirectory($item)) {
                yield from $this->propFind($itemPath, $deep);
            }
            yield [$itemPath => $item];
        }
    }

    /**
     * @param array $item
     * @return bool
     */
    private function isDirectory(array $item): bool
    {
        return (isset($item[self::DAV_GETCONTENTTYPE]) && $item[self::DAV_GETCONTENTTYPE] === 'httpd/unix-directory')
            || (isset($item[self::DAV_RESOURCETYPE]) && $item[self::DAV_RESOURCETYPE] instanceof ResourceType && $item[self::DAV_RESOURCETYPE]->is('{DAV:}collection'))
            || (isset($item[self::DAV_ISCOLLECTION]) && $item[self::DAV_ISCOLLECTION] === '1');
    }

    /**
     * @param string $path
     * @param array $item
     * @return FileAttributes
     */
    private function parseItem(string $path, array $item): FileAttributes
    {
        if (isset($item[self::DAV_GETCONTENTLENGTH])) {
            $contentLength = (int)$item[self::DAV_GETCONTENTLENGTH];
        } else {
            $contentLength = null;
        }

        if (isset($item[self::DAV_GETLASTMODIFIED])) {
            $lastModified = strtotime($item[self::DAV_GETLASTMODIFIED]);
        } else {
            $lastModified = null;
        }

        $contentType = $item[self::DAV_GETCONTENTTYPE] ?? null;
        $isCollection = $item[self::DAV_ISCOLLECTION] ?? null;

        return new FileAttributes($path, $contentLength, null, $lastModified, $contentType, [
            self::DAV_ISCOLLECTION => $isCollection,
        ]);
    }

    /**
     * @param string $path
     * @param string $type
     * @return FileAttributes
     */
    private function fetchMetadata(string $path, string $type): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $response = $this->client->propFind($location, $this->properties);
        } catch (ClientHttpException $e) {
            throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage(), $e);
        }

        if ($type === FileAttributes::ATTRIBUTE_FILE_SIZE && $this->isDirectory($response)) {
            throw UnableToRetrieveMetadata::create($path, $type);
        }

        return $this->parseItem($path, $response);
    }
}