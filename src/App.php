<?php

namespace App;

class App
{
    public function run(string $path, ?string $queryParameters): void
    {
        if ($path === '/') {
            http_response_code(404);

            return;
        }

        $channelId = substr($path, 1);
        preg_match('/^UC[\w-]{21}[AQgw]/', $channelId, $matches);

        if (count($matches) !== 1) {
            http_response_code(404);

            return;
        } else {
            $channelId = $matches[0];
        }

        $config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
        $apiConfig = $config['api'];

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
        $customUrl = $snippet->customUrl;
        $publishedAt = $snippet->publishedAt;
        $thumbnail = ! empty($snippet->thumbnails) && ! empty($snippet->thumbnails->high)
            ? $snippet->thumbnails->high->url
            : null
        ;
        $country = $snippet->country;
        var_dump($channelId, $title, $description, $customUrl, $publishedAt, $thumbnail, $country);

        echo 'Yeay !';
    }
}
