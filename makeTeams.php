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

$headers = fgetcsv($handle, separator: ',', enclosure: '"', escape: "");
if ($headers === false) {
    echo "CSV file is empty or invalid.\n";
    fclose($handle);
    exit(1);
}

$full_file = array_fill_keys($headers, []);

while (($row = fgetcsv($handle, separator: ',', enclosure: '"', escape: "")) !== false) {
    foreach ($headers as $i => $header) {
        $full_file[$header][] = $row[$i] ?? null;
    }
}

fclose($handle);

function collectClusterInfo(array $full_file): array {
    $clusters = [];
    $numRows = count($full_file['Cluster']);
    for ($i = 0; $i < $numRows; $i++) {
        // Extract cluster code
        if (!isset($full_file['Cluster'][$i])) continue;
        if (preg_match('/\b[bs][yo][A-Z]{2}\d+[ab]?\b/', $full_file['Cluster'][$i], $matches)) {
            $cluster = $matches[0];
        } else {
            continue;
        }

        // Initialize if not set
        if (!isset($clusters[$cluster])) {
            $clusters[$cluster] = [
                'size' => 0,
                'languages' => [],
                'translators' => [],
                'possibleleader' => []
            ];
        }

        // Increment size
        $clusters[$cluster]['size']++;

        // Collect language
        if (!empty($full_file['Language'][$i])) {
            $clusters[$cluster]['languages'][] = $full_file['Language'][$i];
        }

        // Collect CanTranslate
        if (!empty($full_file['CanTranslate'][$i])) {
            $clusters[$cluster]['translators'][] = $full_file['CanTranslate'][$i];
        }

        // Collect possible team possibleleader
        if (!empty($full_file['TeamLeader'][$i]) && stripos($full_file['TeamLeader'][$i], 'yes') !== false) {
            $first = $full_file['FirstName'][$i] ?? '';
            $last = $full_file['LastName'][$i] ?? '';
            $name = trim($first . ' ' . $last);
            if ($name !== '') {
                $clusters[$cluster]['possibleleader'][] = $name;
            }
        }
    }

    // Make languages and translators unique
    foreach ($clusters as &$info) {
        $info['languages'] = array_values(array_unique($info['languages']));
        $info['translators'] = array_values(array_unique($info['translators']));
        $info['possibleleader'] = array_values(array_unique($info['possibleleader']));
    }

    return $clusters;
}

/*
// Filter and extract codes from the 'Cluster' column
function filterAndExtractClusterCodes(array $input): array {
    $result = [];
    foreach ($input as $value) {
        if (preg_match('/\b[bs][yo][A-Z]{2}\d+[ab]?\b/', $value, $matches)) {
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
*/
/**
 * Distributes clusters into 42 teams, prioritizing even team sizes over country mixing.
 *
 * @param array $clusters Associative array: key = cluster code, value = array with 'size', 'languages', etc.
 * @return array Array of 42 teams, each a list of cluster codes
 */
