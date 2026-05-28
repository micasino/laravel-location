<?php

namespace Stevebauman\Location\Drivers;

use Exception;
use GeoIp2\Database\Reader;
use GeoIp2\Model\City;
use GeoIp2\Model\Country;
use GeoIp2\WebService\Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use PharData;
use PharFileInfo;
use RecursiveIteratorIterator;
use RuntimeException;
use Stevebauman\Location\Position;
use Stevebauman\Location\Request;

class MaxMind extends Driver implements Updatable
{
    /**
     * Update the MaxMind database.
     */
    public function update(Command $command): void
    {
        $storage = $this->newTemporaryStorage();

        $tarFilePath = $storage->path(
            $tarFileName = 'maxmind.tar.gz'
        );

        $response = Http::withOptions(['sink' => $tarFilePath])->get(
            $this->getDatabaseUrl()
        );

        throw_if(
            $response->failed(),
            new RuntimeException('Failed to download MaxMind database. Response: '.$response->body())
        );

        $archive = new PharData($tarFilePath);

        $file = $this->discoverDatabaseFile($archive);

        $directory = Str::of($file->getPath())->basename();

        $relativePath = implode('/', [$directory, $file->getFilename()]);

        $archive->extractTo($storage->path('/'), $relativePath, true);

        $this->putDatabaseContentsFromFile($storage->path($relativePath));

        $storage->delete($tarFileName);
        $storage->deleteDirectory($directory);
    }

    /**
     * Create a temporary local filesystem instance for database updates.
     */
    protected function newTemporaryStorage(): FilesystemAdapter
    {
        @mkdir(
            $root = storage_path('app/location/maxmind/update'),
            recursive: true
        );

        return Storage::build([
            'driver' => 'local',
            'root' => $root,
        ]);
    }

    /**
     * Write the database file contents to the configured destination.
     */
    protected function putDatabaseContentsFromFile(string $path): void
    {
        $stream = fopen($path, 'r');

        throw_if(
            ! $stream,
            new RuntimeException('Unable to read extracted MaxMind database file.')
        );

        if ($this->getDatabaseDisk()) {
            Storage::disk($this->getDatabaseDisk())
                ->put($this->getDatabaseDiskPath(), $stream);

            rewind($stream);

            $this->writeStreamToPath($stream, $this->getDatabaseCachePath());

            fclose($stream);

            return;
        }

        $this->writeStreamToPath($stream, $this->getDatabasePath());

        fclose($stream);
    }

    /**
     * Write stream contents to a local file path.
     */
    protected function writeStreamToPath($stream, string $path): void
    {
        $directory = dirname($path);

        @mkdir($directory, recursive: true);

        $temporaryPath = tempnam($directory, 'maxmind_');

        throw_if(
            $temporaryPath === false,
            new RuntimeException(sprintf('Unable to create temporary file for MaxMind database path [%s].', $path))
        );

        $temporaryStream = fopen($temporaryPath, 'w+b');

        throw_if(
            ! $temporaryStream,
            new RuntimeException(sprintf('Unable to write temporary MaxMind database file [%s].', $temporaryPath))
        );

        stream_copy_to_stream($stream, $temporaryStream);

        fflush($temporaryStream);
        fclose($temporaryStream);

        rename($temporaryPath, $path);
    }

    /**
     * Attempt to discover the database file inside the archive.
     *
     * @throws Exception
     */
    protected function discoverDatabaseFile(PharData $archive): PharFileInfo
    {
        foreach (new RecursiveIteratorIterator($archive) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'mmdb') {
                return $file;
            }
        }

