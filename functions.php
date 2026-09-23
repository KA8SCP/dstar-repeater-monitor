<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function blank_status(array $r): array {
    return [
        'name' => $r['name'], 'type' => $r['type'], 'host' => $r['host'],
        'url' => $r['urls'][0], 'online' => false, 'http_code' => 0,
        'response_ms' => null, 'checked_at' => gmdate('c'),
        'uptime' => null, 'version' => null, 'drefd_version' => null,
        'xlx_version' => null, 'dashboard_version' => null, 'dcs_version' => null, 'description' => null,
        'users' => [], 'modules' => [], 'peers' => [], 'last_heard' => [],
        'error' => null,
    ];
}

function clean_text(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

function fetch_url(string $url, bool $insecureSsl = false): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_USERAGENT => 'DSTAR-Repeater-Monitor/1.0',
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => !$insecureSsl,
        CURLOPT_SSL_VERIFYHOST => $insecureSsl ? 0 : 2,
    ]);

    $t = microtime(true);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $body !== false
            && ($info['http_code'] ?? 0) >= 200
            && ($info['http_code'] ?? 0) < 400,
        'body' => is_string($body) ? $body : '',
        'http_code' => (int)($info['http_code'] ?? 0),
        'response_ms' => (int)round((microtime(true)-$t)*1000),
        'error' => $err,
        'insecure_ssl' => $insecureSsl,
    ];
}

function fetch_any(array $urls, bool $insecureSsl = false): array {
    $last = null;

    foreach ($urls as $url) {
        $r = fetch_url($url, $insecureSsl);

        if ($r['ok']) {
            return array_merge($r, ['url'=>$url]);
        }

        $last = array_merge($r, ['url'=>$url]);
    }

    return $last ?? [
        'ok' => false,
        'body' => '',
        'http_code' => 0,
        'response_ms' => null,
        'error' => 'No URL configured',
        'url' => $urls[0] ?? '',
        'insecure_ssl' => $insecureSsl,
    ];
}

function dom_from_html(string $html): ?DOMDocument {
    if ($html === '') return null;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING|LIBXML_NOERROR);
    libxml_clear_errors();
    return $dom;
}

function tables_from_dom(?DOMDocument $dom): array {
    if (!$dom) return [];
    $xp = new DOMXPath($dom);
    $out = [];
    foreach ($xp->query('//table') as $table) {
        $rows = [];
        foreach ($xp->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($xp->query('./th|./td', $tr) as $cell) {
                $txt = clean_text($cell->textContent);
                if ($txt === '') {
                    foreach ($xp->query('.//img', $cell) as $img) {
                        $alt = clean_text((string)$img->getAttribute('alt'));
                        $title = clean_text((string)$img->getAttribute('title'));
                        if ($alt !== '') { $txt = $alt; break; }
                        if ($title !== '') { $txt = $title; break; }
                    }
                }
                $cells[] = $txt;
            }
            if ($cells) $rows[] = $cells;
        }
        if ($rows) $out[] = $rows;
    }
    return $out;
}

function header_text(array $row): string {
    return strtolower(implode(' | ', array_map('strtolower', $row)));
}

function table_has(array $table, array $terms): bool {
    if (!$table) return false;
    $h = header_text($table[0]);
    foreach ($terms as $term) if (str_contains($h, strtolower($term))) return true;
    return false;
}

function find_tables(array $tables, array $terms): array {
    return array_values(array_filter($tables, fn($t) => table_has($t, $terms)));
}

function module_letters(string $s): array {
    preg_match_all('/(?<![A-Z0-9])([A-I])(?![A-Z0-9])/i', $s, $m);
    return array_values(array_unique(array_map('strtoupper', $m[1] ?? [])));
}

function extract_uptime(string $text): ?string {
    if (preg_match('/Service\s+uptime\s*:\s*(.*?)(?=\s+(?:Users|Modules|Repeaters|Peers)\b|$)/i', $text, $m)) {
        $v = clean_text($m[1]);
        return $v !== '' ? $v : null;
    }
    return null;
}

function extract_xlxd_versions(string $text): array {
    $out = ['xlx_version'=>null, 'dashboard_version'=>null];
    if (preg_match('/XLX[0-9A-Z]+\s+v([\d.]+)/i', $text, $m)) $out['xlx_version'] = 'v'.$m[1];
    if (preg_match('/Dashboard\s+v([\d.]+)/i', $text, $m)) $out['dashboard_version'] = 'v'.$m[1];
    return $out;
}

function extract_drefd_version(string $text): ?string {
    if (preg_match('/DREFD\s+version\s+([A-Za-z0-9._-]+)/i', $text, $m)) return $m[1];
    if (preg_match('/DREFD\s+v(?:ersion)?\s*([A-Za-z0-9._-]+)/i', $text, $m)) return $m[1];
    return null;
}

function extract_dcs_version(string $text): ?string {
    if (preg_match('/DCS\s+v(?:ersion)?\s*([A-Za-z0-9._-]+)/i', $text, $m)) return $m[1];
    return null;
}

function extract_dcs_uptime(string $text): ?string {
    if (preg_match('/(?:Server\s+)?Uptime\s*:\s*(.*?)(?=\s+(?:DCS\s+v|Interlink|Repeater|User|Sysop|Starttime)\b|$)/i', $text, $m)) return clean_text($m[1]);
    if (preg_match('/Starttime\s*:\s*([0-9-]+\s+[0-9:]+)/i', $text, $m)) return 'Since '.$m[1];
    return null;
}

