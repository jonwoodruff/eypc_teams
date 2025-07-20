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
                'possibleleader' => [],
                'yp_count' => 0,
                'serving_count' => 0
            ];
        }

        // Increment size
        $clusters[$cluster]['size']++;

        // Count YP and Serving
        $status = $full_file['StatusEYPC'][$i] ?? '';
        if (strcasecmp($status, 'YP') === 0) {
            $clusters[$cluster]['yp_count']++;
        }
        if (stripos($status, 'Serving') !== false) {
            $clusters[$cluster]['serving_count']++;
        }

        // Collect language only if English is Poor or None
        $needtranslation = $full_file['NeedTranslationFromEnglish'][$i] ?? '';
        if (
            (!empty($full_file['SpokenLanguage'][$i])) &&
            (strcasecmp($needtranslation, 'Yes') === 0)
        ) {
            $clusters[$cluster]['languages'][] = $full_file['SpokenLanguage'][$i];
        }

        // Collect CanTranslate, splitting multiple languages
        if (!empty($full_file['CanTranslate'][$i])) {
            $langs = preg_split('/\W+/', $full_file['CanTranslate'][$i], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($langs as $lang) {
                $clusters[$cluster]['translationlanguages'][] = $lang;
            }
        }

        // Add SpokenLanguage and AlsoFluentIn as translation languages if SpokenEnglish is Fluent or Fair
        $spokenEnglish = $full_file['SpokenEnglish'][$i] ?? '';
        if (strcasecmp($spokenEnglish, 'Fluent') === 0 || strcasecmp($spokenEnglish, 'Fair') === 0) {
            // SpokenLanguage
            if (!empty($full_file['SpokenLanguage'][$i])) {
                $clusters[$cluster]['translationlanguages'][] = $full_file['SpokenLanguage'][$i];
            }
            // AlsoFluentIn (may be multiple, split)
            if (!empty($full_file['AlsoFluentIn'][$i])) {
                $fluentLangs = preg_split('/\W+/', $full_file['AlsoFluentIn'][$i], -1, PREG_SPLIT_NO_EMPTY);
                foreach ($fluentLangs as $lang) {
                    $clusters[$cluster]['translationlanguages'][] = $lang;
                }
            }
        }

        // Collect possible team possibleleader
        if (!empty($full_file['PotentialTL_Shortlist'][$i]) && stripos($full_file['PotentialTL_Shortlist'][$i], 'yes') !== false) {
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
    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2]; // e.g. by, sy, bo, so
        }
        return null;
    };

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

    // Try to swap clusters: give a leader cluster from a team with >1 to a team with 0, only if same category
    foreach ($teamsWithoutLeaders as $noLeaderIdx) {
        foreach ($teamsWithLeaders as $withLeaderIdx) {
            // Find a cluster with a leader in the donor team
            foreach ($teams[$withLeaderIdx] as $donorKey => $donorCluster) {
                if ($hasLeader[$donorCluster]) {
                    $catDonor = $getCategory($donorCluster);
                    // Find a cluster without a leader in the recipient team, same category
                    foreach ($teams[$noLeaderIdx] as $recipientKey => $recipientCluster) {
                        $catRecipient = $getCategory($recipientCluster);
                        if (!$hasLeader[$recipientCluster] && $catDonor === $catRecipient) {
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
        // Map teams to clusters with/without leaders
        $teamsWithLeaders = [];
        foreach ($teams as $i => $team) {
            $leaderCount = 0;
            foreach ($team as $clusterCode) {
                if (!empty($hasLeader[$clusterCode])) {
                    $leaderCount++;
                }
            }
            if ($leaderCount > 1) {
                $teamsWithLeaders[] = $i;
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
        if (strpos($lang, 'hinese') !== false) $lang = 'chinese';
        if ($lang === 'english') return null;
        return $lang;
    };

    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2]; // e.g. by, sy, bo, so
        }
        return null;
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
                        $catA = $getCategory($clusterCode);
                        // Look for a cluster in another team that can translate $lang and is the same category
                        foreach ($teams as $otherIdx => $otherTeam) {
                            if ($otherIdx == $teamIdx) continue;
                            foreach ($otherTeam as $j => $otherCluster) {
                                $catB = $getCategory($otherCluster);
                                if ($catA !== $catB) continue; // Only swap same category
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

function moveClusterToRandomTeam(array &$teams, string $clusterName): void {
    $currentTeamIdx = null;
    $clusterIdx = null;

    // Find the current team and index of the cluster
    foreach ($teams as $teamIdx => $team) {
        foreach ($team as $i => $code) {
            if ($code === $clusterName) {
                $currentTeamIdx = $teamIdx;
                $clusterIdx = $i;
                break 2;
            }
        }
    }

    // If not found, do nothing
    if ($currentTeamIdx === null || $clusterIdx === null) {
        echo "Cluster $clusterName not found in any team.\n";
        return;
    }

    // Remove from current team
    array_splice($teams[$currentTeamIdx], $clusterIdx, 1);

    // Pick a random other team
    $teamCount = count($teams);
    $otherTeams = array_diff(range(0, $teamCount - 1), [$currentTeamIdx]);
    $randomTeamIdx = $otherTeams[array_rand($otherTeams)];

    // Add to random team
    $teams[$randomTeamIdx][] = $clusterName;

    echo "Moved cluster $clusterName from Team " . ($currentTeamIdx + 1) . " to Team " . ($randomTeamIdx + 1) . "\n";
}

function fixMultipleDuplicateCategories(array $teams): array {
    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2]; // e.g. by, sy, bo, so
        }
        return null;
    };

    $categories = ['by', 'sy', 'bo', 'so'];
    $maxIterations = 20;
    $changed = true;
    for ($iter = 0; $iter < $maxIterations && $changed; $iter++) {
        $changed = false;
        foreach ($teams as $teamIdx => $team) {
            // Count categories in this team
            $catCounts = [];
            $catPositions = [];
            foreach ($team as $i => $clusterCode) {
                $cat = $getCategory($clusterCode);
                if ($cat) {
                    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
                    $catPositions[$cat][] = $i;
                }
            }
            // Find all categories with duplicates in this team
            $dupeCats = [];
            foreach ($catCounts as $cat => $count) {
                if ($count > 2) {
                    moveClusterToRandomTeam($teams, $team[$catPositions[$cat][1]]);
                    $changed = true;
                } else if ($count > 1) $dupeCats[] = $cat;
            }
            if (count($dupeCats) > 1 && !$changed) {
                // Move the second occurrence of the first duplicate category
                moveClusterToRandomTeam($teams, $team[$catPositions[$dupeCats[0]][1]]);
                $changed = true;
            }
            if ($changed) break; // Restart the loop if we made a change
        }
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

    $categories = ['by', 'sy', 'bo', 'so'];
    $maxIterations = 40;
    for ($iter = 0; $iter < $maxIterations; $iter++) {
        $changed = false;
        foreach ($categories as $cat) {
            // Build lists of teams by count of this category
            $catCounts = [];
            $catPositions = []; // [teamIdx => [clusterIdx, ...]]
            foreach ($teams as $teamIdx => $team) {
                $catClusters = [];
                foreach ($team as $i => $clusterCode) {
                    if ($getCategory($clusterCode) === $cat) {
                        $catClusters[] = $i;
                    }
                }
                $catPositions[$teamIdx] = $catClusters;
                $catCounts[$teamIdx] = count($catClusters);
            }

            // While any team has more than min+1 of this category, and another team has less
            while (true) {
                $maxCount = max($catCounts);
                $minCount = min($catCounts);
                // Don't allow a team to have more than min+1 unless all teams have at least min+1
                if ($maxCount <= $minCount + 1) break;

                // Find a team with maxCount and a team with minCount
                $fromTeam = array_search($maxCount, $catCounts);
                $toTeam = array_search($minCount, $catCounts);

                // Move one of the extras (not the first, to keep at least min+1)
                $moveIdx = $catPositions[$fromTeam][$minCount + 1]; // e.g. 2nd if min=1, 3rd if min=2
                $clusterToMove = $teams[$fromTeam][$moveIdx];
                // Remove from fromTeam
                array_splice($teams[$fromTeam], $moveIdx, 1);
                // Add to toTeam
                $teams[$toTeam][] = $clusterToMove;
                $changed = true;

                // Update counts and positions for next round
                // Rebuild only for affected teams
                foreach ([$fromTeam, $toTeam] as $teamIdx) {
                    $catClusters = [];
                    foreach ($teams[$teamIdx] as $i => $clusterCode) {
                        if ($getCategory($clusterCode) === $cat) {
                            $catClusters[] = $i;
                        }
                    }
                    $catPositions[$teamIdx] = $catClusters;
                    $catCounts[$teamIdx] = count($catClusters);
                }
            }
        }
        if (!$changed) break;
    }
    return fixMultipleDuplicateCategories($teams);
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
            if (strpos($lang, 'hinese') !== false) $lang = 'chinese';
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

/**
 * Attempts to balance the number of people in each team as evenly as possible by swapping clusters.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @param array $clusterInfo Output of collectClusterInfo (clusterCode => info array)
 * @param int $maxIterations Maximum number of balancing passes
 * @return array New teams array with more balanced team sizes
 */
function balanceTeamSizes(array $teams, array $clusterInfo, int $maxIterations = 20): array {
    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2]; // e.g. by, sy, bo, so
        }
        return null;
    };

    for ($iter = 0; $iter < $maxIterations; $iter++) {
        // Calculate team sizes
        $teamSizes = [];
        foreach ($teams as $idx => $team) {
            $size = 0;
            foreach ($team as $clusterCode) {
                $size += $clusterInfo[$clusterCode]['size'] ?? 0;
            }
            $teamSizes[$idx] = $size;
        }

        $maxIdx = array_keys($teamSizes, max($teamSizes))[0];
        $minIdx = array_keys($teamSizes, min($teamSizes))[0];

        // If already balanced (difference <= 1), stop
        if ($teamSizes[$maxIdx] - $teamSizes[$minIdx] <= 1) {
            break;
        }

        $bestSwap = null;
        $bestDiff = $teamSizes[$maxIdx] - $teamSizes[$minIdx];

        // Try all swaps between max and min teams, but only for clusters of the same category
        foreach ($teams[$maxIdx] as $i => $clusterA) {
            $catA = $getCategory($clusterA);
            if ($catA === null) continue;
            foreach ($teams[$minIdx] as $j => $clusterB) {
                $catB = $getCategory($clusterB);
                if ($catA !== $catB) continue; // Only swap same category

                $sizeA = $clusterInfo[$clusterA]['size'] ?? 0;
                $sizeB = $clusterInfo[$clusterB]['size'] ?? 0;

                // Simulate swap
                $newMax = $teamSizes[$maxIdx] - $sizeA + $sizeB;
                $newMin = $teamSizes[$minIdx] - $sizeB + $sizeA;
                $diff = abs($newMax - $newMin);

                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestSwap = [$i, $j];
                }
            }
        }

        // If a swap improves balance, do it
        if ($bestSwap) {
            [$i, $j] = $bestSwap;
            $tmp = $teams[$maxIdx][$i];
            $teams[$maxIdx][$i] = $teams[$minIdx][$j];
            $teams[$minIdx][$j] = $tmp;
        } else {
            // No improving swap found, stop
            break;
        }
    }
    return $teams;
}

function printClusterAssignmentsCSV(array $teams): void {
    foreach ($teams as $teamIndex => $team) {
        $teamNum = $teamIndex + 1;
        foreach ($team as $clusterCode) {
            echo $clusterCode . "," . $teamNum . "\n";
        }
    }
}

function assignTeamsToFullFile(array $teams, array $full_file): array {
    // Build a map from cluster code to team number
    $clusterToTeam = [];
    foreach ($teams as $teamIndex => $team) {
        $teamNum = $teamIndex + 1;
        foreach ($team as $clusterCode) {
            $clusterToTeam[$clusterCode] = $teamNum;
        }
    }

    // Add a new column "TeamNumber" to $full_file
    if (!in_array('TeamNumber', $full_file)) {
        $full_file['TeamNumber'] = [];
    }

    $numRows = count($full_file['Cluster']);
    for ($i = 0; $i < $numRows; $i++) {
        $clusterCode = null;
        if (preg_match('/\b[bs][yo][A-Z]{2}\d+[ab]?\b/', $full_file['Cluster'][$i], $matches)) {
            $clusterCode = $matches[0];
        }
        $full_file['TeamNumber'][$i] = $clusterCode && isset($clusterToTeam[$clusterCode])
            ? $clusterToTeam[$clusterCode]
            : null;
    }

    return $full_file;
}

function writeTeamsSummaryCSV(array $teams, array $clusterInfo, string $filename = "teams.csv"): void {
    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2]; // e.g. by, sy, bo, so
        }
        return null;
    };

    // Helper to normalize language names
    $normalize = function($lang) {
        $lang = strtolower(trim($lang));
        if ($lang === 'ukrainian' || $lang === 'ukrain') $lang = 'russian';
        if (strpos($lang, 'hinese') !== false) $lang = 'chinese';
        if ($lang === 'english') return null;
        return $lang;
    };

    $file = fopen($filename, "w");
    if ($file === false) {
        echo "Failed to open $filename for writing.\n";
        return;
    }

    // Write header
    $header = [
        "TeamNumber",
        "TeamSize",
        "YPCount",
        "ServingCount",
        "by_clusters",
        "sy_clusters",
        "bo_clusters",
        "so_clusters",
        "TranslationNeeded",
        "TranslationAvailable",
        "TranslationMissing",
        "PotentialLeaders"
    ];
    fputcsv($file, $header, ",", '"', "\\");

    foreach ($teams as $teamIndex => $team) {
        $teamNum = $teamIndex + 1;
        $teamSize = 0;
        $ypCount = 0;
        $servingCount = 0;
        $categories = ['by' => [], 'sy' => [], 'bo' => [], 'so' => []];
        $spoken = [];
        $canTranslate = [];
        $leaders = [];

        foreach ($team as $clusterCode) {
            $cat = $getCategory($clusterCode);
            if ($cat && isset($categories[$cat])) {
                $categories[$cat][] = $clusterCode;
            }
            $teamSize += $clusterInfo[$clusterCode]['size'] ?? 0;
            $ypCount += $clusterInfo[$clusterCode]['yp_count'] ?? 0;
            $servingCount += $clusterInfo[$clusterCode]['serving_count'] ?? 0;
            if (!empty($clusterInfo[$clusterCode]['languages'])) {
                $spoken = array_merge($spoken, $clusterInfo[$clusterCode]['languages']);
            }
            if (!empty($clusterInfo[$clusterCode]['translationlanguages'])) {
                $canTranslate = array_merge($canTranslate, $clusterInfo[$clusterCode]['translationlanguages']);
            }
            if (!empty($clusterInfo[$clusterCode]['possibleleader'])) {
                $leaders = array_merge($leaders, $clusterInfo[$clusterCode]['possibleleader']);
            }
        }

        // Normalize languages
        $spokenNorm = array_filter(array_map($normalize, $spoken));
        $canTranslateNorm = array_filter(array_map($normalize, $canTranslate));
        $spokenNorm = array_unique($spokenNorm);
        $canTranslateNorm = array_unique($canTranslateNorm);

        // Languages missing translation
        $missing = array_diff($spokenNorm, $canTranslateNorm);

        // Prepare row
        $row = [
            $teamNum,
            $teamSize,
            $ypCount,
            $servingCount,
            implode(" ", $categories['by']),
            implode(" ", $categories['sy']),
            implode(" ", $categories['bo']),
            implode(" ", $categories['so']),
            implode(" ", $spokenNorm),
            implode(" ", $canTranslateNorm),
            implode(" ", $missing),
            implode("; ", array_unique($leaders))
        ];
        fputcsv($file, $row, ",", '"', "\\");
    }

    fclose($file);
    echo "Team summary written to $filename\n";
}

