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
                'translationlanguages' => [],
                'possibleleader' => []
            ];
        }

        // Increment size
        $clusters[$cluster]['size']++;

        // Collect language only if English is Poor or None
        $englishLevel = $full_file['English'][$i] ?? '';
        if (
            (!empty($full_file['Language'][$i])) &&
            (strcasecmp($englishLevel, 'Poor') === 0 || strcasecmp($englishLevel, 'None') === 0)
        ) {
            $clusters[$cluster]['languages'][] = $full_file['Language'][$i];
        }

        // Collect CanTranslate, splitting multiple languages
        if (!empty($full_file['CanTranslate'][$i])) {
            // Split on any sequence of non-word characters (e.g. comma, semicolon, space)
            $langs = preg_split('/\W+/', $full_file['CanTranslate'][$i], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($langs as $lang) {
                $clusters[$cluster]['translationlanguages'][] = $lang;
            }
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

    // Make languages and translationlanguages unique
    foreach ($clusters as &$info) {
        $info['languages'] = array_values(array_unique($info['languages']));
        $info['translationlanguages'] = array_values(array_unique($info['translationlanguages']));
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

/**
 * Attempts to redistribute clusters so that every team has translators for all spoken languages.
 * Ignores English, and treats Ukrainian and Russian as the same.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @param array $clusterInfo Output of collectClusterInfo (clusterCode => info array)
 * @return array New teams array with improved language coverage
 */
function balanceTeamLanguages(array $teams, array $clusterInfo): array {
    // Helper to normalize language names
    $normalize = function($lang) {
        $lang = strtolower(trim($lang));
        if ($lang === 'ukrainian' || $lang === 'ukrain') $lang = 'russian';
        if ($lang === 'english') return null;
        return $lang;
    };

    // Build a map of clusterCode => normalized spoken/translation languages
    $clusterLangs = [];
    foreach ($clusterInfo as $code => $info) {
        $spoken = array_filter(array_map($normalize, $info['languages'] ?? []));
        $canTranslate = array_filter(array_map($normalize, $info['translationlanguages'] ?? []));
        $clusterLangs[$code] = [
            'spoken' => $spoken,
            'canTranslate' => $canTranslate,
        ];
    }

    // Try to fix teams with missing translators
    $maxIterations = 10; // Avoid infinite loops
    for ($iter = 0; $iter < $maxIterations; $iter++) {
        $fixed = true;
        foreach ($teams as $teamIdx => $team) {
            // Gather all spoken and translation languages in this team
            $spoken = [];
            $canTranslate = [];
            foreach ($team as $clusterCode) {
                $spoken = array_merge($spoken, $clusterLangs[$clusterCode]['spoken']);
                $canTranslate = array_merge($canTranslate, $clusterLangs[$clusterCode]['canTranslate']);
            }
            $spoken = array_unique($spoken);
            $canTranslate = array_unique($canTranslate);

            // Find missing translations
            $missing = array_diff($spoken, $canTranslate);
            if (empty($missing)) continue;

            // Try to swap a cluster with a missing language with a cluster from another team that brings a translator
            foreach ($missing as $lang) {
                // Find a cluster in this team that speaks $lang but does not translate it
                foreach ($team as $i => $clusterCode) {
                    if (in_array($lang, $clusterLangs[$clusterCode]['spoken']) &&
                        !in_array($lang, $clusterLangs[$clusterCode]['canTranslate'])) {
                        // Look for a cluster in another team that can translate $lang and does not introduce new missing languages
                        foreach ($teams as $otherIdx => $otherTeam) {
                            if ($otherIdx == $teamIdx) continue;
                            foreach ($otherTeam as $j => $otherCluster) {
                                if (in_array($lang, $clusterLangs[$otherCluster]['canTranslate'])) {
                                    // Simulate swap
                                    $newTeam = $team;
                                    $newOtherTeam = $otherTeam;
                                    $newTeam[$i] = $otherCluster;
                                    $newOtherTeam[$j] = $clusterCode;

                                    // Recalculate spoken/translated for both teams
                                    $newSpoken = [];
                                    $newCanTranslate = [];
                                    foreach ($newTeam as $cc) {
                                        $newSpoken = array_merge($newSpoken, $clusterLangs[$cc]['spoken']);
                                        $newCanTranslate = array_merge($newCanTranslate, $clusterLangs[$cc]['canTranslate']);
                                    }
                                    $newSpoken = array_unique($newSpoken);
                                    $newCanTranslate = array_unique($newCanTranslate);
                                    $newMissing = array_diff($newSpoken, $newCanTranslate);

                                    $otherSpoken = [];
                                    $otherCanTranslate = [];
                                    foreach ($newOtherTeam as $cc) {
                                        $otherSpoken = array_merge($otherSpoken, $clusterLangs[$cc]['spoken']);
                                        $otherCanTranslate = array_merge($otherCanTranslate, $clusterLangs[$cc]['canTranslate']);
                                    }
                                    $otherSpoken = array_unique($otherSpoken);
                                    $otherCanTranslate = array_unique($otherCanTranslate);
                                    $otherMissing = array_diff($otherSpoken, $otherCanTranslate);

                                    // Only swap if it doesn't make things worse for either team
                                    if (count($newMissing) <= count($missing) && count($otherMissing) == 0) {
                                        // Perform swap
                                        $teams[$teamIdx][$i] = $otherCluster;
                                        $teams[$otherIdx][$j] = $clusterCode;
                                        $fixed = false;
                                        break 4;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($fixed) break;
    }
    return $teams;
}

/**
 * Redistributes clusters so that no team contains two clusters of the same boy/girl, younger/older category.
 * If a conflict is found, attempts to swap with another team to resolve it.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @param array $clusterInfo Output of collectClusterInfo (clusterCode => info array)
 * @return array New teams array with category conflicts resolved
 */
function enforceCategoryUniqueness(array $teams, array $clusterInfo): array {
    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2]; // e.g. by, sy, bo, so
        }
        return null;
    };

    $maxIterations = 10;
    for ($iter = 0; $iter < $maxIterations; $iter++) {
        $fixed = true;
        foreach ($teams as $teamIdx => $team) {
            $categories = [];
            foreach ($team as $i => $clusterCode) {
                $cat = $getCategory($clusterCode);
                if ($cat === null) continue;
                if (isset($categories[$cat])) {
                    // Conflict: two clusters of same category in this team
                    // Try to swap with another team to resolve
                    foreach ($teams as $otherIdx => $otherTeam) {
                        if ($otherIdx == $teamIdx) continue;
                        foreach ($otherTeam as $j => $otherCluster) {
                            $otherCat = $getCategory($otherCluster);
                            // Only swap if it doesn't introduce a new conflict in the other team
                            $otherTeamCats = array_map($getCategory, $otherTeam);
                            if (!in_array($cat, $otherTeamCats)) {
                                // Swap clusters
                                $teams[$teamIdx][$i] = $otherCluster;
                                $teams[$otherIdx][$j] = $clusterCode;
                                $fixed = false;
                                break 3;
                            }
                        }
                    }
                } else {
                    $categories[$cat] = true;
                }
            }
        }
        if ($fixed) break;
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

function reportTeamLanguagesAndTranslations(array $teams, array $clusterInfo): void {
    foreach ($teams as $teamIndex => $team) {
        $spoken = [];
        $canTranslate = [];
        foreach ($team as $clusterCode) {
            if (!empty($clusterInfo[$clusterCode]['languages'])) {
                $spoken = array_merge($spoken, $clusterInfo[$clusterCode]['languages']);
            }
            if (!empty($clusterInfo[$clusterCode]['translationlanguages'])) {
                $canTranslate = array_merge($canTranslate, $clusterInfo[$clusterCode]['translationlanguages']);
            }
        }
        $spoken = array_unique($spoken);
        $canTranslate = array_unique($canTranslate);

        // Normalize: ignore English for translation, treat Ukrainian and Russian as equal
        $normalize = function($lang) {
            $lang = strtolower(trim($lang));
            if ($lang === 'ukrainian' || $lang === 'ukrain') $lang = 'russian';
            if ($lang === 'english') return null;
            return $lang;
        };

        $spokenNorm = array_filter(array_map($normalize, $spoken));
        $canTranslateNorm = array_filter(array_map($normalize, $canTranslate));

        echo "Team " . ($teamIndex + 1) . ":\n";
        echo "  Languages spoken: " . (empty($spokenNorm) ? "(none)" : implode(", ", $spokenNorm)) . "\n";
        echo "  Can translate: " . (empty($canTranslateNorm) ? "(none)" : implode(", ", $canTranslateNorm)) . "\n";

        // Check for spoken languages not covered by translation
        $missing = array_diff($spokenNorm, $canTranslateNorm);
        if (!empty($missing)) {
            echo "  WARNING: No translator for: " . implode(", ", $missing) . "\n";
        }
    }
}

// Print the associative array
//$clusterCodes = filterAndExtractClusterCodes($full_file["Cluster"]);
$clusterInfo = collectClusterInfo($full_file);
print_r($clusterInfo);
$teams = distributeClustersToTeams($clusterInfo);
balanceTeamLanguages($teams, $clusterInfo);
print_r($teams);
$teams = balanceTeamLeaders($teams, $clusterInfo);
$teams = balanceTeamLeaders(array_reverse($teams), $clusterInfo);
$teams = enforceCategoryUniqueness($teams, $clusterInfo);
print_r($teams);
$teamSizes = calculateTeamSizes($teams, $clusterInfo);
echo "Team sizes: " . implode(", ", $teamSizes) . "\n";
printTeamPossibleLeaders($teams, $clusterInfo);
reportTeamLanguagesAndTranslations($teams, $clusterInfo);