function parse_xlxd(array $r, string $html, int $ms, string $url): array {
    $s = blank_status($r);
    $s['online'] = true; $s['http_code'] = 200; $s['response_ms'] = $ms; $s['url'] = $url;
    $dom = dom_from_html($html);
    $tables = tables_from_dom($dom);
    $plain = clean_text($dom?->textContent ?? strip_tags($html));
    $s['uptime'] = extract_uptime($plain);
    $versions = extract_xlxd_versions($plain);
    $s['xlx_version'] = $versions['xlx_version'];
    $s['dashboard_version'] = $versions['dashboard_version'];
    $s['version'] = trim(($s['xlx_version'] ?? '').(($s['xlx_version'] && $s['dashboard_version']) ? ' · ' : '').($s['dashboard_version'] ? 'Dashboard '.$s['dashboard_version'] : '')) ?: null;

    // XLXD Users / Modules table.
    foreach (find_tables($tables, ['Module','Users','DPlus']) as $t) {
        foreach (array_slice($t, 1) as $row) {
            if (!isset($row[0])) continue;
            $mod = strtoupper(trim($row[0]));
            if (preg_match('/^[A-Z]$/', $mod)) {
                $s['modules'][] = [
                    'module'=>$mod, 'name'=>$row[1] ?? '',
                    'users'=>isset($row[2]) && is_numeric($row[2]) ? (int)$row[2] : null,
                    'links'=>array_values(array_filter(array_slice($row, 3), fn($v)=>$v!==''))
                ];
            }
        }
    }

    // XLXD user/live tables. Map by header names instead of fixed column offsets.
    foreach ($tables as $t) {
        if (!$t) continue;
        $headerRow = -1; $map = [];
        foreach (array_slice($t,0,3,true) as $ri=>$hdr) {
            $lower=array_map(fn($v)=>strtolower(trim($v)),$hdr);
            if (!in_array('callsign',$lower,true)) continue;
            $headerRow=$ri;
            foreach ($lower as $i=>$h) {
                if ($h==='callsign') $map['callsign']=$i;
                elseif (str_contains($h,'flag') || str_contains($h,'country')) $map['country']=$i;
                elseif (str_contains($h,'suffix') || str_contains($h,'dprs')) $map['suffix']=$i;
                elseif (str_contains($h,'via') || str_contains($h,'peer')) $map['via']=$i;
                elseif (str_contains($h,'last heard') || str_contains($h,'last tx')) $map['last_heard']=$i;
                elseif (str_contains($h,'listening on') || $h==='module') $map['module']=$i;
            }
            break;
        }
        if ($headerRow < 0 || !isset($map['callsign'])) continue;
        foreach (array_slice($t,$headerRow+1,MAX_USERS) as $row) {
            $call=trim($row[$map['callsign']] ?? '');
            if ($call==='' || !preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,15}$/i',$call)) continue;
            $s['users'][]=[
                'country'=>isset($map['country']) ? ($row[$map['country']] ?? '') : '',
                'callsign'=>$call,
                'suffix'=>isset($map['suffix']) ? ($row[$map['suffix']] ?? '') : '',
                'via'=>isset($map['via']) ? ($row[$map['via']] ?? '') : '',
                'last_heard'=>isset($map['last_heard']) ? ($row[$map['last_heard']] ?? '') : '',
                'module'=>isset($map['module']) ? ($row[$map['module']] ?? '') : ''
            ];
        }
    }

    foreach (find_tables($tables, ['Peer','Protocol']) as $t) {
        foreach (array_slice($t,1,MAX_PEERS) as $row) {
            if (!isset($row[0]) || $row[0]==='') continue;
            $s['peers'][]=['peer'=>$row[0],'details'=>implode(' | ',array_slice($row,1))];
        }
    }

    // XLXD Last Heard table.
    // Normalize:
    // Flag | Callsign | Suffix | DPRS | Via / Peer | Last heard | Listening on
    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $map = [];

        foreach (array_slice($t, 0, 3, true) as $ri => $hdr) {
            $lower = array_map(fn($v) => strtolower(trim($v)), $hdr);

            if (!in_array('callsign', $lower, true)) continue;

            foreach ($lower as $i => $h) {
                if ($h === 'callsign') {
                    $map['callsign'] = $i;
                } elseif (str_contains($h, 'flag') || str_contains($h, 'country')) {
                    $map['country'] = $i;
                } elseif ($h === 'suffix') {
                    $map['suffix'] = $i;
                } elseif ($h === 'dprs') {
                    $map['dprs'] = $i;
                } elseif (str_contains($h, 'via') || str_contains($h, 'peer')) {
                    $map['via'] = $i;
                } elseif (str_contains($h, 'last heard')) {
                    $map['last_heard'] = $i;
                } elseif (str_contains($h, 'listening on') || $h === 'module') {
                    $map['module'] = $i;
                }
            }

            if (
                isset($map['callsign']) &&
                isset($map['last_heard'])
            ) {
                $headerRow = $ri;
                break;
            }
        }

        if ($headerRow < 0) continue;

        foreach (array_slice($t, $headerRow + 1, MAX_LAST_HEARD) as $row) {
            $call = trim($row[$map['callsign']] ?? '');

            if (
                $call === '' ||
                !preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,15}$/i', $call)
            ) {
                continue;
            }

            $s['last_heard'][] = [
                'country'    => isset($map['country'])
                                ? trim($row[$map['country']] ?? '')
                                : '',
                'callsign'   => $call,
                'suffix'     => isset($map['suffix'])
                                ? trim($row[$map['suffix']] ?? '')
                                : '',
                'dprs'       => isset($map['dprs'])
                                ? trim($row[$map['dprs']] ?? '')
                                : '',
                'via'        => isset($map['via'])
                                ? trim($row[$map['via']] ?? '')
                                : '',
                'last_heard' => trim($row[$map['last_heard']] ?? ''),
                'time'       => trim($row[$map['last_heard']] ?? ''),
                'module'     => isset($map['module'])
                                ? trim($row[$map['module']] ?? '')
                                : '',
                'type'       => 'XLXD'
            ];
        }
    }

    if (!$s['modules']) foreach (module_letters($plain) as $m) $s['modules'][]=['module'=>$m,'name'=>'','users'=>null,'links'=>[]];
    // Always present XLX modules alphabetically and remove duplicate module rows.
    $byModule=[];
    foreach ($s['modules'] as $m) $byModule[strtoupper($m['module'])]=$m;
    ksort($byModule,SORT_STRING);
    $s['modules']=array_values($byModule);
    return $s;
}
function parse_dplus(array $r, string $html, int $ms, string $url): array {
    $s = blank_status($r);
    $s['online'] = true; $s['http_code'] = 200; $s['response_ms'] = $ms; $s['url'] = $url;
    $dom = dom_from_html($html); $tables = tables_from_dom($dom);
    $plain = clean_text($dom?->textContent ?? strip_tags($html));
    $s['uptime'] = extract_uptime($plain);
    $s['drefd_version'] = extract_drefd_version($plain);
    $s['version'] = $s['drefd_version'] ? 'DREFD '.$s['drefd_version'] : null;

    // DREFD authoritative connection totals:
    // Example: "21 remote users - 0 gateways"
    $s['remote_user_count'] = 0;
    $s['linked_gateway_count'] = 0;

    if (preg_match('/\b(\d+)\s+remote\s+users?\s*-\s*(\d+)\s+gateways?\b/i', $plain, $m)) {
        $s['remote_user_count'] = (int)$m[1];
        $s['linked_gateway_count'] = (int)$m[2];
    }

    // Linked Gateways / Reflectors. Preserve every available REF module, including unlinked ones.
    // REF/DREFD Linked Gateways table.
    // Modules A-E are columns. Only accept rows that match the five-column
    // gateway layout so nested Remote Users / Last Heard tables are ignored.
    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $moduleCols = [];

        foreach (array_slice($t,0,4,true) as $ri=>$hdr) {
            $candidate = [];

            foreach ($hdr as $i=>$cell) {
                if (preg_match('/^Module\s+([A-E])$/i', trim($cell), $m)) {
                    $candidate[$i] = strtoupper($m[1]);
                }
            }

            if (count($candidate) === 5) {
                $headerRow = $ri;
                $moduleCols = $candidate;
                break;
            }
        }

        if ($headerRow < 0) continue;

        $linksByModule = [
            'A'=>[], 'B'=>[], 'C'=>[], 'D'=>[], 'E'=>[]
        ];

        foreach (array_slice($t,$headerRow+1) as $row) {
            // A real Linked Gateways row has exactly the five module columns.
            if (count($row) !== 5) continue;

            foreach ($moduleCols as $i=>$mod) {
                $gateway = trim($row[$i] ?? '');

                if ($gateway === '') continue;

                // DREFD gateway entries look like "KA1EAR B", "WB1GOF C", etc.
                if (!preg_match('/^[A-Z0-9\/-]{3,12}\s+[A-Z]$/i', $gateway)) {
                    continue;
                }

                if (!in_array($gateway,$linksByModule[$mod],true)) {
                    $linksByModule[$mod][] = $gateway;
                }
            }
        }

        foreach ($linksByModule as $mod=>$links) {
            $s['modules'][] = [
                'module'=>$mod,
                'name'=>'',
                'users'=>null,
                'links'=>$links
            ];
        }

        break;
    }

    // REF/DREFD reflectors expose modules A-E.
    // Preserve any module information actually published by the dashboard,
    // but do not manufacture an "unlinked" state when no link data is published.
    $existing=[]; foreach ($s['modules'] as $m) $existing[$m['module']]=$m;
    foreach (range('A','E') as $mod) if (!isset($existing[$mod])) $existing[$mod]=['module'=>$mod,'name'=>'','users'=>null,'links'=>[]];
    ksort($existing,SORT_STRING); $s['modules']=array_values($existing);

    // Remote Users table. The title may occupy its own row, so discover the real header row.
    foreach ($tables as $t) {
        $tableText=strtolower(implode(' ',array_map(fn($r)=>implode(' ',$r),array_slice($t,0,4))));

        // DREFD puts the "Remote Users" title in an outer table.
        // Identify the nested data table by its actual column headings.
        if (
            !str_contains($tableText,'callsign') ||
            !str_contains($tableText,'user message') ||
            !str_contains($tableText,'last tx') ||
            !str_contains($tableText,'type')
        ) continue;
        $headerRow=-1; $map=[];
        foreach (array_slice($t,0,4,true) as $ri=>$hdr) {
            foreach ($hdr as $i=>$cell) {
                $h=strtolower(trim($cell));
                if ($h==='callsign') $map['callsign']=$i;
                elseif ($h==='user') $map['user']=$i;
                elseif (str_contains($h,'message')) $map['message']=$i;
                elseif (str_contains($h,'last tx')) $map['last_tx']=$i;
                elseif ($h==='type') $map['type']=$i;
            }
            if (isset($map['callsign'])) { $headerRow=$ri; break; }
        }
        if ($headerRow<0) continue;
        foreach (array_slice($t,$headerRow+1,MAX_USERS) as $row) {
            $call=trim($row[$map['callsign']] ?? '');
            if ($call==='' || !preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,15}$/i',$call)) continue;
            $last=isset($map['last_tx']) ? ($row[$map['last_tx']] ?? '') : '';
            $module=''; if (preg_match('/\b([A-E])\b/',strtoupper($last),$mm)) $module=$mm[1];
            $s['users'][]=[
                'callsign'=>$call,'user'=>isset($map['user'])?($row[$map['user']]??''):'',
                'message'=>isset($map['message'])?($row[$map['message']]??''):'',
                'last_heard'=>$last,'module'=>$module,
                'type'=>isset($map['type'])?($row[$map['type']]??''):''
            ];
        }
    }

    // REF/DREFD Last Heard table.
    // The "Last Heard" title is in the outer table; identify the nested
    // data table by its actual headers:
    // Callsign | User Message | Last TX on | Time
    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $map = [];

        foreach (array_slice($t, 0, 4, true) as $ri => $hdr) {
            $candidate = [];

            foreach ($hdr as $i => $cell) {
                $h = strtolower(trim($cell));

                if ($h === 'callsign') {
                    $candidate['callsign'] = $i;
                } elseif (str_contains($h, 'user message')) {
                    $candidate['message'] = $i;
                } elseif (str_contains($h, 'last tx')) {
                    $candidate['last_tx'] = $i;
                } elseif ($h === 'time') {
                    $candidate['time'] = $i;
                }
            }

            if (
                isset($candidate['callsign']) &&
                isset($candidate['last_tx']) &&
                isset($candidate['time'])
            ) {
                $headerRow = $ri;
                $map = $candidate;
                break;
            }
        }

        if ($headerRow < 0) continue;

        foreach (array_slice($t, $headerRow + 1, MAX_LAST_HEARD) as $row) {
            $call = trim($row[$map['callsign']] ?? '');

            if (
                $call === '' ||
                !preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,15}$/i', $call)
            ) {
                continue;
            }

            $message = isset($map['message'])
                ? trim($row[$map['message']] ?? '')
                : '';

            $lastTx = trim($row[$map['last_tx']] ?? '');
            $time   = trim($row[$map['time']] ?? '');

            $s['last_heard'][] = [
                'callsign'       => $call,
                'message'        => $message,
                'last_tx_status' => $lastTx,
                'module'         => preg_match('/^[A-E]$/i', $lastTx)
                                    ? strtoupper($lastTx)
                                    : '',
                'time'           => $time,
                'type'           => 'DPLUS'
            ];
        }
    }

    return $s;
}
function parse_dplus_gateway(array $r, string $html, int $ms, string $url): array {
    $s = blank_status($r);

    $s['online'] = true;
    $s['http_code'] = 200;
    $s['response_ms'] = $ms;
    $s['url'] = $url;

    $dom = dom_from_html($html);
    $tables = tables_from_dom($dom);
    $plain = clean_text($dom?->textContent ?? strip_tags($html));

    /*
     * A gateway page may contain both the traditional DPLUS dashboard
     * and a separate g2_link dashboard. Keep those data sets separate.
     */
    $s['dplus_version'] = null;
    $s['g2_dashboard_version'] = null;
    $s['g2_link_version'] = null;

    $s['dplus_modules'] = [];
    $s['dplus_last_heard'] = [];

    $s['g2_modules'] = [];
    $s['g2_last_heard'] = [];

    /*
     * DPLUS software version.
     * Example: DPLUS version 2.2t
     */
    if (preg_match(
        '/\bDPLUS\s+version\s+([A-Za-z0-9._-]+)/i',
        $plain,
        $m
    )) {
        $s['dplus_version'] = trim($m[1]);
        $s['version'] = 'DPLUS '.$s['dplus_version'];
    }

    /*
     * g2_link dashboard/software versions.
     * Example: Dashboard v2.04 g2_link 4.00
     */
    if (preg_match(
        '/Dashboard\s+v?([A-Za-z0-9._-]+)\s+g2_link\s+([A-Za-z0-9._-]+)/i',
        $plain,
        $m
    )) {
        $s['g2_dashboard_version'] = trim($m[1]);
        $s['g2_link_version'] = trim($m[2]);
    }

    /*
     * WB1GOF and similar systems can publish more than one
     * "Module | Linked to" table.
     *
     * The first is the DPLUS dashboard. A later table belongs
     * to g2_link. Do not allow the latter to overwrite DPLUS.
     */
    $linkTableNumber = 0;

    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $moduleCol = -1;
        $linkedCol = -1;

        foreach (array_slice($t, 0, 4, true) as $ri => $row) {
            $lower = array_map(
                fn($v) => strtolower(trim($v)),
                $row
            );

            $mcol = array_search('module', $lower, true);
            $lcol = array_search('linked to', $lower, true);

            if ($mcol !== false && $lcol !== false) {
                $headerRow = $ri;
                $moduleCol = (int)$mcol;
                $linkedCol = (int)$lcol;
                break;
            }
        }

        if ($headerRow < 0) continue;

        $linkTableNumber++;
        $parsed = [];

        foreach (array_slice($t, $headerRow + 1) as $row) {
            $module = strtoupper(trim($row[$moduleCol] ?? ''));
            $linked = trim($row[$linkedCol] ?? '');

            if (!preg_match('/^[A-E]$/', $module)) {
                continue;
            }

            if ($linked === '') {
                $linked = 'unlinked';
            }

            $parsed[] = [
                'module' => $module,
                'linked_to' => $linked,
                'links' => (
                    preg_match('/^(?:unlinked|not linked)$/i', $linked)
                    ? []
                    : [$linked]
                )
            ];
        }

        if (!$parsed) continue;

        if ($linkTableNumber === 1) {
            $s['dplus_modules'] = $parsed;

            /*
             * Keep the generic modules field compatible with the
             * existing UI/API. DPLUS is authoritative here.
             */
            $s['modules'] = $parsed;
        } elseif (!empty($s['g2_link_version'])) {
            /*
             * Only classify a later Module/Linked-to table as
             * g2_link when this page actually identifies itself
             * as having a g2_link dashboard.
             */
            $s['g2_modules'] = $parsed;
        }
    }

    /*
     * Parse DPLUS Remote Users tables.
     *
     * Form:
     * Callsign | User Message | Last TX on | Type
     */
    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $map = [];

        foreach (array_slice($t, 0, 4, true) as $ri => $row) {
            $lower = array_map(
                fn($v) => strtolower(trim($v)),
                $row
            );

            if (
                in_array("callsign", $lower, true) &&
                in_array("last tx on", $lower, true) &&
                in_array("type", $lower, true) &&
                !in_array("time", $lower, true) &&
                !in_array("date-time", $lower, true)
            ) {
                $headerRow = $ri;

                foreach ($lower as $i => $name) {
                    $map[$name] = $i;
                }

                break;
            }
        }

        if ($headerRow < 0) continue;

        foreach (
            array_slice($t, $headerRow + 1, MAX_USERS)
            as $row
        ) {
            $call = trim($row[$map["callsign"]] ?? "");

            if (preg_match(
                "/^([A-Z0-9][A-Z0-9\\/-]{2,15})/i",
                $call,
                $cm
            )) {
                $call = strtoupper($cm[1]);
            } else {
                continue;
            }

            $lastTx = trim($row[$map["last tx on"]] ?? "");

            $s["users"][] = [
                "callsign" => $call,
                "message" => isset($map["user message"])
                    ? trim($row[$map["user message"]] ?? "")
                    : "",
                "module" => preg_match("/^[A-E]$/i", $lastTx)
                    ? strtoupper($lastTx)
                    : "",
                "last_tx_status" => $lastTx,
                "type" => trim($row[$map["type"]] ?? "")
            ];
        }
    }

    /*
     * Parse Last Heard tables.
     *
     * DPLUS form:
     * Callsign | User Message | Last TX on | Time
     *
     * g2_link form:
     * Callsign | Last TX on | Date-Time
     */
    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $map = [];

        foreach (array_slice($t, 0, 4, true) as $ri => $row) {
            $lower = array_map(
                fn($v) => strtolower(trim($v)),
                $row
            );

            if (
                in_array('callsign', $lower, true) &&
                in_array('last tx on', $lower, true)
            ) {
                $headerRow = $ri;

                foreach ($lower as $i => $name) {
                    $map[$name] = $i;
                }

                break;
            }
        }

        if ($headerRow < 0) continue;

        $isDplus = isset($map['time']);
        $isG2 = isset($map['date-time']);

        if (!$isDplus && !$isG2) continue;

        foreach (
            array_slice($t, $headerRow + 1, MAX_LAST_HEARD)
            as $row
        ) {
            $call = trim($row[$map['callsign']] ?? '');

            /*
             * Strip dashboard encoding artifacts while retaining
             * normal amateur-radio callsign syntax.
             */
            if (preg_match(
                '/^([A-Z0-9][A-Z0-9\/-]{2,15})/i',
                $call,
                $cm
            )) {
                $call = strtoupper($cm[1]);
            } else {
                continue;
            }

            $lastTx = trim($row[$map['last tx on']] ?? '');

            if ($isDplus) {
                $module = preg_match('/^[A-E]$/i', $lastTx)
                    ? strtoupper($lastTx)
                    : '';

                $entry = [
                    'callsign' => $call,
                    'message' => isset($map['user message'])
                        ? trim($row[$map['user message']] ?? '')
                        : '',
                    'module' => $module,
                    'last_tx_status' => $lastTx,
                    'time' => trim($row[$map['time']] ?? ''),
                    'type' => 'DPLUS_GATEWAY'
                ];

                $s['dplus_last_heard'][] = $entry;

            } elseif ($isG2) {
                $module = '';

                if (preg_match(
                    '/\b([A-Z0-9]+)\s+([A-E])\b/i',
                    $lastTx,
                    $mm
                )) {
                    $module = strtoupper($mm[2]);
                }

                $entry = [
                    'callsign' => $call,
                    'module' => $module,
                    'last_tx_status' => $lastTx,
                    'time' => trim($row[$map['date-time']] ?? ''),
                    'type' => 'G2_LINK'
                ];

                $s['g2_last_heard'][] = $entry;
            }
        }
    }

    /*
     * Use DPLUS Last Heard as the generic activity source when
     * available. Otherwise fall back to g2_link.
     */
    if ($s['dplus_last_heard']) {
        $s['last_heard'] = $s['dplus_last_heard'];
    } elseif ($s['g2_last_heard']) {
        $s['last_heard'] = $s['g2_last_heard'];
    }

    return $s;
}

