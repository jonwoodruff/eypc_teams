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
    $numRows = isset($full_file['Cluster']) ? count($full_file['Cluster']) : 0;

    // Normalizer: trims, extracts "other (...)" and maps Cyrillic -> English, lowercases.
    $normalizeLang = function(string $lang): string {
        $lang = trim($lang, " \t\n\r\0\x0B;,:.()[]");
        if ($lang === '') return '';
        // If form is "other (korean)" or malformed like "other (korean" -> extract "korean"
        if (preg_match('/^\s*other\s*\(\s*([^)]+)\s*\)?\s*$/i', $lang, $m)) {
            $lang = trim($m[1]);
        } elseif (preg_match('/^\s*other\s*[:\-]\s*(.+)$/i', $lang, $m)) {
            // handle "other: korean" or "other - korean"
            $lang = trim($m[1]);
        }
        $langLower = mb_strtolower($lang, 'UTF-8');

        // Cyrillic -> latin mappings
        if (preg_match('/русск/ui', $langLower)) return 'russian';
        if (preg_match('/украин/ui', $langLower)) return 'ukrainian';

        // Common fixes
        if (strpos($langLower, 'hinese') !== false) return 'chinese';

        // return normalized lower-case token
        return $langLower;
    };

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
        $needtranslation = $full_file['NeedTranslationFromEnglishForMessages'][$i] ?? '';
        $spokenRaw = (string) ($full_file['SpokenLanguage'][$i] ?? '');
        if ($spokenRaw !== '' && strcasecmp($needtranslation, 'Yes') === 0) {
            // Split SpokenLanguage into multiple entries (handles "Polish;, Russian;, Ukrainian" etc.)
            $langs = preg_split('/[;,\/]+|\band\b/i', $spokenRaw);
            foreach ($langs as $lang) {
                $norm = $normalizeLang((string)$lang);
                if ($norm === '') continue;
                $clusters[$cluster]['languages'][] = $norm;
            }
        }

        // Collect CanTranslate / CanHelpTranslate, splitting multiple languages
        $canHelpRaw = (string) (
            $full_file['CanHelpTranslate'][$i]
            ?? $full_file['CanTranslate'][$i]
            ?? ''
        );
        if ($canHelpRaw !== '') {
            $langs = preg_split('/[;,\/]+|\band\b/i', $canHelpRaw);
            foreach ($langs as $lang) {
                $norm = $normalizeLang((string)$lang);
                if ($norm === '') continue;
                $clusters[$cluster]['translationlanguages'][] = $norm;
            }
        }

        // Add SpokenLanguage and AlsoFluentIn as translation languages if SpokenEnglish is Fluent or Fair
        $spokenEnglish = $full_file['SpokenEnglish'][$i] ?? '';
        if (strcasecmp($spokenEnglish, 'Fluent') === 0 || strcasecmp($spokenEnglish, 'Fair') === 0) {
            if ($spokenRaw !== '') {
                $langs = preg_split('/[;,\/]+|\band\b/i', $spokenRaw);
                foreach ($langs as $lang) {
                    $norm = $normalizeLang((string)$lang);
                    if ($norm === '') continue;
                    $clusters[$cluster]['translationlanguages'][] = $norm;
                }
            }
            // AlsoFluentIn (may be multiple, split)
            $fluentRaw = (string) ($full_file['AlsoFluentIn'][$i] ?? '');
            if ($fluentRaw !== '') {
                $fluentLangs = preg_split('/[;,\/]+|\band\b/i', $fluentRaw);
                foreach ($fluentLangs as $lang) {
                    $norm = $normalizeLang((string)$lang);
                    if ($norm === '') continue;
                    $clusters[$cluster]['translationlanguages'][] = $norm;
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
 * Distributes clusters into 48 teams, prioritizing even team sizes over country mixing.
 *
 * @param array $clusters Associative array: key = cluster code, value = array with 'size', 'languages', etc.
 * @return array Array of 48 teams, each a list of cluster codes
 */
function distributeClustersToTeams(array $clusters): array {
    $teams = array_fill(0, 48, []);
    $teamProfiles = array_fill(0, 48, []); // Track gender/age/country per team

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
        for ($i = 0; $i < 48; $i++) {
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
        $lang = strtolower(trim((string)$lang));
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

        // Deduplicate and reindex so there are no repeated language entries
        $spoken = array_values(array_unique($spoken));
        $canTranslate = array_values(array_unique($canTranslate));

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
    $teamCount = count($teams);

    // Phase 1: per-category leveling (no team should have > min+1 of a category)
    $maxPhase1Iter = 40;
    for ($iter = 0; $iter < $maxPhase1Iter; $iter++) {
        $changed = false;

        foreach (['by','sy','bo','so'] as $cat) {
            // build counts and positions
            $catCounts = array_fill(0, $teamCount, 0);
            $catPositions = array_fill(0, $teamCount, []);
            foreach ($teams as $tIdx => $team) {
                foreach ($team as $pos => $code) {
                    if ($getCategory($code) === $cat) {
                        $catCounts[$tIdx]++;
                        $catPositions[$tIdx][] = $pos;
                    }
                }
            }

            $maxCount = max($catCounts);
            $minCount = min($catCounts);

            // move until max <= min+1
            while ($maxCount > $minCount + 1) {
                $fromTeam = array_search($maxCount, $catCounts, true);
                $toTeam = array_search($minCount, $catCounts, true);
                if ($fromTeam === false || $toTeam === false) break;

                $positions = $catPositions[$fromTeam];
                if (empty($positions)) break;
                $movePos = end($positions);

                $clusterToMove = $teams[$fromTeam][$movePos];
                array_splice($teams[$fromTeam], $movePos, 1);
                $teams[$toTeam][] = $clusterToMove;

                // rebuild counts & positions for affected teams
                foreach ([$fromTeam, $toTeam] as $tIdx) {
                    $catCounts[$tIdx] = 0;
                    $catPositions[$tIdx] = [];
                    foreach ($teams[$tIdx] as $pos => $code) {
                        if ($getCategory($code) === $cat) {
                            $catCounts[$tIdx]++;
                            $catPositions[$tIdx][] = $pos;
                        }
                    }
                }

                $maxCount = max($catCounts);
                $minCount = min($catCounts);
                $changed = true;
            }
        }

        if (!$changed) break;
    }

    // Phase 2: distribute younger clusters (by/sy) more evenly across teams
    $maxPhase2Iter = 200;
    for ($iter = 0; $iter < $maxPhase2Iter; $iter++) {
        $yCounts = array_fill(0, $teamCount, 0);
        $yPositions = array_fill(0, $teamCount, []);
        $totalY = 0;
        for ($t = 0; $t < $teamCount; $t++) {
            foreach ($teams[$t] as $pos => $code) {
                $cat = $getCategory($code);
                if ($cat === 'by' || $cat === 'sy') {
                    $yCounts[$t]++;
                    $yPositions[$t][] = $pos;
                    $totalY++;
                }
            }
        }

        if ($totalY === 0) break;
        $target = (int) ceil($totalY / $teamCount);

        $maxY = max($yCounts);
        $minY = min($yCounts);
        if ($maxY <= $target && $minY >= max(0, $target-1)) break;

        $fromTeam = null;
        $toTeam = null;
        for ($t = 0; $t < $teamCount; $t++) {
            if ($yCounts[$t] > $target) { $fromTeam = $t; break; }
        }
        for ($t = 0; $t < $teamCount; $t++) {
            if ($yCounts[$t] < $target) { $toTeam = $t; break; }
        }

        if ($fromTeam === null || $toTeam === null) break;

        $movePos = null;
        $candidatePos = $yPositions[$fromTeam];
        $toCats = [];
        foreach ($teams[$toTeam] as $code) {
            $c = $getCategory($code);
            if ($c) $toCats[$c] = true;
        }
        foreach ($candidatePos as $pos) {
            $code = $teams[$fromTeam][$pos];
            $c = $getCategory($code); // by or sy
            if (!isset($toCats[$c])) { $movePos = $pos; break; }
        }
        if ($movePos === null) {
            $movePos = end($candidatePos);
            if ($movePos === false) break;
        }

        $clusterToMove = $teams[$fromTeam][$movePos];
        array_splice($teams[$fromTeam], $movePos, 1);
        $teams[$toTeam][] = $clusterToMove;
    }

    // Phase 3: fix teams that are old-only / old-heavy by swapping in extra younger clusters from donors
    $maxPhase3Iter = 200;
    for ($iter = 0; $iter < $maxPhase3Iter; $iter++) {
        $changed = false;

        // compute per-team y counts and positions, and old positions
        $yCounts = array_fill(0, $teamCount, 0);
        $yPositions = array_fill(0, $teamCount, []);
        $oldCounts = array_fill(0, $teamCount, 0);
        $oldPositions = array_fill(0, $teamCount, []);
        for ($t = 0; $t < $teamCount; $t++) {
            foreach ($teams[$t] as $pos => $code) {
                $cat = $getCategory($code);
                if ($cat === 'by' || $cat === 'sy') {
                    $yCounts[$t]++; $yPositions[$t][] = $pos;
                } elseif ($cat === 'bo' || $cat === 'so') {
                    $oldCounts[$t]++; $oldPositions[$t][] = $pos;
                }
            }
        }

        // identify target teams: zero yCounts and at least 2 old clusters (so/bo)
        $targets = [];
        foreach ($teams as $t => $_) {
            if ($yCounts[$t] === 0 && $oldCounts[$t] >= 2) $targets[] = $t;
        }

        if (empty($targets)) break;

        // identify donor teams: yCounts > 1 (have an extra young cluster)
        $donors = [];
        foreach ($teams as $t => $_) {
            if ($yCounts[$t] > 1) $donors[] = $t;
        }

        if (empty($donors)) break;

        // Try to move one y cluster from a donor into each target, swapping with an old duplicate
        foreach ($targets as $target) {
            if ($yCounts[$target] > 0) continue; // may have changed
            // prefer swapping an old cluster that is duplicated category (if any)
            // find an old category that occurs >1 and pick its second occurrence
            $targetOldCats = [];
            foreach ($teams[$target] as $pos => $code) {
                $cat = $getCategory($code);
                if ($cat === 'bo' || $cat === 'so') {
                    $targetOldCats[$cat][] = $pos;
                }
            }

            $swapPos = null;
            foreach (['so','bo'] as $oldCat) {
                if (!empty($targetOldCats[$oldCat]) && count($targetOldCats[$oldCat]) > 1) {
                    $swapPos = $targetOldCats[$oldCat][1]; // second occurrence
                    break;
                }
            }
            // if no duplicate-specific found, just pick last old position
            if ($swapPos === null && !empty($oldPositions[$target])) {
                $swapPos = end($oldPositions[$target]);
            }
            if ($swapPos === null) continue;

            // find a donor and a y-cluster to swap in
            $performed = false;
            foreach ($donors as $dIdx) {
                if ($yCounts[$dIdx] <= 1) continue;
                // find candidate y positions in donor
                foreach ($yPositions[$dIdx] as $dPos) {
                    // pick this donor cluster
                    $donorCluster = $teams[$dIdx][$dPos];
                    // perform swap
                    $tmp = $teams[$target][$swapPos];
                    $teams[$target][$swapPos] = $teams[$dIdx][$dPos];
                    $teams[$dIdx][$dPos] = $tmp;

                    $changed = true;
                    $performed = true;
                    break 2;
                }
            }
            // after one swap, recompute next iteration
            if ($performed) break;
        }

        if (!$changed) break;
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

        // Normalize: ignore English for translation, treat Ukrainian and Russian as equal
        $normalize = function($lang) {
            $lang = strtolower(trim((string)$lang));
            if ($lang === '' || $lang === 'english') return null;
            if ($lang === 'ukrainian' || $lang === 'ukrain') $lang = 'russian';
            if (strpos($lang, 'hinese') !== false) $lang = 'chinese';
            return $lang;
        };

        // Normalize then remove null/empty and dedupe safely
        $spokenNorm = array_map(fn($l) => $normalize($l), $spoken);
        $spokenNorm = array_filter($spokenNorm, fn($v) => $v !== null && $v !== '');
        $spokenNorm = array_map(fn($s) => trim((string)$s), $spokenNorm);
        $spokenNorm = array_values(array_unique(array_map('strtolower', $spokenNorm)));

        $canTranslateNorm = array_map(fn($l) => $normalize($l), $canTranslate);
        $canTranslateNorm = array_filter($canTranslateNorm, fn($v) => $v !== null && $v !== '');
        $canTranslateNorm = array_map(fn($s) => trim((string)$s), $canTranslateNorm);
        $canTranslateNorm = array_values(array_unique(array_map('strtolower', $canTranslateNorm)));

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

function writeTeamsSummaryCSV(array $teams, array $clusterInfo, array $teamLeaders, string $filename = "teams.csv"): void {
    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2]; // e.g. by, sy, bo, so
        }
        return null;
    };

    // Helper to normalize language names
    $normalize = function($lang) {
        $lang = strtolower(trim((string)$lang));
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
        "PotentialLeaders",
        "Leader"
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
        $spokenNorm = array_map(function($l) use ($normalize) {
            return $normalize((string)$l);
        }, $spoken);
        $canTranslateNorm = array_map(function($l) use ($normalize) {
            return $normalize((string)$l);
        }, $canTranslate);

        // Cast to string before trim so trim() never receives null, then remove empty entries
        $spokenNorm = array_map(function($s) { return trim((string)$s); }, $spokenNorm);
        $canTranslateNorm = array_map(function($s) { return trim((string)$s); }, $canTranslateNorm);

        $spokenNorm = array_values(array_unique(array_filter($spokenNorm, function($v) { return $v !== ''; })));
        $canTranslateNorm = array_values(array_unique(array_filter($canTranslateNorm, function($v) { return $v !== ''; })));

        // Final lowercasing + dedupe to collapse equivalents
        $spokenNorm = array_values(array_unique(array_map('strtolower', $spokenNorm)));
        $canTranslateNorm = array_values(array_unique(array_map('strtolower', $canTranslateNorm)));

        // Languages missing translation
        $missing = array_diff($spokenNorm, $canTranslateNorm);

        // Get best leader for this team
        $leader = $teamLeaders[$teamNum] ?? '';

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
            implode("; ", array_unique($leaders)),
            $leader
        ];
        fputcsv($file, $row, ",", '"', "\\");
    }

    fclose($file);
    echo "Team summary written to $filename\n";
}

/**
 * Swaps clusters between teams so that both specified clusters end up in the same team.
 * If already together, does nothing. If not, swaps the second cluster into the team with the first,
 * swapping with a cluster of the same category if possible.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @param string $clusterA First cluster code
 * @param string $clusterB Second cluster code
 * @return array Modified teams array
 */
function putClustersTogether(array $teams, string $clusterA, string $clusterB): array {
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

    // Helper to extract category from cluster code
    $getCategory = function($clusterCode) {
        if (preg_match('/^([bs])([yo])/', $clusterCode, $m)) {
            return $m[1] . $m[2];
        }
        return null;
    };

    $catB = $getCategory($clusterB);

    // Try to swap clusterB into teamA by swapping with a cluster of the same category
    foreach ($teams[$teamA] as $swapIdx => $swapCluster) {
        if ($swapCluster === $clusterA) continue; // Don't swap clusterA out
        if ($getCategory($swapCluster) === $catB) {
            // Swap clusterB and this cluster
            $teams[$teamA][$swapIdx] = $clusterB;
            $teams[$teamB][$idxB] = $swapCluster;
            echo "Swapped $clusterB into team with $clusterA.\n";
            return $teams;
        }
    }

    // If no suitable swap found, just move clusterB into teamA (may increase category count)
    array_splice($teams[$teamB], $idxB, 1);
    $teams[$teamA][] = $clusterB;
    echo "Moved $clusterB into team with $clusterA (no swap).\n";
    return $teams;
}

/**
 * For each pair of clusters in a static list, calls putClustersTogether to ensure both clusters are in the same team.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @return array Modified teams array
 */
function putRequestedClusterPairsTogether(array $teams): array {
    // Example pairs to put together
    $pairs = [
        //['soOT01', 'soRO01'],
         // add more pairs as needed
    ];

    foreach ($pairs as $pair) {
        if (count($pair) == 2) {
            $teams = putClustersTogether($teams, $pair[0], $pair[1]);
        }
    }
    return $teams;
}

/**
 * Returns the best potential team leader from the clusters in a team, using $full_file for details.
 * Priority: 1) Over 30 years old (AgeAtConf), 2) Brother (Gender), 3) Otherwise first in list.
 *
 * @param array $team Array of cluster codes in the team
 * @param array $full_file Full registrant data
 * @return string|null Best leader name or null if none found
 */
function getBestTeamLeader(array $team, array $full_file): ?string {
    $potentialLeaders = [];

    // Build a map from cluster code to indices in $full_file
    $clusterIndices = [];
    foreach ($team as $clusterCode) {
        foreach ($full_file['Cluster'] as $i => $clusterVal) {
            if (preg_match('/\b[bs][yo][A-Z]{2}\d+[ab]?\b/', $clusterVal, $matches) && $matches[0] === $clusterCode) {
                $clusterIndices[$clusterCode][] = $i;
            }
        }
    }

    // Gather all potential leaders from these clusters
    foreach ($clusterIndices as $clusterCode => $indices) {
        foreach ($indices as $i) {
            if (!empty($full_file['PotentialTL_Shortlist'][$i]) && stripos($full_file['PotentialTL_Shortlist'][$i], 'yes') !== false) {
                $name = trim(($full_file['FirstName'][$i] ?? '') . ' ' . ($full_file['LastName'][$i] ?? ''));
                if ($name !== '') {
                    $potentialLeaders[] = [
                        'name' => $name,
                        'age' => intval($full_file['AgeAtConf'][$i] ?? 0),
                        'gender' => strtolower($full_file['Gender'][$i] ?? ''),
                    ];
                }
            }
        }
    }

    if (empty($potentialLeaders)) return null;

    // 1. Prioritize over 30 years old
    $over30 = array_filter($potentialLeaders, fn($p) => $p['age'] > 30);
    if (!empty($over30)) {
        // 2. Prioritize Brother
        $brotherOver30 = array_filter($over30, fn($p) => $p['gender'] === 'brother');
        if (!empty($brotherOver30)) {
            return $brotherOver30[array_key_first($brotherOver30)]['name'];
        }
        return $over30[array_key_first($over30)]['name'];
    }

    // 2. Prioritize Brother
    $brother = array_filter($potentialLeaders, fn($p) => $p['gender'] === 'brother');
    if (!empty($brother)) {
        return $brother[array_key_first($brother)]['name'];
    }

    // 3. Otherwise, first in list
    return $potentialLeaders[0]['name'];
}

/**
 * Returns an array of best team leaders for each team.
 *
 * @param array $teams Array of teams (each is an array of cluster codes)
 * @param array $full_file Full registrant data
 * @return array Array of best leader names (or null) indexed by team number (starting from 1)
 */
function getAllBestTeamLeaders(array $teams, array $full_file): array {
    $leaders = [];
    foreach ($teams as $teamIndex => $team) {
        $leaders[$teamIndex + 1] = getBestTeamLeader($team, $full_file);
    }
    return $leaders;
}

// Print the associative array
//$clusterCodes = filterAndExtractClusterCodes($full_file["Cluster"]);
$clusterInfo = collectClusterInfo($full_file);
//print_r($clusterInfo);
$teams = distributeClustersToTeams($clusterInfo);
$teams = enforceCategoryUniqueness($teams, $clusterInfo);
$teams = balanceTeamSizes($teams, $clusterInfo);
//$teams = balanceTeamLanguages($teams, $clusterInfo);
//$teams = putRequestedClusterPairsTogether($teams);
//$teams = balanceTeamLeaders($teams, $clusterInfo);
//$teams = enforceCategoryUniqueness($teams, $clusterInfo);
//$teams = putRequestedClusterPairsTogether($teams);

//print_r($teams);
$teamSizes = calculateTeamSizes($teams, $clusterInfo);
$teamLeaders = getAllBestTeamLeaders($teams, $full_file);
echo "Team sizes: " . implode(", ", $teamSizes) . "\n";
//printTeamPossibleLeaders($teams, $clusterInfo);
reportTeamLanguagesAndTranslations($teams, $clusterInfo);
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

 writeTeamsSummaryCSV($teams, $clusterInfo, $teamLeaders, "teams.csv");
