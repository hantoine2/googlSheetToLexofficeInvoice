<?php
// Required dependencies and configurations
require_once 'google_sheets.php';
$config = require 'config.php';
$accessToken = $config['access_token'];

// Fetch data from Google Sheets
$sheetData = fetchGoogleSheetData();
if (empty($sheetData)) {
    echo "No data found in Google Sheets.";
    exit;
}

// Function to fetch contactId by name (with retry logic)
function getContactIdByName($email, $accessToken, $retries = 3)
{
    $url = "https://api.lexoffice.io/v1/contacts?email=" . urlencode($email);
    $attempt = 0;

    while ($attempt < $retries) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $accessToken",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['content'][0]['id'])) {
            return $data['content'][0]['id'];
        }

        $attempt++;
        sleep(1); // Wait for 1 second before retrying
    }

    echo "Failed to retrieve contact ID for $email after $retries attempts.<br>";
    return null;
}

// Function to parse dates from dd.mm.yyyy to ISO 8601 format
function parseToIsoDate($date)
{
    $dateParts = explode('.', trim($date));
    if (count($dateParts) === 3) {
        return sprintf('%04d-%02d-%02dT00:00:00.000+02:00', $dateParts[2], $dateParts[1], $dateParts[0]);
    }
    return date("Y-m-d\TH:i:s.000+02:00"); // Default to today if format is unexpected
}

// Recursive function to process each row independently
function processRow($sheetData, $rowIndex, $accessToken)
{
    if ($rowIndex >= count($sheetData)) {
        echo "Finished processing all rows.";
        return;
    }

    echo "Processing row index: $rowIndex<br>";
    $row = $sheetData[$rowIndex];

    // Skip header row if present
    if ($rowIndex === 0) {
        processRow($sheetData, $rowIndex + 1, $accessToken);
        return;
    }

    // Check for required columns
    if (!isset($row[4]) || !isset($row[5])) {
        echo "Data missing in row $rowIndex. Skipping...<br>";
        processRow($sheetData, $rowIndex + 1, $accessToken);
        return;
    }

    // Extract email and fetch contact ID
    $email = $row[2] ?? null;
    if (!$email) {
        echo "No email provided for row $rowIndex. Skipping...<br>";
        processRow($sheetData, $rowIndex + 1, $accessToken);
        return;
    }

    $contactId = getContactIdByName($email, $accessToken);
    if (!$contactId) {
        echo "Contact ID not found for email: $email. Skipping row $rowIndex.<br>";
        processRow($sheetData, $rowIndex + 1, $accessToken);
        return;
    }

    echo "Successfully retrieved contact ID for row $rowIndex: $contactId<br>";

    // Prepare line items for the invoice
    $description = !empty($row[7]) ? $row[7] : "@" . $row[1] . " " . $row[4] . " am " . $row[3];
    $unitPrice = $row[5];
    $quantity = 1;
    $totalCurrency = 'EUR';

    $lineItems = [
        [
            "type" => "custom",
            "name" => $description,
            "quantity" => $quantity,
            "unitName" => "Stück",
            "unitPrice" => [
                "currency" => $totalCurrency,
                "netAmount" => (float)$unitPrice,
                "taxRatePercentage" => 19,
            ],
            "discountPercentage" => 0
        ]
    ];

    if (!empty($row[6])) {
        $lineItems[] = [
            "type" => "custom",
            "name" => "KSK Gebühr",
            "quantity" => 1,
            "unitName" => "Stück",
            "unitPrice" => [
                "currency" => $totalCurrency,
                "netAmount" => (float)$row[6],
                "taxRatePercentage" => 19,
            ],
            "discountPercentage" => 0
        ];
    }

    // Determine shipping conditions based on date data
    $shippingType = "service";
    $shippingDate = $shippingEndDate = date("Y-m-d\TH:i:s.000+02:00");

    if (!empty($row[3])) {
        $dateData = $row[3];
        $dateList = array_map('trim', explode(',', $dateData));

        if (count($dateList) > 1) {
            // Multiple dates found, treat as "serviceperiod"
            $shippingType = "serviceperiod";
            $shippingDate = parseToIsoDate($dateList[0]);
            $shippingEndDate = parseToIsoDate(end($dateList));
        } else {
            // Single date found, treat as "service"
            $shippingDate = $shippingEndDate = parseToIsoDate($dateList[0]);
        }
    }

    $invoiceData = [
        "archived" => false,
        "voucherDate" => date("Y-m-d") . "T00:00:00.000+01:00",
        "address" => ["contactId" => $contactId],
        "lineItems" => $lineItems,
        "totalPrice" => ["currency" => $totalCurrency],
        "taxConditions" => ["taxType" => "net"],
        "shippingConditions" => [
            "shippingDate" => $shippingDate,
            "shippingEndDate" => $shippingEndDate,
            "shippingType" => $shippingType
        ],
        "title" => "Rechnung",
        "introduction" => "Ihre bestellten Positionen stellen wir Ihnen hiermit in Rechnung",
        "remark" => "Vielen Dank für Ihren Einkauf"
    ];

    $maxRetries = 3;
    $retryCount = 0;

    while ($retryCount < $maxRetries) {
        $ch = curl_init("https://api.lexoffice.io/v1/invoices");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoiceData));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($response, true);

        if ($httpCode === 201 && isset($result["id"])) {
            echo "Invoice created successfully for row $rowIndex! Invoice ID: " . $result["id"] . "<br>";
            break;
        } elseif ($httpCode === 429) {
            echo "Rate limit exceeded for row $rowIndex. Retrying in 5 seconds...<br>";
            sleep(5);
            $retryCount++;
        } else {
            echo "Failed to create invoice for row $rowIndex. HTTP Code: $httpCode. Response: " . $response . "<br>";
            break;
        }
    }

    echo "Completed processing for row $rowIndex.<br>";
    processRow($sheetData, $rowIndex + 1, $accessToken);
}

// Start the recursive processing from the first row
processRow($sheetData, 0, $accessToken);