        throw new Exception('Unable to locate database file inside of MaxMind archive.');
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrate(Position $position, Fluent $location): Position
    {
        $position->countryName = $location->country;
        $position->countryCode = $location->country_code;
        $position->isoCode = $location->country_code;
        $position->regionCode = $location->regionCode;
        $position->regionName = $location->regionName;
        $position->cityName = $location->city;
        $position->postalCode = $location->postal;
        $position->metroCode = $location->metro_code;
        $position->timezone = $location->timezone;
        $position->latitude = $location->latitude;
        $position->longitude = $location->longitude;

        return $position;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(Request $request): Fluent|false
    {
        return rescue(function () use ($request) {
            $location = $this->fetchLocation($request->getIp());

            if ($location instanceof City) {
                return new Fluent([
                    'country' => $location->country->name,
                    'country_code' => $location->country->isoCode,
                    'city' => $location->city->name,
                    'regionCode' => $location->mostSpecificSubdivision->isoCode,
                    'regionName' => $location->mostSpecificSubdivision->name,
                    'postal' => $location->postal->code,
                    'timezone' => $location->location->timeZone,
                    'latitude' => (string) $location->location->latitude,
                    'longitude' => (string) $location->location->longitude,
                    'metro_code' => (string) $location->location->metroCode,
                ]);
            }

            return new Fluent([
                'country' => $location->country->name,
                'country_code' => $location->country->isoCode,
            ]);
        }, false, false);
    }

    /**
     * Attempt to fetch the location model from Maxmind.
     *
     * @throws Exception
     */
    protected function fetchLocation(string $ip): City|Country
    {
        $maxmind = $this->isWebServiceEnabled()
            ? $this->newClient($this->getUserId(), $this->getLicenseKey(), $this->getLocales(), $this->getOptions())
            : $this->newReader($this->getDatabaseFilePathForReader());

        if ($this->isWebServiceEnabled() || $this->getLocationType() === 'city') {
            return $maxmind->city($ip);
        }

        return $maxmind->country($ip);
    }

    /**
     * Get the database file path for the MaxMind reader.
     * If using a custom disk, mirrors the file to a persistent local cache once and reuses it.
     *
     * @throws Exception
     */
    protected function getDatabaseFilePathForReader(): string
    {
        if (! $this->getDatabaseDisk()) {
            return $this->getDatabasePath();
        }

        $cachePath = $this->getDatabaseCachePath();

        if (is_readable($cachePath)) {
            return $cachePath;
        }

        $lock = Cache::lock('location-maxmind-database-cache-'.$this->getDatabaseDisk(), 30);

        $lock->block(30);

        try {
            // Re-check after acquiring the lock in case another process populated the cache while we waited.
            if (is_readable($cachePath)) {
                return $cachePath;
            }

            $disk = Storage::disk($this->getDatabaseDisk());
            $diskPath = $this->getDatabaseDiskPath();

            if (! $disk->exists($diskPath)) {
                throw new Exception(sprintf('MaxMind database file not found on disk [%s] at path [%s].', $this->getDatabaseDisk(), $diskPath));
            }

            $stream = $disk->readStream($diskPath);

            if (! $stream) {
                throw new Exception(sprintf('Unable to read MaxMind database file from disk [%s] at path [%s].', $this->getDatabaseDisk(), $diskPath));
            }

            $this->writeStreamToPath($stream, $cachePath);

            fclose($stream);
        } finally {
            $lock->release();
        }

        return $cachePath;
    }

    /**
     * Get the persistent local cache path for databases stored on custom disks.
     */
    protected function getDatabaseCachePath(): string
    {
        if (! $this->getDatabaseDisk()) {
            return $this->getDatabasePath();
        }

        $filename = pathinfo($this->getDatabaseDiskPath(), PATHINFO_FILENAME) ?: 'GeoLite2-City';

        return storage_path(
            sprintf(
                'app/location/maxmind/cache/%s-%s.mmdb',
                $filename,
                md5($this->getDatabaseDisk().'|'.$this->getDatabaseDiskPath())
            )
        );
    }

    /**
     * Get a new MaxMind web service client.
     */
    protected function newClient(string|int $userId, string $licenseKey, array $locales, array $options = []): Client
    {
        return new Client((int) $userId, $licenseKey, $locales, $options);
    }

    /**
     * Get a new MaxMind reader client.
     */
    protected function newReader(string $path): Reader
    {
        return new Reader($path);
    }

    /**
     * Determine if the MaxMind web service is enabled.
     */
    protected function isWebServiceEnabled(): bool
    {
        return (bool) config('location.maxmind.web.enabled', false);
    }

    /**
     * Get the configured MaxMind web user ID.
     */
    protected function getUserId(): string|int
    {
        return config('location.maxmind.web.user_id');
    }

    /**
     * Get the configured MaxMind web license key.
     */
    protected function getLicenseKey(): string
    {
        return config('location.maxmind.license_key', config('location.maxmind.web.license_key'));
    }

    /**
     * Get the configured MaxMind web option array.
     */
    protected function getOptions(): array
    {
        return config('location.maxmind.web.options', []);
    }

    /**
     * Get the configured MaxMind web locales array.
     */
    protected function getLocales(): array
    {
        return config('location.maxmind.web.locales', ['en']);
    }

    /**
     * Get the MaxMind database file path.
     */
    protected function getDatabasePath(): string
    {
        return config('location.maxmind.local.path', database_path('maxmind/GeoLite2-City.mmdb'));
    }

    /**
     * Get the MaxMind database filesystem disk.
     */
    protected function getDatabaseDisk(): ?string
    {
        return config('location.maxmind.local.disk');
    }

    /**
     * Get the MaxMind database filesystem path for the configured disk.
     */
    protected function getDatabaseDiskPath(): string
    {
        return ltrim($this->getDatabasePath(), '/');
    }

    /**
     * Get the database URL to download.
     */
    protected function getDatabaseUrl(): string
    {
        return config(
            'location.maxmind.local.url',
            sprintf('https://download.maxmind.com/app/geoip_download_by_token?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz', $this->getLicenseKey()),
        );
    }

    /**
     * Get the MaxMind location type.
     */
    protected function getLocationType(): string
    {
        return config('location.maxmind.local.type', 'city');
    }
}
