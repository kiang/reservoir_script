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

    // Find CSV resources and download them with pagination
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

                echo "Downloading CSV with pagination from: {$downloadUrl}\n";

                // Fetch all pages
                $allData = fetchAllPages($client, $downloadUrl);

                if ($allData !== null) {
                    file_put_contents($filename, $allData);
                    echo "Saved to: {$filename}\n";

                    // Process CSV and group data by year and damname for dataset 6345
                    if ($datasetId === '6345') {
                        echo "Processing CSV data...\n";
                        processCSVtoJSON($filename);
                    }
                }
            }
        }
    }

    echo "\n";
}

function fetchAllPages($client, $baseUrl) {
    $limit = 1000;
    $offset = 0;
    $allRecords = [];
    $header = null;

    while (true) {
        // Parse URL and add limit/offset parameters
        $urlParts = parse_url($baseUrl);
        $queryParams = [];

        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
        }

        $queryParams['limit'] = $limit;
        $queryParams['offset'] = $offset;

        $url = $urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'] . '?' . http_build_query($queryParams);

        echo "Fetching offset {$offset}...\n";

        try {
            $response = $client->get($url);
            $csvContent = $response->getBody()->getContents();

            // Parse CSV content
            $lines = str_getcsv($csvContent, "\n");

            if (empty($lines)) {
                break;
            }

            // First page: extract header
            if ($header === null) {
                $header = $lines[0];
                array_shift($lines); // Remove header from lines
            } else {
                // Subsequent pages: remove header line
                array_shift($lines);
            }

            $recordCount = count($lines);

            // Filter out empty lines
            $lines = array_filter($lines, function($line) {
                return !empty(trim($line));
            });

            $allRecords = array_merge($allRecords, $lines);

            echo "Fetched {$recordCount} records\n";

            // If we got fewer records than limit, we've reached the end
            if ($recordCount < $limit) {
                break;
            }

            $offset += $limit;

        } catch (Exception $e) {
            echo "Failed to download CSV from {$url}: " . $e->getMessage() . "\n";
            return null;
        }
    }

    // Combine header and all records
    if ($header !== null && !empty($allRecords)) {
        array_unshift($allRecords, $header);
        return implode("\n", $allRecords);
    }

    return null;
}

function processCSVtoJSON($csvFile) {
    $jsonData = [];

    if (($handle = fopen($csvFile, 'r')) !== false) {
        // Read header row
        $header = fgetcsv($handle);

        // Remove BOM if present
        if (!empty($header[0])) {
            $header[0] = str_replace("\xEF\xBB\xBF", '', $header[0]);
        }

        $lineCount = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $lineCount++;
            $row = array_combine($header, $data);

            if (empty($row['sampledate']) || empty($row['damname'])) {
                continue;
            }

            // Extract year from sampledate
            $year = date('Y', strtotime($row['sampledate']));
            $damname = $row['damname'];
            $sampledate = $row['sampledate'];

            $siteid = $row['siteid'] ?? '';

            // Initialize structure if needed
            if (!isset($jsonData[$year])) {
                $jsonData[$year] = [];
            }
            if (!isset($jsonData[$year][$damname])) {
                $jsonData[$year][$damname] = [];
            }
            if (!isset($jsonData[$year][$damname][$siteid])) {
                $jsonData[$year][$damname][$siteid] = [
                    'twd97lon' => $row['twd97lon'] ?? '',
                    'twd97lat' => $row['twd97lat'] ?? '',
                    'data' => []
                ];
            }

            // Create unique key for this entry
            $uniqueKey = $row['samplelayer'] . '|' . $row['sampledepth'] . '|' . $row['itemname'];

            // Create data entry for this sample
            $entry = [
                'samplelayer' => $row['samplelayer'] ?? '',
                'sampledepth' => $row['sampledepth'] ?? '',
                'itemname' => $row['itemname'] ?? '',
                'itemengname' => $row['itemengname'] ?? '',
                'itemengabbreviation' => $row['itemengabbreviation'] ?? '',
                'itemvalue' => $row['itemvalue'] ?? '',
                'itemunit' => $row['itemunit'] ?? '',
                'note' => $row['note'] ?? ''
            ];

            // Group by sampledate under siteid with unique key
            if (!isset($jsonData[$year][$damname][$siteid]['data'][$sampledate])) {
                $jsonData[$year][$damname][$siteid]['data'][$sampledate] = [];
            }
            $jsonData[$year][$damname][$siteid]['data'][$sampledate][$uniqueKey] = $entry;
        }

        fclose($handle);
        echo "Processed {$lineCount} rows\n";

        // Write JSON files by year and damname
        foreach ($jsonData as $year => $dams) {
            foreach ($dams as $damname => $sites) {
                $jsonDir = __DIR__ . "/../data/docs/json/{$year}";
                if (!is_dir($jsonDir)) {
                    mkdir($jsonDir, 0755, true);
                }

                $jsonFile = $jsonDir . "/" . preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '_', $damname) . ".json";

                // Load existing JSON file if it exists
                $existingSites = [];
                if (file_exists($jsonFile)) {
                    $existingContent = file_get_contents($jsonFile);
                    $existingSites = json_decode($existingContent, true) ?? [];
                }

                // Merge with new data
                foreach ($sites as $siteId => $siteData) {
                    if (!isset($existingSites[$siteId])) {
                        $existingSites[$siteId] = $siteData;
                    } else {
                        // Update coordinates if changed
                        $existingSites[$siteId]['twd97lon'] = $siteData['twd97lon'];
                        $existingSites[$siteId]['twd97lat'] = $siteData['twd97lat'];

                        // Merge data by date
                        foreach ($siteData['data'] as $date => $entries) {
                            if (!isset($existingSites[$siteId]['data'][$date])) {
                                $existingSites[$siteId]['data'][$date] = [];
                            }

                            // Merge entries with unique key
                            foreach ($entries as $key => $entry) {
                                $existingSites[$siteId]['data'][$date][$key] = $entry;
                            }
                        }
                    }
                }

                // Convert back to array format for output
                foreach ($existingSites as $siteId => &$siteInfo) {
                    foreach ($siteInfo['data'] as $date => &$entries) {
                        $entries = array_values($entries);
                    }
                }

                file_put_contents($jsonFile, json_encode($existingSites, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                echo "Saved JSON: {$jsonFile}\n";
            }
        }
    }
}

echo "Fetch complete\n";
