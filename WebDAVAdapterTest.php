<?php

declare(strict_types=1);

namespace League\Flysystem\WebDAV;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToSetVisibility;
use Sabre\DAV\Client;

class WebDAVAdapterTest extends FilesystemAdapterTestCase
{
    private $client;

    /**
     * @test
     */
    public function setting_visibility(): void
    {
        $this->expectException(UnableToSetVisibility::class);
        parent::setting_visibility();
    }

    /**
     * @test
     */
    public function fetching_the_mime_type_of_an_svg_file(): void
    {
        $this->givenWeHaveAnExistingFile('file.svg', file_get_contents(__DIR__ . '/vendor/league/flysystem-adapter-test-utilities/test_files/flysystem.svg'));

        $mimetype = $this->adapter()->mimeType('file.svg')->mimeType();

        $this->assertEquals('image/svg+xml', $mimetype);
    }


    protected function createFilesystemAdapter(): FilesystemAdapter
    {
        $this->client = new Client([
            'baseUri' => getenv('FLYSYSTEM_WEBDAV_BASEURI') ?: null,
            'userName' => getenv('FLYSYSTEM_WEBDAV_USERNAME') ?: null,
            'password' => getenv('FLYSYSTEM_WEBDAV_PASSWORD') ?: null,
        ]);

        return new WebDAVAdapter($this->client);
    }
}