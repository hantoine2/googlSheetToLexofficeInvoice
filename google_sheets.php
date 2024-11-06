<?php
function fetchGoogleSheetData()
{
    // Replace with your actual API key and spreadsheet ID
    $apiKey = 'google_api_key';
    $spreadsheetId = 'spreadsheet_id';
    $range = 'rangeA:B'; // Define the range to read from the sheet

    // Google Sheets API URL
    $url = "https://sheets.googleapis.com/v4/spreadsheets/$spreadsheetId/values/$range?key=$apiKey";

    // Fetch data from Google Sheets
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    // Check if there's an error in the API response
    if (isset($data['error'])) {
        echo "Error: " . $data['error']['message'];
        return [];
    }
    // Return data if available, otherwise an empty array
    return $data['values'] ?? [];
}