function parse_dcs_users(string $html): array {
    $users = [];
    $dom = dom_from_html($html);
    $tables = tables_from_dom($dom);

    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $map = [];

        foreach (array_slice($t,0,4,true) as $ri=>$row) {
            $lower = array_map(fn($v)=>strtolower(trim($v)), $row);

            if (
                in_array('mycall',$lower,true) &&
                in_array('myref',$lower,true) &&
                in_array('last heard',$lower,true)
            ) {
                $headerRow = $ri;

                foreach ($lower as $i=>$name) {
                    $map[$name] = $i;
                }
                break;
            }
        }

        if ($headerRow < 0) continue;

        foreach (array_slice($t,$headerRow+1) as $row) {
            $call = trim($row[$map['mycall']] ?? '');

            if (!preg_match('/^[A-Z0-9]{3,8}(?:\/[A-Z0-9]+)?$/i',$call)) {
                continue;
            }

            $myref = trim($row[$map['myref']] ?? '');
            $source = trim($row[$map['s+modul'] ?? -1] ?? '');
            $last = trim($row[$map['last heard']] ?? '');

            $module = '';
            if (preg_match('/DCS\d+\s+([A-Z])\b/i',$myref,$m)) {
                $module = strtoupper($m[1]);
            }

            $users[] = [
                'callsign'   => strtoupper($call),
                'module'     => $module,
                'last_heard' => $last,
                'via'        => $source,
                'myref'      => $myref,
                'message'    => trim($row[$map['message'] ?? -1] ?? ''),
                'system'     => trim($row[$map['system'] ?? -1] ?? ''),
                'group'      => trim($row[$map['group'] ?? -1] ?? ''),
                'group_dtmf' => trim($row[$map['group dtmf'] ?? -1] ?? '')
            ];

            if (count($users) >= MAX_USERS) break;
        }

        if ($users) break;
    }

    return $users;
}

