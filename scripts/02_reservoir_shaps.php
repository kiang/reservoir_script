<?php
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Initialize cookie jar for maintaining session
$cookieJar = new \GuzzleHttp\Cookie\CookieJar();

// Initialize Guzzle HTTP client
$client = new Client([
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
    ],
    'verify' => false,
    'timeout' => 30,
    'cookies' => $cookieJar
]);

$baseUrl = 'https://wq.moenv.gov.tw/EWQP/zh/EnvWaterMonitoring/Reservoir.aspx';

echo "Fetching initial page to get dam options...\n";

// Create output directory
$shapsDir = __DIR__ . '/../shaps';
if (!is_dir($shapsDir)) {
    mkdir($shapsDir, 0755, true);
}

try {
    // First request to get the page and viewstate
    $response = $client->get($baseUrl);
    $html = $response->getBody()->getContents();

    // Parse HTML to extract dropdown options and form data
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Get dropdown options
    $options = $xpath->query('//select[@id="CPH1_ddlDam"]/option');

    if ($options->length === 0) {
        echo "No dam options found\n";
        exit(1);
    }

    echo "Found {$options->length} dam options\n";

    // Extract ALL hidden form fields
    $hiddenFields = [];
    $hiddenInputs = $xpath->query('//input[@type="hidden"]');
    foreach ($hiddenInputs as $input) {
        $name = $input->getAttribute('name');
        $value = $input->getAttribute('value');
        if ($name) {
            $hiddenFields[$name] = $value;
        }
    }

    echo "Extracted " . count($hiddenFields) . " hidden form fields\n";

    // Loop through each dam option
    foreach ($options as $option) {
        $value = $option->getAttribute('value');
        $text = trim($option->textContent);

        // Skip empty options
        if (empty($value) || $value === '') {
            continue;
        }

        echo "Processing: {$text} (value: {$value})\n";

        try {
            // Build complete POST data with ALL hidden fields
            $postData = $hiddenFields;
            $postData['ctl00$ScriptMgr'] = 'ctl00$CPH1$UpdatePanel1|ctl00$CPH1$ddlDam';
            $postData['__EVENTTARGET'] = 'ctl00$CPH1$ddlDam';
            $postData['__EVENTARGUMENT'] = '';
            $postData['__LASTFOCUS'] = '';
            $postData['__ASYNCPOST'] = 'true';
            $postData['ctl00$CPH1$ddlDam'] = $value;

            $response = $client->post($baseUrl, [
                'form_params' => $postData,
                'headers' => [
                    'X-Requested-With' => 'XMLHttpRequest',
                    'X-MicrosoftAjax' => 'Delta=true',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Referer' => $baseUrl,
                    'Origin' => 'https://wq.moenv.gov.tw'
                ]
            ]);

            $responseBody = $response->getBody()->getContents();

            // ASP.NET AJAX returns a special delta format, not full HTML
            // Extract the SVG from the delta response (looking for div.map with svgDam)
            if (preg_match('/<div class=.map.>.*?<\/figure>/s', $responseBody, $matches)) {
                $htmlFragment = $matches[0];

                // Extract SVG with class="svgDam" from the HTML fragment
                if (preg_match('/<svg[^>]*class=.svgDam.*?<\/svg>/s', $htmlFragment, $svgMatches)) {
                    $svgContent = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
                    $svgContent .= '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n";
                    $svgContent .= $svgMatches[0];

                    // Clean filename
                    $filename = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '_', $text);
                    $filepath = $shapsDir . '/' . $filename . '.svg';

                    file_put_contents($filepath, $svgContent);
                    echo "Saved SVG to: {$filepath}\n";

                    // Update hidden fields from response
                    if (preg_match('/\|__VIEWSTATE\|([^|]+)\|/', $responseBody, $vsMatch)) {
                        $hiddenFields['__VIEWSTATE'] = $vsMatch[1];
                    }
                    if (preg_match('/\|__EVENTVALIDATION\|([^|]+)\|/', $responseBody, $evMatch)) {
                        $hiddenFields['__EVENTVALIDATION'] = $evMatch[1];
                    }
                } else {
                    echo "Could not extract SVG from response for {$text}\n";
                }
            } else {
                echo "No reservoir data found for {$text}\n";
            }

        } catch (Exception $e) {
            echo "Failed to fetch data for {$text}: " . $e->getMessage() . "\n";
            continue;
        }

        // Small delay to be polite to the server
        usleep(500000); // 0.5 seconds
    }

} catch (Exception $e) {
    echo "Failed to fetch initial page: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nComplete! SVG files saved to {$shapsDir}\n";