/**
 * This function fulfills a special request for 2025 EYPC
 * Swaps clusters between teams so that both soRO01 and soOT01 end up in the same team.
 * If already together, does nothing. If not, swaps soOT01 into the team with soRO01.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @return array Modified teams array
 */
function putSoRO01AndSoOT01Together(array $teams): array {
    $clusterA = 'soRO01';
    $clusterB = 'soOT01';
    $teamA = null;
    $teamB = null;
    $idxA = null;
    $idxB = null;

    // Find the teams and positions for both clusters
    foreach ($teams as $teamIdx => $team) {
        foreach ($team as $i => $clusterCode) {
            if ($clusterCode === $clusterA) {
                $teamA = $teamIdx;
                $idxA = $i;
            }
            if ($clusterCode === $clusterB) {
                $teamB = $teamIdx;
                $idxB = $i;
            }
        }
    }

    // If either not found, do nothing
    if ($teamA === null || $teamB === null) {
        echo "Could not find both $clusterA and $clusterB in teams.\n";
        return $teams;
    }

    // If already together, do nothing
    if ($teamA === $teamB) {
        echo "$clusterA and $clusterB are already in the same team.\n";
        return $teams;
    }

    // Move soOT01 into the team with soRO01 by swapping with a cluster of the same category (so)
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2];
        }
        return null;
    };

    // Find a cluster in teamA (soRO01's team) with the same category as soOT01 (so)
    foreach ($teams[$teamA] as $swapIdx => $swapCluster) {
        if ($swapCluster === $clusterA) continue; // Don't swap soRO01 out
        if ($getCategory($swapCluster) === 'so') {
            // Swap soOT01 and this cluster
            $teams[$teamA][$swapIdx] = $clusterB;
            $teams[$teamB][$idxB] = $swapCluster;
            echo "Swapped $clusterB into team with $clusterA.\n";
            return $teams;
        }
    }

    // If no suitable swap found, just move soOT01 into teamA (may increase so count)
    array_splice($teams[$teamB], $idxB, 1);
    $teams[$teamA][] = $clusterB;
    echo "Moved $clusterB into team with $clusterA (no swap).\n";
    return $teams;
}

