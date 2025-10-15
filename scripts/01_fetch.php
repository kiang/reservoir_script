<?php
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

$datasets = [
    '111184',
    '6345'
];

$baseUrl = 'https://data.gov.tw/api/v2/rest/dataset/';

// Initialize Guzzle HTTP client
$client = new Client([
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
    ],
    'verify' => false,
    'timeout' => 30,
]);

foreach ($datasets as $datasetId) {
    echo "Fetching dataset: {$datasetId}\n";

    try {
        // Get dataset metadata
        $metadataUrl = $baseUrl . $datasetId;
        $response = $client->get($metadataUrl);
        $metadata = $response->getBody()->getContents();
        $metadataJson = json_decode($metadata, true);
    } catch (Exception $e) {
        echo "Failed to fetch metadata for dataset {$datasetId}: " . $e->getMessage() . "\n";
        continue;
    }

    if (!isset($metadataJson['result']['distribution'])) {
        echo "No distribution found for dataset {$datasetId}\n";
        continue;
    }

    // Find CSV resources and download them
    foreach ($metadataJson['result']['distribution'] as $index => $distribution) {
        $resourceDesc = $distribution['resourceDescription'] ?? '';

        if (stripos($resourceDesc, 'CSV') !== false) {
            $downloadUrl = $distribution['resourceDownloadUrl'] ?? null;

            if ($downloadUrl) {
                $filename = __DIR__ . "/../data/raw/{$datasetId}_{$index}.csv";

                // Create data directory if it doesn't exist
                $dataDir = dirname($filename);
                if (!is_dir($dataDir)) {
                    mkdir($dataDir, 0755, true);
                }

                echo "Downloading CSV from: {$downloadUrl}\n";

                try {
                    $response = $client->get($downloadUrl);
                    $csvContent = $response->getBody()->getContents();
                    file_put_contents($filename, $csvContent);
                    echo "Saved to: {$filename}\n";
                } catch (Exception $e) {
                    echo "Failed to download CSV from {$downloadUrl}: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    echo "\n";
}

echo "Fetch complete\n";
