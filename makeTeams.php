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

$full_file = array_fill_keys($headers, []);

while (($row = fgetcsv($handle)) !== false) {
    foreach ($headers as $i => $header) {
        $full_file[$header][] = $row[$i] ?? null;
    }
}

fclose($handle);

// Filter and extract codes from the 'Cluster' column
function filterAndExtractClusterCodes(array $input): array {
    $result = [];
    foreach ($input as $value) {
        if (preg_match('/\b[bs][yo][A-Z]{2}\d+\b/', $value, $matches)) {
            $result[] = $matches[0];
        }
    }
    return $result;
}

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

/**
 * Distributes clusters into 42 teams according to gender, age, and country diversity.
 *
 * @param array $clusters Associative array: key = cluster code ([bs][yo][A-Z]{2}\d+), value = count
 * @return array Array of 42 teams, each a list of cluster codes
 */
function distributeClustersToTeams(array $clusters): array {
    $teams = array_fill(0, 42, []);
    $teamProfiles = array_fill(0, 42, []); // Track gender/age/country per team

    // Parse cluster info
    $parsed = [];
    foreach ($clusters as $code => $count) {
        if (preg_match('/^([bs])([yo])([A-Z]{2})\d+$/', $code, $m)) {
            $parsed[] = [
                'code' => $code,
                'count' => $count,
                'gender' => $m[1], // b or s
                'age' => $m[2],    // y or o
                'country' => $m[3] // e.g. US
            ];
        }
    }

    // Sort clusters by count descending (to place big clusters first)
    usort($parsed, function($a, $b) {
        return $b['count'] <=> $a['count'];
    });

    // Helper: check if team already has a cluster of same gender/age
    function hasCategory($profile, $gender, $age) {
        return isset($profile["$gender$age"]);
    }

    // Helper: count unique countries in a team
    function countryCount($profile) {
        return isset($profile['countries']) ? count($profile['countries']) : 0;
    }

    // Distribute clusters
    foreach ($parsed as $cluster) {
        $bestTeam = null;
        $bestScore = -1;

        // Try to find the best team for this cluster
        for ($i = 0; $i < 42; $i++) {
            // 1. Don't allow same gender+age in same team
            if (hasCategory($teamProfiles[$i], $cluster['gender'], $cluster['age'])) {
                continue;
            }

            // 2. Prefer teams with fewer clusters (for evenness)
            $sizeScore = -count($teams[$i]);

            // 3. Prefer teams with more country diversity
            $countries = $teamProfiles[$i]['countries'] ?? [];
            $countryScore = in_array($cluster['country'], $countries) ? 0 : 1;

            // 4. Composite score: prioritize country diversity, then evenness
            $score = $countryScore * 100 + $sizeScore;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTeam = $i;
            }
        }

        // If no team fits (all have same gender+age), place in smallest team
        if ($bestTeam === null) {
            $minSize = min(array_map('count', $teams));
            foreach ($teams as $i => $team) {
                if (count($team) == $minSize) {
                    $bestTeam = $i;
                    break;
                }
            }
        }

        // Assign cluster to team
        $teams[$bestTeam][] = $cluster['code'];
        // Update team profile
        $teamProfiles[$bestTeam]["{$cluster['gender']}{$cluster['age']}"] = true;
        $teamProfiles[$bestTeam]['countries'][] = $cluster['country'];
        $teamProfiles[$bestTeam]['countries'] = array_unique($teamProfiles[$bestTeam]['countries']);
    }

    return $teams;
}

function calculateTeamSizes(array $teams, array $clusters): array {
    $teamSizes = [];
    foreach ($teams as $team) {
        $total = 0;
        foreach ($team as $clusterCode) {
            $total += $clusters[$clusterCode] ?? 0;
        }
        $teamSizes[] = $total;
    }
    return $teamSizes;
}

// Print the associative array
$clusterCodes = filterAndExtractClusterCodes($full_file["Cluster"]);
$clusterCounts = countOccurrences($clusterCodes);
$teams = distributeClustersToTeams($clusterCounts);
print_r($teams);
$teamSizes = calculateTeamSizes($teams, $clusterCounts);
echo "Team sizes: " . implode(", ", $teamSizes) . "\n";