function parse_dcs_status(string $html): array {
    $peers = [];
    $dom = dom_from_html($html);
    $tables = tables_from_dom($dom);

    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;
        $map = [];

        foreach (array_slice($t,0,5,true) as $ri=>$row) {
            $lower = array_map(fn($v)=>strtolower(trim($v)), $row);

            if (
                in_array('dv station',$lower,true) &&
                in_array('band',$lower,true) &&
                in_array('linked',$lower,true) &&
                in_array('dcs group',$lower,true)
            ) {
                $headerRow = $ri;

                foreach ($lower as $i=>$name) {
                    $map[$name] = $i;
                }
                break;
            }
        }

        if ($headerRow < 0) continue;

        foreach (array_slice($t,$headerRow+1) as $row) {
            $station = trim($row[$map['dv station']] ?? '');

            if (!preg_match('/^[A-Z0-9]{3,8}(?:\/[A-Z0-9]+)?$/i',$station)) {
                continue;
            }

            $group = trim($row[$map['dcs group']] ?? '');

            $module = '';
            if (preg_match('/^\(([A-Z])\)/i',$group,$m)) {
                $module = strtoupper($m[1]);
            }

            $peers[] = [
                'peer'     => strtoupper($station),
                'station'  => strtoupper($station),
                'module'   => $module,
                'band'     => trim($row[$map['band']] ?? ''),
                'linked'   => trim($row[$map['linked']] ?? ''),
                'group'    => $group,
                'via'      => trim($row[$map['via']] ?? ''),
                'software' => trim($row[$map['software']] ?? ''),
                't_status' => trim($row[$map['t-status']] ?? '')
            ];

            if (count($peers) >= MAX_PEERS) break;
        }

        if ($peers) break;
    }

    return $peers;
}