function distributeClustersToTeams(array $clusters): array {
    $teams = array_fill(0, 42, []);
    $teamProfiles = array_fill(0, 42, []); // Track gender/age/country per team

    // Parse cluster info
    $parsed = [];
    foreach ($clusters as $code => $info) {
        if (preg_match('/^([bs])([yo])([A-Z]{2})\d+[ab]?$/', $code, $m)) {
            $parsed[] = [
                'code' => $code,
                'size' => $info['size'],
                'gender' => $m[1], // b or s
                'age' => $m[2],    // y or o
                'country' => $m[3] // e.g. US
            ];
        }
    }

    // Sort clusters by size descending (to place big clusters first)
    usort($parsed, function($a, $b) {
        return $b['size'] <=> $a['size'];
    });

    // Helper: check if team already has a cluster of same gender/age
    function hasCategory($profile, $gender, $age) {
        return isset($profile["$gender$age"]);
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

            // 3. Prefer teams with more country diversity (lower priority)
            $countries = $teamProfiles[$i]['countries'] ?? [];
            $countryScore = in_array($cluster['country'], $countries) ? 0 : 1;

            // 4. Composite score: prioritize evenness, then country diversity
            $score = $sizeScore * 100 + $countryScore;

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

/**
 * Attempts to ensure every team has at least one cluster with a possible leader, by swapping clusters between teams.
 * Teams with multiple leaders may give one to teams with none.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @param array $clusterInfo Output of collectClusterInfo (clusterCode => info array)
 * @return array New teams array with improved leader distribution
 */
function balanceTeamLeaders(array $teams, array $clusterInfo): array {
    // Find which clusters have leaders
    $hasLeader = [];
    foreach ($clusterInfo as $code => $info) {
        $hasLeader[$code] = !empty($info['possibleleader']);
    }

    // Map teams to clusters with/without leaders
    $teamsWithLeaders = [];
    $teamsWithoutLeaders = [];
    foreach ($teams as $i => $team) {
        $leaderCount = 0;
        foreach ($team as $clusterCode) {
            if (!empty($hasLeader[$clusterCode])) {
                $leaderCount++;
            }
        }
        if ($leaderCount == 0) {
            $teamsWithoutLeaders[] = $i;
        } elseif ($leaderCount > 1) {
            $teamsWithLeaders[] = $i;
        }
    }

    // Try to swap clusters: give a leader cluster from a team with >1 to a team with 0
    foreach ($teamsWithoutLeaders as $noLeaderIdx) {
        foreach ($teamsWithLeaders as $withLeaderIdx) {
            // Find a cluster with a leader in the donor team
            foreach ($teams[$withLeaderIdx] as $donorKey => $donorCluster) {
                if ($hasLeader[$donorCluster]) {
                    // Find a cluster without a leader in the recipient team
                    foreach ($teams[$noLeaderIdx] as $recipientKey => $recipientCluster) {
                        if (!$hasLeader[$recipientCluster]) {
                            // Swap clusters
                            $tmp = $teams[$noLeaderIdx][$recipientKey];
                            $teams[$noLeaderIdx][$recipientKey] = $teams[$withLeaderIdx][$donorKey];
                            $teams[$withLeaderIdx][$donorKey] = $tmp;
                            // After swap, move to next team without leader
                            break 3;
                        }
                    }
                }
            }
        }
    }

    return $teams;
}

// These are reporting functions to calculate team sizes and print possible leaders
function calculateTeamSizes(array $teams, array $clusters): array {
    $teamSizes = [];
    foreach ($teams as $team) {
        $total = 0;
        foreach ($team as $clusterCode) {
            $total += $clusters[$clusterCode]['size'] ?? 0;
        }
        $teamSizes[] = $total;
    }
    return $teamSizes;
}

function printTeamPossibleLeaders(array $teams, array $clusterInfo): void {
    foreach ($teams as $teamIndex => $team) {
        echo "Team " . ($teamIndex + 1) . " possible leaders:\n";
        $leaders = [];
        foreach ($team as $clusterCode) {
            if (!empty($clusterInfo[$clusterCode]['possibleleader'])) {
                foreach ($clusterInfo[$clusterCode]['possibleleader'] as $leader) {
                    $leaders[] = $leader;
                }
            }
        }
        if (!empty($leaders)) {
            foreach ($leaders as $name) {
                echo "  - $name\n";
            }
        } else {
            echo "  (none)\n";
        }
    }
}

// Print the associative array
//$clusterCodes = filterAndExtractClusterCodes($full_file["Cluster"]);
$clusterInfo = collectClusterInfo($full_file);
print_r($clusterInfo);
$teams = distributeClustersToTeams($clusterInfo);
$teams = balanceTeamLeaders($teams, $clusterInfo);
$teams = balanceTeamLeaders(array_reverse($teams), $clusterInfo);
//print_r($teams);
$teamSizes = calculateTeamSizes($teams, $clusterInfo);
echo "Team sizes: " . implode(", ", $teamSizes) . "\n";
printTeamPossibleLeaders($teams, $clusterInfo);