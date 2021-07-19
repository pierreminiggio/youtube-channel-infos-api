<?php

namespace App;

use DateTime;
use DateTimeInterface;
use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

class App
{

    public function __construct(
        private string $baseDir,
        private string $host
    )
    {
    }

    public function run(string $path, ?string $queryParameters): void
    {
        if ($path === '/') {
            http_response_code(404);

            return;
        }

        $request = substr($path, 1);

        if ($request === 'all') {
            $config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
            $dbConfig = $config['db'];

            $fetcher = new DatabaseFetcher(new DatabaseConnection(
                $dbConfig['host'],
                $dbConfig['database'],
                $dbConfig['username'],
                $dbConfig['password'],
                DatabaseConnection::UTF8_MB4
            ));

            $queriedChannels = $fetcher->query(
                $fetcher->createQuery(
                    'channel_info'
                )->select(
                    'channel_id',
                    'title',
                    'description',
                    'custom_url',
                    'published_at',
                    'photo'
                )
            );

            http_response_code(200);
            echo json_encode(array_map(fn (array $queriedChannel): array => [
                'channel_id' => $queriedChannel['channel_id'],
                'title' => $queriedChannel['title'],
                'description' => $queriedChannel['description'],
                'custom_url' => $queriedChannel['custom_url'],
                'published_at' => $queriedChannel['published_at'],
                'photo' => $queriedChannel['photo']
            ], $queriedChannels));

            return;
        }

        preg_match('/^UC[\w-]{21}[AQgw]/', $request, $matches);

        if (count($matches) !== 1) {
            http_response_code(404);

            return;
        }

        $channelId = $matches[0];

        $config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
        $apiConfig = $config['api'];
        $dbConfig = $config['db'];
        $fetcher = new DatabaseFetcher(new DatabaseConnection(
            $dbConfig['host'],
            $dbConfig['database'],
            $dbConfig['username'],
            $dbConfig['password'],
            DatabaseConnection::UTF8_MB4
        ));

        $queriedIds = $fetcher->query(
            $fetcher
                ->createQuery('unprocessable_request')
                ->select('id')
                ->where('request = :request')
            ,
            ['request' => $channelId]
        );

        if ($queriedIds) {
            http_response_code(404);

            return;
        }

        $channelInfos = $this->findChannelInfosIfPresent($fetcher, $channelId);

        if ($channelInfos) {
            $channelInfos['photo'] = $this->getLocalePhotoOrDownloadItOrShowPlaceholderInstead(
                $channelInfos['photo'],
                $channelInfos['channel_id']
            );

            http_response_code(200);
            echo json_encode($channelInfos);

            return;
        }

        // Check if channel exists on Youtube API
        $accessTokenCurl = curl_init();
        curl_setopt_array($accessTokenCurl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => 'https://www.googleapis.com/oauth2/v4/token',
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $apiConfig['client_id'],
                'client_secret' => $apiConfig['client_secret'],
                'refresh_token' => $apiConfig['refresh_token'],
                'grant_type' => 'refresh_token'
            ])
        ]);
        $accessTokenCurlResult = curl_exec($accessTokenCurl);

        if ($accessTokenCurlResult === false) {
            http_response_code(500);

            return;
        }

        $accessTokenJsonResponse = json_decode($accessTokenCurlResult);
        if (! empty($accessTokenJsonResponse->error)) {
            http_response_code(500);

            return;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => 'https://www.googleapis.com/youtube/v3/channels?id=' . $channelId . '&part=snippet'
        ]);
        $authorization = "Authorization: Bearer " . $accessTokenJsonResponse->access_token;
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json' , $authorization]);

        $result = curl_exec($curl);

        if ($result === false) {
            http_response_code(500);

            return;
        }

        $jsonResponse = json_decode($result);
        if (! empty($jsonResponse->error)) {
            http_response_code(500);

            return;
        }

        if (empty($jsonResponse->pageInfo) || empty($jsonResponse->pageInfo->totalResults)) {
            $fetcher->exec(
                $fetcher
                    ->createQuery('unprocessable_request')
                    ->insertInto('request', ':channel_id')
                ,
                ['channel_id' => $channelId]
            );
            http_response_code(404);

            return;
        }

        if (empty($jsonResponse->items)) {
            http_response_code(500);

            return;
        }

        $entry = $jsonResponse->items[0];
        $snippet = $entry->snippet;
        $title = $snippet->title;
        $description = $snippet->description;
        $customUrl = $snippet->customUrl ?? null;
        $publishedAt = $snippet->publishedAt;
        $thumbnail = ! empty($snippet->thumbnails) && ! empty($snippet->thumbnails->high)
            ? $snippet->thumbnails->high->url
            : null
        ;
        $country = $snippet->country ?? null;

        $publishedAtDate = ! empty($publishedAt)
            ? DateTime::createFromFormat(DateTimeInterface::ISO8601, $publishedAt)
            : null
        ;

        $fetcher->exec(
            $fetcher
                ->createQuery('channel_info')
                ->insertInto(
                    'channel_id, title, description, custom_url, published_at, photo, country',
                    ':channelId, :title, :description, :customUrl, :publishedAt, :thumbnail, :country'
                )
            ,
            [
                'channelId' => $channelId,
                'title' => $title,
                'description' => $description,
                'customUrl' => $customUrl,
                'publishedAt' => $publishedAtDate ? $publishedAtDate->format('Y-m-d H:i:s') : null,
                'thumbnail' => $thumbnail,
                'country' => $country
            ]
        );
        
        $channelInfos = $this->findChannelInfosIfPresent($fetcher, $channelId);

        if (! $channelInfos) {
            http_response_code(500);

            return;
        }

        $channelInfos['photo'] = $this->getLocalePhotoOrDownloadItOrShowPlaceholderInstead(
            $channelInfos['photo'],
            $channelInfos['channel_id']
        );

        http_response_code(200);
        echo json_encode($channelInfos);
    }

    protected function findChannelInfosIfPresent(DatabaseFetcher $fetcher, string $channelId): ?array
    {
        $fetchedChannels = $fetcher->query(
            $fetcher
                ->createQuery('channel_info')
                ->select('channel_id, title, description, custom_url, published_at, photo, country')
                ->where('channel_id = :channel_id')
            ,
            ['channel_id' => $channelId]
        );

        if (! $fetchedChannels) {

            return null;
        }

        $fetchedChannel = $fetchedChannels[0];

        return [
            'channel_id' => $fetchedChannel['channel_id'],
            'title' => $fetchedChannel['title'],
            'description' => $fetchedChannel['description'],
            'custom_url' => $fetchedChannel['custom_url'],
            'published_at' => $fetchedChannel['published_at'],
            'photo' => $fetchedChannel['photo'],
            'country' => $fetchedChannel['country']
        ];
    }

    protected function getLocalePhotoOrDownloadItOrShowPlaceholderInstead(string $photoUrl, string $channelId): string
    {
        $cacheDir = $this->baseDir . 'cache' . DIRECTORY_SEPARATOR;

        if (! file_exists($cacheDir)) {
            mkdir($cacheDir);
        }

        $photoName =  $channelId . '.png';
        $photoPath = $cacheDir . $photoName;

        if (! file_exists($photoPath)) {
            set_time_limit(0);

            $fp = fopen($photoPath, 'w+');
            $ch = curl_init($photoUrl);
            curl_setopt($ch, CURLOPT_TIMEOUT, 50);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($httpCode !== 200) {
                unlink($photoPath);

                return $this->host . '/placeholder.png';
            }
        }

        return $this->host . '/cache/' . $photoName;
    }
}