// Print the associative array
//$clusterCodes = filterAndExtractClusterCodes($full_file["Cluster"]);
$clusterInfo = collectClusterInfo($full_file);
//print_r($clusterInfo);
$teams = distributeClustersToTeams($clusterInfo);
$teams = enforceCategoryUniqueness($teams, $clusterInfo);
$teams = balanceTeamSizes($teams, $clusterInfo);
$teams = balanceTeamLanguages($teams, $clusterInfo);
$teams = balanceTeamLeaders($teams, $clusterInfo);
$teams = putSoRO01AndSoOT01Together($teams);

//print_r($teams);
$teamSizes = calculateTeamSizes($teams, $clusterInfo);
//echo "Team sizes: " . implode(", ", $teamSizes) . "\n";
//printTeamPossibleLeaders($teams, $clusterInfo);
//reportTeamLanguagesAndTranslations($teams, $clusterInfo);
printClusterAssignmentsCSV($teams);

$full_file_with_teams = assignTeamsToFullFile($teams, $full_file);

// Write results to output.csv
$outputFile = fopen("registrants.csv", "w");
if ($outputFile === false) {
    echo "Failed to open registrants.csv for writing.\n";
    exit(1);
}

// Write header
$headers = array_keys($full_file_with_teams);
fputcsv($outputFile, $headers, ",", '"', "\\");

// Write each row
$numRows = count($full_file_with_teams[$headers[0]]);
for ($i = 0; $i < $numRows; $i++) {
    $row = [];
    foreach ($headers as $header) {
        $row[] = $full_file_with_teams[$header][$i] ?? '';
    }
    fputcsv($outputFile, $row, ",", '"', "\\");
}

fclose($outputFile);
echo "Results written to registrants.csv\n";

 writeTeamsSummaryCSV($teams, $clusterInfo);