
<?php
// Include google_sheets.php to use fetchGoogleSheetData function
include 'google_sheets.php';

// Fetch data from Google Sheets
$sheetData = fetchGoogleSheetData();

// Check if data was retrieved successfully and column C exists
if (!empty($sheetData)) {
    // Extract the emails from column C (index 2 since arrays are 0-indexed)
    $emails = array_column($sheetData, 2);
    if (!empty($emails)) {
        // Set email to the first email in column C for this example
        $email = $emails[0];  // Adjust if needed to target specific entries
    } else {
        die("Error: No email addresses found in column C.");
    }
} else {
    die("Error: Could not retrieve data from Google Sheets.");
}

// Include configuration file for the access token
$config = require 'config.php';
$accessToken = $config['access_token'];

// Build the endpoint URL with query parameters
$url = "https://api.lexoffice.io/v1/contacts?email=" . urlencode($email);

// Initialize cURL session for GET request
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $accessToken",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Execute the request and fetch response
$response = curl_exec($ch);
curl_close($ch);

// Log the entire raw response from the initial contact search
echo "<h2>Raw Response (Contact Search):</h2>";
echo "<pre>";
var_dump($response);
echo "</pre>";

// Decode the JSON response
$data = json_decode($response, true);

// Log the decoded JSON response for easier reading
echo "<h2>Decoded JSON Response (Contact Search):</h2>";
echo "<pre>";
print_r($data);
echo "</pre>";

// Check if data was retrieved successfully
if (isset($data['content']) && !empty($data['content'])) {
    echo "<h2>Contact Data:</h2>";
    foreach ($data['content'] as $contact) {
        // Extract contact ID for additional request
        $id = $contact['id'];
        $salutation = $contact['person']['salutation'] ?? '';
        $firstName = $contact['person']['firstName'] ?? '';
        $lastName = $contact['person']['lastName'] ?? '';
        $customerNumber = $contact['roles']['customer']['number'] ?? 'N/A';
        $archived = $contact['archived'] ? 'Yes' : 'No';

        // Display basic contact information
        echo "<p>Contact ID: $id</p>";
        echo "<p>Customer Number: $customerNumber</p>";
        echo "<p>Salutation: $salutation</p>";
        echo "<p>Name: $firstName $lastName</p>";
        echo "<p>Archived: $archived</p>";
        echo "<hr>";
    }
} else {
    echo "No contact data found or an error occurred.";
}
?>