function extract_dcs_log_uptime(string $log): ?string {
    if (!preg_match_all(
        '/Start_Time=(\d{4}-\d{2}-\d{2})(\d{2}:\d{2}:\d{2})/',
        $log,
        $matches,
        PREG_SET_ORDER
    )) {
        return null;
    }

    // Use the most recently published Start_Time value.
    $last = end($matches);
    $startText = $last[1].' '.$last[2];

    try {
        $tz = new DateTimeZone('America/New_York');
        $start = new DateTimeImmutable($startText, $tz);
        $now = new DateTimeImmutable('now', $tz);

        if ($start > $now) {
            return null;
        }

        $diff = $start->diff($now);

        $parts = [];

        if ($diff->days > 0) {
            $parts[] = $diff->days.' '.($diff->days === 1 ? 'day' : 'days');
        }

        $parts[] = $diff->h.' '.($diff->h === 1 ? 'hour' : 'hours');
        $parts[] = $diff->i.' '.($diff->i === 1 ? 'minute' : 'minutes');

        return implode(' ', $parts);

    } catch (Throwable $e) {
        return null;
    }
}

function parse_dcs(array $r, string $html, int $ms, string $url): array {
    $s = blank_status($r);
    $s['online'] = true; $s['http_code'] = 200; $s['response_ms'] = $ms; $s['url'] = $url;
    $dom = dom_from_html($html);
    $tables = tables_from_dom($dom);
    $plain = clean_text($dom?->textContent ?? strip_tags($html));
    $s['uptime'] = extract_dcs_uptime($plain);
    $s['dcs_version'] = extract_dcs_version($plain);
    $s['version'] = $s['dcs_version'] ? 'DCS '.$s['dcs_version'] : null;

    // XReflector DCS dashboards vary by generation. Parse tables by header meaning.
    foreach ($tables as $t) {
        if (!$t) continue;
        $h = strtolower(implode(' ', $t[0]));
        if (str_contains($h,'module')) {
            foreach (array_slice($t,1) as $row) {
                $joined = implode(' ', $row);
                if (preg_match('/(?:DCS016)?\\s*([A-Z])\\b/i', $joined, $m)) {
                    $mod = strtoupper($m[1]);
                    if (!array_filter($s['modules'], fn($x)=>$x['module']===$mod))
                        $s['modules'][]=['module'=>$mod,'name'=>'','users'=>null,'links'=>array_values(array_filter($row))];
                }
            }
        }
        if (str_contains($h,'user') || str_contains($h,'callsign')) {
            foreach (array_slice($t,1,MAX_USERS) as $row) {
                $call = null;
                foreach ($row as $cell) if (preg_match('/^[A-Z0-9]{3,8}(?:\\/[A-Z0-9]+)?$/i', trim($cell))) { $call=trim($cell); break; }
                if (!$call) continue;
                $mod=''; foreach ($row as $cell) if (preg_match('/^([A-Z])$/',trim($cell),$m)) {$mod=$m[1]; break;}
                $s['users'][]=['callsign'=>$call,'last_heard'=>end($row) ?: '','module'=>$mod,'via'=>implode(' | ',$row)];
            }
        }
        if (str_contains($h,'repeater') || str_contains($h,'interlink')) {
            foreach (array_slice($t,1,MAX_PEERS) as $row) {
                if (!$row) continue;
                $s['peers'][]=['peer'=>$row[0] ?? '', 'details'=>implode(' | ',array_slice($row,1))];
            }
        }
    }
    // Module fallback: DCS016A ... DCS016Z references in visible text.
    if (!$s['modules']) {
        preg_match_all('/DCS016\\s*([A-Z])\\b/i',$plain,$mm);
        foreach (array_unique($mm[1] ?? []) as $mod) $s['modules'][]=['module'=>strtoupper($mod),'name'=>'','users'=>null,'links'=>[]];
    }
    return $s;
}

