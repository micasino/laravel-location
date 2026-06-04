<?php

namespace Stevebauman\Location\Tests\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Fluent;
use Mockery as m;
use Stevebauman\Location\Commands\Update;
use Stevebauman\Location\Drivers\MaxMind;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;

it('can update database', function () {
    config([
        'location.maxmind.license_key' => '123',
        'location.maxmind.local.url' => 'http://example.com',
    ]);

    Http::fake([
        'http://example.com' => Http::response(file_get_contents(__DIR__.'/../fixtures/maxmind.tar.gz')),
    ]);

    $this->artisan(Update::class)->assertSuccessful();

    expect(database_path('maxmind/GeoLite2-City.mmdb'))->toBeFile();
});

it('can update database on configured filesystem disk', function () {
    config([
        'location.maxmind.license_key' => '123',
        'location.maxmind.local.url' => 'http://example.com',
        'location.maxmind.local.disk' => 'local',
        'location.maxmind.local.path' => 'maxmind/GeoLite2-City.mmdb',
    ]);

    Http::fake([
        'http://example.com' => Http::response(file_get_contents(__DIR__.'/fixtures/maxmind.tar.gz')),
    ]);

    $this->artisan(Update::class)->assertSuccessful();

    expect(Storage::disk('local')->exists('maxmind/GeoLite2-City.mmdb'))->toBeTrue();

    $cachePath = storage_path(sprintf(
        'app/location/maxmind/cache/GeoLite2-City-%s.mmdb',
        md5('local|maxmind/GeoLite2-City.mmdb')
    ));

    expect($cachePath)->toBeFile();
});

it('uploads database contents as string to configured disk for broad cloud storage compatibility', function () {
    config([
        'location.maxmind.local.disk' => 's3',
    ]);

    $driver = new class extends MaxMind
    {
        public function storeDatabaseFile(string $path): void
        {
            $this->putDatabaseContentsFromFile($path);
        }

        protected function getDatabasePath(): string
        {
            return 'maxmind/GeoLite2-City.mmdb';
        }
    };

    $tmpPath = tempnam(sys_get_temp_dir(), 'maxmind_test_');

    file_put_contents($tmpPath, 'test-mmdb-content');

    $disk = m::mock();

    // Disk must receive the file contents as a string (not a stream) so that
    // Flysystem uses write() instead of writeStream(), which is required for
    // S3-compatible backends such as Google Cloud Storage.
    $disk->shouldReceive('put')
        ->once()
        ->with('maxmind/GeoLite2-City.mmdb', 'test-mmdb-content')
        ->andReturn(true);

    Storage::shouldReceive('disk')
        ->once()
        ->with('s3')
        ->andReturn($disk);

    $driver->storeDatabaseFile($tmpPath);

    $cachePath = storage_path(sprintf(
        'app/location/maxmind/cache/GeoLite2-City-%s.mmdb',
        md5('s3|maxmind/GeoLite2-City.mmdb')
    ));

    expect($cachePath)->toBeFile();
    expect(file_get_contents($cachePath))->toBe('test-mmdb-content');

    @unlink($tmpPath);
    @unlink($cachePath);
});

it('can process fluent response', function () {
    $driver = m::mock(MaxMind::class);

    $attributes = [
        'country' => 'United States',
        'country_code' => 'US',
        'city' => 'Long Beach',
        'postal' => 'W7W5L1',
        'metro_code' => '5555',
        'latitude' => '50',
        'longitude' => '50',
        'timezone' => 'America/Toronto',
    ];

    $driver
        ->makePartial()
        ->shouldAllowMockingProtectedMethods()
        ->shouldReceive('process')->once()->andReturn(new Fluent($attributes));

    Location::setDriver($driver);

    $position = Location::get();

    expect($position)->toBeInstanceOf(Position::class);

    expect($position->toArray())->toEqual([
        'countryName' => 'United States',
        'currencyCode' => null,
        'countryCode' => 'US',
        'regionCode' => null,
        'regionName' => null,
        'cityName' => 'Long Beach',
        'zipCode' => null,
        'isoCode' => 'US',
        'postalCode' => 'W7W5L1',
        'latitude' => '50',
        'longitude' => '50',
        'metroCode' => '5555',
        'areaCode' => null,
        'ip' => '66.102.0.0',
        'timezone' => 'America/Toronto',
        'driver' => get_class($driver),
    ]);
});

it('can use city database', function () {
    config(['location.testing.enabled' => false]);
    config(['location.driver' => MaxMind::class]);
    config(['location.maxmind.local.type' => 'city']);
    config(['location.maxmind.local.path' => __DIR__.'/../fixtures/GeoLite2-City-Test.mmdb']);

    $position = Location::get('2.125.160.216');

    expect($position)->toBeInstanceOf(Position::class);

    expect($position->toArray())->toEqual([
        'ip' => '2.125.160.216',
        'countryName' => 'United Kingdom',
        'currencyCode' => null,
        'countryCode' => 'GB',
        'regionCode' => 'WBK',
        'regionName' => 'West Berkshire',
        'cityName' => 'Boxford',
        'zipCode' => null,
        'isoCode' => 'GB',
        'postalCode' => 'OX1',
        'latitude' => '51.75',
        'longitude' => '-1.25',
        'metroCode' => '',
        'areaCode' => null,
        'timezone' => 'Europe/London',
        'driver' => "Stevebauman\Location\Drivers\MaxMind",
    ]);
});

it('can reuse cached local database when using configured filesystem disk', function () {
    config(['location.testing.enabled' => false]);
    config(['location.driver' => MaxMind::class]);
    config(['location.maxmind.local.type' => 'city']);
    config(['location.maxmind.local.disk' => 'local']);
    config(['location.maxmind.local.path' => 'maxmind/GeoLite2-City-Test.mmdb']);

    Storage::disk('local')->put(
        'maxmind/GeoLite2-City-Test.mmdb',
        file_get_contents(__DIR__.'/fixtures/GeoLite2-City-Test.mmdb')
    );

    $first = Location::get('2.125.160.216');

    $cachePath = storage_path(sprintf(
        'app/location/maxmind/cache/GeoLite2-City-Test-%s.mmdb',
        md5('local|maxmind/GeoLite2-City-Test.mmdb')
    ));

    expect($cachePath)->toBeFile();

    Storage::disk('local')->delete('maxmind/GeoLite2-City-Test.mmdb');

    $second = Location::get('2.125.160.216');

    expect($first)->toBeInstanceOf(Position::class);
    expect($second)->toBeInstanceOf(Position::class);
    expect($second->countryCode)->toBe('GB');
    expect($second->cityName)->toBe('Boxford');
});

it('can use country database', function () {
    config(['location.testing.enabled' => false]);
    config(['location.driver' => MaxMind::class]);
    config(['location.maxmind.local.type' => 'country']);
    config(['location.maxmind.local.path' => __DIR__.'/../fixtures/GeoLite2-Country-Test.mmdb']);

    $position = Location::get('2.125.160.216');

    expect($position)->toBeInstanceOf(Position::class);

    expect($position->toArray())->toEqual([
        'ip' => '2.125.160.216',
        'countryName' => 'United Kingdom',
        'currencyCode' => null,
        'countryCode' => 'GB',
        'regionCode' => null,
        'regionName' => null,
        'cityName' => null,
        'zipCode' => null,
        'isoCode' => 'GB',
        'postalCode' => null,
        'latitude' => null,
        'longitude' => null,
        'metroCode' => null,
        'areaCode' => null,
        'timezone' => null,
        'driver' => "Stevebauman\Location\Drivers\MaxMind",
    ]);
});
