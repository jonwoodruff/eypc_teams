<?php
if ($argc < 2) {
    echo "Usage: php {$argv[0]} <csv_file>\n";
    exit(1);
}

$filename = $argv[1];
if (!file_exists($filename) || !is_readable($filename)) {
    echo "File not found or not readable: $filename\n";
    exit(1);
}

$handle = fopen($filename, 'r');
if ($handle === false) {
    echo "Failed to open file: $filename\n";
    exit(1);
}

$headers = fgetcsv($handle);
if ($headers === false) {
    echo "CSV file is empty or invalid.\n";
    fclose($handle);
    exit(1);
}

$result = array_fill_keys($headers, []);

while (($row = fgetcsv($handle)) !== false) {
    foreach ($headers as $i => $header) {
        $result[$header][] = $row[$i] ?? null;
    }
}

fclose($handle);

function countOccurrences(array $input): array {
    $counts = [];
    foreach ($input as $value) {
        if (isset($counts[$value])) {
            $counts[$value]++;
        } else {
            $counts[$value] = 1;
        }
    }
    return $counts;
}

// Print the associative array
print_r(countOccurrences($result["Cluster"]));