function load_cache(string $key): ?array {
    $file = __DIR__.'/cache/'.preg_replace('/[^A-Za-z0-9_-]/','_',$key).'.json';
    if (!is_file($file) || (time()-filemtime($file)) > CACHE_TTL) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function save_cache(string $key, array $data): void {
    $file = __DIR__.'/cache/'.preg_replace('/[^A-Za-z0-9_-]/','_',$key).'.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
}


function parse_xlxd_module_list(string $html): array {
    $modules = [];

    $dom = dom_from_html($html);
    $tables = tables_from_dom($dom);

    foreach ($tables as $t) {
        if (!$t) continue;

        $headerRow = -1;

        // The XLX Modules List table begins:
        // Module | Name | Users | DPlus ... DExtra ... DCS ... DMR | YSF | M17
        foreach (array_slice($t, 0, 3, true) as $ri => $row) {
            $lower = array_map(
                fn($v) => strtolower(trim($v)),
                $row
            );

            if (
                in_array('module', $lower, true) &&
                in_array('name', $lower, true) &&
                in_array('users', $lower, true)
            ) {
                $headerRow = $ri;
                break;
            }
        }

        if ($headerRow < 0) continue;

        foreach (array_slice($t, $headerRow + 1) as $row) {
            $mod = strtoupper(trim($row[0] ?? ''));

            if (!preg_match('/^[A-Z]$/', $mod)) continue;

            $name  = trim($row[1] ?? '');
            $users = trim($row[2] ?? '');

            $modules[] = [
                'module' => $mod,
                'name'   => $name,
                'users'  => is_numeric($users) ? (int)$users : null,

                // Preserve the complete published routing information.
                'dplus_urcall'  => trim($row[3] ?? ''),
                'dplus_dtmf'    => trim($row[4] ?? ''),
                'dextra_urcall' => trim($row[5] ?? ''),
                'dextra_dtmf'   => trim($row[6] ?? ''),
                'dcs_urcall'    => trim($row[7] ?? ''),
                'dcs_dtmf'      => trim($row[8] ?? ''),
                'dmr'           => trim($row[9] ?? ''),
                'ysf_dgid'      => trim($row[10] ?? ''),
                'm17'           => trim($row[11] ?? ''),

                // Keep compatibility with the existing frontend.
                'links' => []
            ];
        }

        if ($modules) break;
    }

    return $modules;
}

function get_reflector(array $r): array {
    $cached = load_cache($r['name']);
    if ($cached) { $cached['cached']=true; return $cached; }

    $raw = fetch_any(
        $r['urls'],
        !empty($r['insecure_ssl'])
    );
    if (!$raw['ok']) {
        $s = blank_status($r);
        $s['response_ms'] = $raw['response_ms'];
        $s['error'] = $raw['error'] ?: ('HTTP '.$raw['http_code']);
        $s['http_code'] = $raw['http_code'];
        save_cache($r['name'], $s);
        return $s;
    }

    $type = strtoupper($r['type']);
    if ($type === 'XLXD') {
        $s = parse_xlxd($r,$raw['body'],$raw['response_ms'],$raw['url']);

        // Fetch the XLXD dashboard's authoritative Modules List.
        $moduleUrls = [];

        foreach ($r['urls'] as $baseUrl) {
            $parts = parse_url($baseUrl);

            if (!$parts || empty($parts['host'])) continue;

            $scheme = $parts['scheme'] ?? 'http';
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';

            $moduleUrls[] =
                $scheme.'://'.$parts['host'].$port.'/index.php?show=modules';
        }

        if ($moduleUrls) {
            $moduleRaw = fetch_any($moduleUrls);

            if ($moduleRaw['ok']) {
                $moduleList = parse_xlxd_module_list($moduleRaw['body']);

                if ($moduleList) {
                    $s['modules'] = $moduleList;
                }
            }
        }
    }
    elseif ($type === 'DPLUS_GATEWAY') {
        $s = parse_dplus_gateway(
            $r,
            $raw['body'],
            $raw['response_ms'],
            $raw['url']
        );
    }
    elseif ($type === 'DCS') {
        $s = parse_dcs($r,$raw['body'],$raw['response_ms'],$raw['url']);

        // XReflector DCS publishes user activity on a separate page.
        $parts = parse_url($raw['url']);

        if (!empty($parts['host'])) {
            $scheme = $parts['scheme'] ?? 'http';
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';

            // DCS operational log publishes the reflector Start_Time.
        $logUrl = $scheme.'://'.$parts['host'].$port.'/dcs/log/ccs.log';
        $logRaw = fetch_any([$logUrl]);

        if ($logRaw['ok']) {
            $dcsUptime = extract_dcs_log_uptime($logRaw['body']);

            if ($dcsUptime !== null) {
                $s['uptime'] = $dcsUptime;
            }

            // DCS operational log also publishes the reflector version.
            if (preg_match_all(
                '/Version=([^&\\s]+)/',
                $logRaw['body'],
                $versionMatches
            )) {
                $versions = $versionMatches[1] ?? [];

                if ($versions) {
                    $dcsVersion = trim(end($versions));

                    if ($dcsVersion !== '') {
                        $s['dcs_version'] = $dcsVersion;
                        $s['version'] = 'DCS '.$dcsVersion;
                    }
                }
            }
        }

        $userUrl = $scheme.'://'.$parts['host'].$port.'/dcs/_user.html';
            $userRaw = fetch_any([$userUrl]);

            if ($userRaw['ok']) {
                $dcsUsers = parse_dcs_users($userRaw['body']);

                if ($dcsUsers) {
                    $s['users'] = $dcsUsers;

                    // DCS _user.html provides authoritative Last Heard activity.
                    $s['last_heard'] = [];

                    foreach ($dcsUsers as $u) {
                        if (empty($u['callsign']) || empty($u['last_heard'])) {
                            continue;
                        }

                        $s['last_heard'][] = [
                            'callsign'   => $u['callsign'],
                            'module'     => $u['module'] ?? '',
                            'last_heard' => $u['last_heard'],
                            'via'        => $u['via'] ?? '',
                            'system'     => $u['system'] ?? '',
                            'message'    => $u['message'] ?? '',
                            'group'      => $u['group'] ?? '',
                            'type'       => 'DCS'
                        ];
                    }
                }
            }

            // XReflector DCS publishes connected stations separately.
            $statusUrl = $scheme.'://'.$parts['host'].$port.'/dcs/_status.html';
            $statusRaw = fetch_any([$statusUrl]);

            if ($statusRaw['ok']) {
                $dcsPeers = parse_dcs_status($statusRaw['body']);

                if ($dcsPeers) {
                    $s['peers'] = $dcsPeers;
                }

                // Build the DCS active-module list from published
                // Users Online and Connected Stations data.
                $activeModules = [];

                foreach ($s['users'] as $u) {
                    $mod = strtoupper(trim($u['module'] ?? ''));
                    if (preg_match('/^[A-Z]$/', $mod)) {
                        $activeModules[$mod] = true;
                    }
                }

                foreach ($s['peers'] as $p) {
                    $mod = strtoupper(trim($p['module'] ?? ''));
                    if (preg_match('/^[A-Z]$/', $mod)) {
                        $activeModules[$mod] = true;
                    }
                }

                if ($activeModules) {
                    ksort($activeModules);

                    $s['modules'] = [];

                    foreach (array_keys($activeModules) as $mod) {
                        $userCount = 0;
                        $stationCount = 0;

                        foreach ($s['users'] as $u) {
                            if (($u['module'] ?? '') === $mod) {
                                $userCount++;
                            }
                        }

                        foreach ($s['peers'] as $p) {
                            if (($p['module'] ?? '') === $mod) {
                                $stationCount++;
                            }
                        }

                        $s['modules'][] = [
                            'module' => $mod,
                            'name' => '',
                            'users' => $userCount,
                            'stations' => $stationCount,
                            'links' => []
                        ];
                    }
                }
            }
        }
    }
    else {
        $s = parse_dplus($r,$raw['body'],$raw['response_ms'],$raw['url']);
    }

    $s['checked_at'] = gmdate('c');
    $s['cached'] = false;
    save_cache($r['name'],$s);
    return $s;
}

function all_reflectors(array $reflectors): array {
    $out=[];
    foreach ($reflectors as $r) $out[] = get_reflector($r);
    return $out;
}

function network_last_heard(array $statuses): array {
    $rows = [];

    foreach ($statuses as $s) {
        foreach (($s['last_heard'] ?? []) as $x) {
            if (empty($x['callsign'])) continue;

            // Preserve all protocol-specific normalized fields.
            $x['reflector'] = $s['name'];
            $x['type'] = $x['type'] ?? ($s['type'] ?? '');

            $rows[] = $x;
        }
    }

    // Sort newest activity first across all reflector families.
    usort($rows, function($a, $b) {
        $getTime = function($x) {
            $v = $x['last_heard'] ?? ($x['time'] ?? '');
            if ($v === '') return 0;

            // REF/DPLUS: 2026/09/22 10:13:56
            $dt = DateTime::createFromFormat('Y/m/d H:i:s', $v);
            if ($dt !== false) return $dt->getTimestamp();

            // XLXD: 22.09.2026 10:13
            $dt = DateTime::createFromFormat('d.m.Y H:i', $v);
            if ($dt !== false) return $dt->getTimestamp();

            $ts = strtotime($v);
            return $ts !== false ? $ts : 0;
        };

        return $getTime($b) <=> $getTime($a);
    });

    return array_slice($rows, 0, 150);
}
