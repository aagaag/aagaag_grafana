<?php

//******************************************************************************
//  Name        : Grafana2026 module.php (fork of Symcon1007_Grafana) by aagaag
//******************************************************************************

class Grafana2026 extends IPSModule
{
    //**********************************************************************
    // Create
    //**********************************************************************
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("BasicAuthUser", "");
        $this->RegisterPropertyString("BasicAuthPassword", "");
        $this->RegisterPropertyBoolean("Logging", false);

        $runlevel = IPS_GetKernelRunlevel();
        if ($runlevel == KR_READY) {
            $this->CreateHooks();
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }
    }

    //**************************************************************************
    // MessageSink
    //**************************************************************************
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->LogMessage("GRAFANA2026 KR_READY", KL_MESSAGE);
            $this->CreateHooks();
        }
    }

    //**************************************************************************
    // ApplyChanges
    //**************************************************************************
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    //**************************************************************************
    // Hooks erstellen
    //**************************************************************************
    protected function CreateHooks()
    {
        $this->LogMessage("GRAFANA2026: CreateHooks", KL_MESSAGE);

        $this->SubscribeHook("");
        $this->SubscribeHook("/query");
        $this->SubscribeHook("/search");
        $this->SubscribeHook("/metrics");
        $this->SubscribeHook("/metric-payload-options");
    }

    //**************************************************************************
    // Hook base path for this fork
    //**************************************************************************
    private function HookBase(): string
    {
        return "/hook/Grafana2026";
    }

    //**************************************************************************
    // Detect endpoint part after /hook/Grafana2026
    //**************************************************************************
    private function GetEndpoint(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }
        $path = rtrim($path, '/');

        $base = preg_quote($this->HookBase(), '~');
        if (preg_match('~' . $base . '(?:/(.*))?$~i', $path, $m)) {
            $ep = isset($m[1]) ? strtolower(trim($m[1], '/')) : '';
            return $ep; // '' means root
        }
        return '';
    }

    //**************************************************************************
    // Read JSON body robustly
    //**************************************************************************
    private function ReadJsonBody()
    {
        $raw = file_get_contents("php://input");
        if ($raw === false) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    //**************************************************************************
    // Extract search string for /metrics or /search
    //**************************************************************************
    private function ExtractSearchTarget($body): string
    {
        if (!is_array($body)) {
            return '';
        }
        if (isset($body['target']) && is_string($body['target'])) {
            return $body['target'];
        }
        if (isset($body['metric']) && is_string($body['metric'])) {
            return $body['metric'];
        }
        if (isset($body[0]) && is_string($body[0])) { // SimpleJSON style
            return $body[0];
        }
        return '';
    }

    //**************************************************************************
    // Output helpers
    //**************************************************************************
    private function RespondJson($payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function RespondText(string $text, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
    }

    //**************************************************************************
    // BasicAuth
    //**************************************************************************
    private function CheckBasicAuth(): bool
    {
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            $_SERVER['PHP_AUTH_USER'] = "";
        }
        if (!isset($_SERVER['PHP_AUTH_PW'])) {
            $_SERVER['PHP_AUTH_PW'] = "";
        }

        $AuthUser     = $this->ReadPropertyString("BasicAuthUser");
        $AuthPassword = $this->ReadPropertyString("BasicAuthPassword");

        $ok = ($_SERVER['PHP_AUTH_USER'] === $AuthUser && $_SERVER['PHP_AUTH_PW'] === $AuthPassword);

        if ($ok) {
            $this->SetStatus(102);
            return true;
        }
        $this->SetStatus(202);
        return false;
    }

    //**************************************************************************
    // Main webhook dispatcher
    //**************************************************************************
    protected function ProcessHookData()
    {
        GLOBAL $data_panelId;

        if (!$this->CheckBasicAuth()) {
            $this->RespondText("Verbindung OK . User Password fehlerhaft !", 401);
            return false;
        }

        $endpoint = $this->GetEndpoint();
        $method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Root health check
        if ($endpoint === '') {
            $this->RespondText("OK", 200);
            return;
        }

        // Optional endpoint for simpod datasource
        if ($endpoint === 'metric-payload-options') {
            $this->RespondJson([], 200);
            return;
        }

        // Metrics listing
        if ($endpoint === 'metrics' || $endpoint === 'search') {
            $body   = $this->ReadJsonBody();
            $search = trim((string)$this->ExtractSearchTarget($body));

            // Treat artefacts from empty bodies as "no filter"
            if ($search === '{}' || $search === '[]') {
                $search = '';
            }

            $metrics = $this->ReturnMetricsArray($search);
            $this->RespondJson($metrics, 200);
            return;
        }

        // Query endpoint
        if ($endpoint === 'query') {
            if ($method === 'GET') {
                $this->RespondText("Aufruf im Browser wird nicht unterstuetzt", 405);
                return;
            }

            $d = $this->ReadJsonBody();
            if (!is_array($d) || !isset($d['targets']) || !is_array($d['targets'])) {
                $this->RespondJson(['error' => 'Invalid Grafana query payload (missing targets)'], 400);
                return;
            }

            $data_panelId     = $d['panelId'] ?? 0;
            $data_dashboardId = $d['dashboardId'] ?? 0;

            // Parse range
            $fromRaw = $d['range']['from'] ?? null;
            $toRaw   = $d['range']['to'] ?? null;

            $from = is_string($fromRaw) ? strtotime($fromRaw) : 0;
            $to   = is_string($toRaw) ? strtotime($toRaw) : time();

            if ($from <= 0) {
                $from = time() - 3600;
            }
            if ($to <= 0) {
                $to = time();
            }
            if ($to > time()) {
                $to = time();
            }

            // Collect targets
            $targets      = [];
            $targets_hide = [];
            $targets_data = [];

            $x = 0;
            foreach ($d['targets'] as $t) {
                if (!isset($t['target']) || $t['target'] === '' || $t['target'] === null) {
                    continue;
                }

                $targets[$x] = (string)$t['target'];

                $targets_hide[$x] = isset($t['hide']) ? (bool)$t['hide'] : false;

                // "payload" is used by newer datasource UIs; keep fallback to legacy "data"
                if (isset($t['payload'])) {
                    $targets_data[$x] = $t['payload'];
                } else {
                    $targets_data[$x] = $t['data'] ?? false;
                }

                // Resolve links if the first part is a link ID
                // (only if numeric)
                $idCandidate = explode(',', $targets[$x], 2)[0];
                if (is_numeric($idCandidate)) {
                    $obj = @IPS_GetObject((int)$idCandidate);
                    if (is_array($obj) && ($obj['ObjectType'] ?? null) === 6) {
                        $lnk = IPS_GetLink((int)$idCandidate);
                        if (is_array($lnk) && isset($lnk['TargetID'])) {
                            // preserve label part after comma
                            $labelPart = explode(',', $targets[$x], 2);
                            $labelPart = $labelPart[1] ?? '';
                            $targets[$x] = (string)$lnk['TargetID'] . ($labelPart !== '' ? ',' . $labelPart : '');
                        }
                    }
                }

                $x++;
            }

            if (count($targets) === 0) {
                $this->RespondJson([], 200);
                return;
            }

            $seriesOut = [];

            foreach ($targets as $key => $targetStr) {
                if (!empty($targets_hide[$key])) {
                    continue;
                }

                $parts = explode(',', $targetStr, 2);
                $idStr = $parts[0] ?? '';
                $label = $parts[1] ?? $targetStr;

                if (!is_numeric($idStr)) {
                    continue;
                }
                $id = (int)$idStr;

                if (!$this->CheckVariable($id)) {
                    continue;
                }

                $additional = $this->GetAdditionalData($targets_data[$key] ?? false);

                // TimeOffset: the original module shifts from/to backwards, then shifts timestamps forwards again.
                $fromAdj = $from;
                $toAdj   = $to;
                if (($additional['TimeOffset'] ?? 0) != 0) {
                    $fromAdj = $fromAdj - (int)$additional['TimeOffset'];
                    $toAdj   = $toAdj - (int)$additional['TimeOffset'];
                }

                $varInfo = IPS_GetVariable($id);
                $typ     = $varInfo['VariableType'];

                $recordLimit = IPS_GetOption('ArchiveRecordLimit') - 1;

                $agWanted = $additional['Aggregationsstufe'] ?? -1;
                $agStufe  = $this->CheckZeitraumForAggregatedValues($fromAdj, $toAdj, $id, $agWanted);

                $vals = $this->GetArchivData($id, $fromAdj, $toAdj, $agStufe, $typ, $additional);
                if ($vals === false || !is_array($vals)) {
                    continue;
                }

                // If too many points, degrade aggregation like original logic
                $count = count($vals);
                if ($count > $recordLimit) {
                    foreach ([6, 5, 0, 1] as $fallback) {
                        $agStufe = $fallback;
                        $vals = $this->GetArchivData($id, $fromAdj, $toAdj, $agStufe, $typ, $additional);
                        if (!is_array($vals)) {
                            continue;
                        }
                        $count = count($vals);
                        if ($count <= $recordLimit) {
                            break;
                        }
                    }
                }

                if (!empty($additional['ReverseData'])) {
                    $vals = array_reverse($vals);
                }

                $dataOffset = (float)($additional['DataOffset'] ?? 0.0);
                $yOffset    = (float)($additional['Yoffset'] ?? 0.0);
                // original semantics: Yoffset overrides DataOffset if provided
                $offset = ($yOffset != 0.0) ? $yOffset : $dataOffset;

                $timeOffset = (int)($additional['TimeOffset'] ?? 0);

                $datapoints = [];
                foreach ($vals as $row) {
                    // Determine value
                    $v = null;

                    if (isset($row['Value'])) {
                        $v = $row['Value'];
                        // optional DataFilter
                        if (isset($additional['DataFilter'])) {
                            $filter = $additional['DataFilter'];
                            $vv = (float)str_replace(",", ".", (string)$v);
                            if ($filter > $vv) {
                                continue;
                            }
                        }
                        $v = (float)str_replace(",", ".", (string)$v);
                    } else {
                        // aggregated row: Avg/Min/Max
                        $avg = isset($row['Avg']) ? (float)str_replace(",", ".", (string)$row['Avg']) : null;
                        $min = isset($row['Min']) ? (float)str_replace(",", ".", (string)$row['Min']) : null;
                        $max = isset($row['Max']) ? (float)str_replace(",", ".", (string)$row['Max']) : null;

                        $v = $avg;
                        if (!empty($additional['AggregationsMin']) && $min !== null) {
                            $v = $min;
                        }
                        if (!empty($additional['AggregationsMax']) && $max !== null) {
                            $v = $max;
                        }
                        if ($v === null) {
                            continue;
                        }
                    }

                    // Boolean variable normalization + offset
                    if ($typ == 0) {
                        $v = ($v ? 1.0 : 0.0);
                    }
                    $v = $v + $offset;

                    // Timestamp
                    if (!isset($row['TimeStamp'])) {
                        continue;
                    }
                    $ts = (int)$row['TimeStamp'];
                    if ($timeOffset != 0) {
                        // shift timestamps forward again
                        $ts = $ts + $timeOffset;
                    }

                    $datapoints[] = [$v, $this->TimestampToGrafanaTime($ts)];
                }

                $seriesOut[] = [
                    'target'     => $label,
                    'datapoints' => $datapoints
                ];
            }

            $this->RespondJson($seriesOut, 200);
            return;
        }

        // Unknown endpoint
        $this->RespondJson(['error' => 'Unknown endpoint'], 404);
    }

    //******************************************************************************
    // Return metrics as array of strings
    //******************************************************************************
    protected function ReturnMetricsArray(string $search): array
    {
        $search = trim($search);
        if ($search === '{}' || $search === '[]') {
            $search = '';
        }

        $archiv = $this->GetArchivID();
        if ($archiv === false) {
            return [];
        }

        $varList = IPS_GetVariableList();
        sort($varList);

        $out = [];

        foreach ($varList as $var) {
            if (!AC_GetLoggingStatus($archiv, $var)) {
                continue;
            }

            $name = IPS_GetName($var);
            $name = str_replace("'", '"', $name);
            $name = addslashes($name);

            $parentId = IPS_GetParent($var);
            $parent = IPS_GetName($parentId);
            $parent = str_replace("'", '"', $parent);
            $parent = addslashes($parent);

            $metric = $var . "," . $name . "[" . $parent . "]";

            if ($search !== '' && stripos($metric, $search) === false) {
                continue;
            }

            $out[] = $metric;
        }

        return $out;
    }

    //******************************************************************************
    // Additional JSON Data auswerten (kept from original logic)
    //******************************************************************************
    protected function GetAdditionalData($data)
    {
        $AdditionalData = array();

        if (!is_array($data)) {
            $data = [];
        }

        $AdditionalData['Aggregationsstufe'] = isset($data['Aggregationsstufe']) ? $data['Aggregationsstufe'] : -1;

        if (isset($data['AggregationsAvg'])) $AdditionalData['AggregationsAvg'] = $data['AggregationsAvg'];
        if (isset($data['AggregationsMin'])) $AdditionalData['AggregationsMin'] = $data['AggregationsMin'];
        if (isset($data['AggregationsMax'])) $AdditionalData['AggregationsMax'] = $data['AggregationsMax'];

        $AdditionalData['LastValues'] = isset($data['LastValues']) ? $data['LastValues'] : false;

        $AdditionalData['Resolution'] = isset($data['Resolution']) ? $data['Resolution'] : -1;

        $AdditionalData['DataOffset'] = isset($data['DataOffset']) ? $data['DataOffset'] : 0;

        $AdditionalData['Yoffset'] = isset($data['yoffset']) ? $data['yoffset'] : 0;

        $AdditionalData['TimeOffset'] = isset($data['TimeOffset']) ? $data['TimeOffset'] : 0;

        if (isset($data['DataFilter'])) $AdditionalData['DataFilter'] = $data['DataFilter'];
        if (isset($data['ReverseData'])) $AdditionalData['ReverseData'] = $data['ReverseData'];

        return $AdditionalData;
    }

    //******************************************************************************
    // Aggregationsstufe fuer Zeitraeume festlegen (copied from original behavior)
    //******************************************************************************
    protected function CheckZeitraumForAggregatedValues($from, $to, $varID, $AggregationsStufe)
    {
        $archiv  = $this->GetArchivID();
        $aggType = AC_GetAggregationType($archiv, $varID);

        $stufe = 99;

        $days  = ($to - $from) / (3600 * 24);
        $hours = ($to - $from) / 3600;

        if ($aggType == 0) {
            if ($days > 7)   $stufe = 0;
            if ($days > 100) $stufe = 1;
        }

        if ($aggType == 1) {
            $stufe = 0;
            if ($hours < 2) $stufe = 5;
            if ($days > 2)  $stufe = 1;
            if ($days > 30) $stufe = 2;
        }

        if ($AggregationsStufe >= 0 && $AggregationsStufe <= 6) $stufe = $AggregationsStufe;
        if ($AggregationsStufe == 99) $stufe = 99;

        return $stufe;
    }

    //******************************************************************************
    // Werte einer Variablen aus dem Archiv holen (kept compatible)
    //******************************************************************************
    protected function GetArchivData($id, $from, $to, $agstufe, $typ, $additional_data)
    {
        $archiv = $this->GetArchivID();

        $status = AC_GetLoggingStatus($archiv, $id);
        $arrayVar = IPS_GetVariable($id);
        $typ = $arrayVar['VariableType'];

        // If not logged: return current value as single datapoint (as original module did)
        if ($status == FALSE) {
            $aktuell = GetValue($id);
            if ($typ == 3) {
                $aktuell = '"' . $aktuell . '"';
            }
            return [ ["TimeStamp" => $to, "Value" => $aktuell] ];
        }

        $limit = 0;
        // original code overwrote $limit back to 0, so keep it 0
        $limit = 0;

        if ($agstufe == 99) {
            $werte = AC_GetLoggedValues($archiv, $id, $from, $to, $limit);
        } else {
            $werte = @AC_GetAggregatedValues($archiv, $id, $agstufe, $from, $to, 0);
        }

        if (!is_array($werte)) {
            return false;
        }

        $reversed = array_reverse($werte);

        // Extend series to endpoints for aggType==0 like original
        $aggType = AC_GetAggregationType($archiv, $id);

        if ($aggType == 0) {
            $letzter_Wert = @AC_GetLoggedValues($archiv, $id, 0, $to, 1)[0]['Value'];
            if ($letzter_Wert === null || $letzter_Wert === false) {
                $letzter_Wert = GetValue($id);
            }

            $erster = @AC_GetLoggedValues($archiv, $id, 0, $from - 1, 1);
            $ersterOK = ($erster !== false && is_array($erster) && isset($erster[0]['Value']));
            $erster_Wert = $ersterOK ? $erster[0]['Value'] : null;

            if ($agstufe == 99) {
                $reversed[] = ["TimeStamp" => $to, "Value" => $letzter_Wert];
                if ($ersterOK) {
                    array_unshift($reversed, ["TimeStamp" => $from, "Value" => $erster_Wert]);
                }
            } else {
                $reversed[] = ["TimeStamp" => $to, "Avg" => $letzter_Wert];
                if ($ersterOK) {
                    array_unshift($reversed, ["TimeStamp" => $from, "Avg" => $erster_Wert]);
                }
            }
        }

        // String values quoting (original behavior)
        if ($typ == 3) {
            foreach ($reversed as $k => $v) {
                if (isset($v['Value'])) {
                    $reversed[$k]['Value'] = '"' . $v['Value'] . '"';
                }
            }
        }

        return $reversed;
    }

    //******************************************************************************
    // Time helpers
    //******************************************************************************
    protected function TimestampToGrafanaTime($time)
    {
        return $time * 1000;
    }

    protected function TimestampToDate($time)
    {
        return date('d.m.Y H:i:s', $time);
    }

    //******************************************************************************
    // Archiv ID
    //******************************************************************************
    protected function GetArchivID()
    {
        $guid = "{43192F0B-135B-4CE7-A0A7-1475603F3060}";
        $array = IPS_GetInstanceListByModuleID($guid);
        $archive_id = @$array[0];

        if (!isset($archive_id)) {
            $this->Logmessage("Archive Control nicht gefunden!", KL_WARNING);
            return false;
        }
        return $archive_id;
    }

    //******************************************************************************
    // Variable check
    //******************************************************************************
    protected function CheckVariable($var)
    {
        if (!is_numeric($var)) {
            return false;
        }
        return IPS_VariableExists((int)$var);
    }

    //******************************************************************************
    // Hook management (IMPORTANT: uses /hook/Grafana2026)
    //******************************************************************************
    protected function SubscribeHook($hook)
    {
        $WebHook = $this->HookBase() . $hook;

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;

            foreach ($hooks as $index => $h) {
                if ($h['Hook'] == $WebHook) {
                    if ($h['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }

            if (!$found) {
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
            }

            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    protected function UnregisterHook($hook)
    {
        $WebHook = $this->HookBase() . $hook;

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $idx = null;

            foreach ($hooks as $i => $h) {
                if ($h['Hook'] == $WebHook) {
                    $idx = $i;
                    break;
                }
            }

            if ($idx !== null) {
                array_splice($hooks, $idx, 1);
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
            }
        }
    }

    //**************************************************************************
    // Destroy
    //**************************************************************************
    public function Destroy()
    {
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->UnregisterHook("");
            $this->UnregisterHook("/query");
            $this->UnregisterHook("/search");
            $this->UnregisterHook("/metrics");
            $this->UnregisterHook("/metric-payload-options");
        }
        parent::Destroy();
    }
}
