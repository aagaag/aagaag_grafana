<?php

//******************************************************************************
//  Name        : Grafana Modul.php modified by aagaag
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
            $this->LogMessage("GRAFANA KR_Ready", KL_MESSAGE);
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
        $this->LogMessage("GRAFANA: Create Hooks", KL_MESSAGE);

        // root hook and legacy endpoints
        $this->SubscribeHook("");
        $this->SubscribeHook("/query");
        $this->SubscribeHook("/search");

        // NEW: latest simpod-json-datasource uses /metrics instead of /search
        $this->SubscribeHook("/metrics");

        // NEW: recommended by simpod-json-datasource
        $this->SubscribeHook("/metric-payload-options");
    }

    //**************************************************************************
    // Helper: request endpoint
    //**************************************************************************
    private function GetEndpoint(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }
        $path = rtrim($path, '/');

        // expected paths:
        // /hook/Grafana
        // /hook/Grafana/search
        // /hook/Grafana/metrics
        // /hook/Grafana/query
        // /hook/Grafana/metric-payload-options
        if (preg_match('~/hook/Grafana2026(?:/(.*))?$~i', $path, $m)) {
            $ep = isset($m[1]) ? strtolower(trim($m[1], '/')) : '';
            return $ep; // '' means root
        }
        return '';
    }

    //**************************************************************************
    // Helper: JSON body read/parse (robust)
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
    // Helper: extract "search string" for /search or /metrics
    //**************************************************************************
    private function ExtractSearchTarget($body): string
    {
        // accepts {}, {"target":""}, {"metric":""}, ["foo"], [""] ...
        if (is_array($body)) {
            if (isset($body['target']) && is_string($body['target'])) {
                return $body['target'];
            }
            if (isset($body['metric']) && is_string($body['metric'])) {
                return $body['metric'];
            }
            if (isset($body[0]) && is_string($body[0])) {
                return $body[0];
            }
        }
        return '';
    }

    //**************************************************************************
    // Helper: JSON response
    //**************************************************************************
    private function RespondJson($payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    //**************************************************************************
    // Helper: plain response
    //**************************************************************************
    private function RespondText(string $text, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
    }

    //**************************************************************************
    // Auth check (Basic Auth)
    //**************************************************************************
    private function CheckBasicAuth(): bool
    {
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            $_SERVER['PHP_AUTH_USER'] = "";
        }
        if (!isset($_SERVER['PHP_AUTH_PW'])) {
            $_SERVER['PHP_AUTH_PW'] = "";
        }

        $AuthUser = $this->ReadPropertyString("BasicAuthUser");
        $AuthPassword = $this->ReadPropertyString("BasicAuthPassword");

        $ok = ($_SERVER['PHP_AUTH_USER'] === $AuthUser && $_SERVER['PHP_AUTH_PW'] === $AuthPassword);

        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Grafana AUTH:" . $_SERVER['PHP_AUTH_USER'] . "-(pw)", 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Modul AUTH:" . $AuthUser . "-(pw)", 0);

        if ($ok) {
            $this->SetStatus(102);
            return true;
        }

        $this->SetStatus(202);
        return false;
    }

    //**************************************************************************
    // Hook Data auswerten
    //**************************************************************************
    protected function ProcessHookData()
    {
        GLOBAL $_IPS;
        GLOBAL $data_panelId;

        $HookStarttime = time();
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Hook Startime:" . $this->TimestampToDate($HookStarttime), 0);

        // Auth
        if (!$this->CheckBasicAuth()) {
            // Grafana expects proper HTTP codes; still keep a human message for manual curl.
            $this->RespondText("Verbindung OK . User Password fehlerhaft !", 401);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Modul AUTH fehlerhaft!!", 0);
            return false;
        }

        $endpoint = $this->GetEndpoint();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Root endpoint: health check
        if ($endpoint === '') {
            // Keep it simple and fast
            $this->RespondText("OK", 200);
            return;
        }

        // metric payload options (recommended by latest simpod-json-datasource)
        if ($endpoint === 'metric-payload-options') {
            $this->RespondJson([], 200);
            return;
        }

        // /search (legacy) and /metrics (latest simpod-json-datasource)
        if ($endpoint === 'search' || $endpoint === 'metrics') {
            // Must be POST for Grafana; but be forgiving.
            $body = $this->ReadJsonBody();
            $data_target = $this->ExtractSearchTarget($body);

            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Endpoint:" . $endpoint . " Method:" . $method, 0);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "SearchTarget:" . $data_target, 0);

            // ReturnMetrics currently returns a JSON string; decode and re-encode to guarantee JSON validity
            $metricsJson = $this->ReturnMetrics($data_target);
            $metricsArr = json_decode($metricsJson, true);
            if (!is_array($metricsArr)) {
                // Fall back to empty array; never emit PHP warnings/html here
                $metricsArr = [];
            }

            $this->RespondJson($metricsArr, 200);
            return;
        }

        // Everything else: treat as query request path
        // Keep your original logic largely intact, but prevent "browser not supported" from breaking valid POSTs.

        $data = file_get_contents("php://input");
        if ($data === false) {
            $data = '';
        }

        $d = json_decode($data, true);
        if (!is_array($d)) {
            $d = [];
        }

        // Extract fields safely
        $data_type = isset($d['type']) ? $d['type'] : "";
        $data_target = isset($d['target']) ? $d['target'] : ""; // FIX: always defined now

        $data_app = @$d['app'];
        $data_requestId = @$d['requestId'];
        $data_timezone = @$d['timezone'];
        $data_panelId = @$d['panelId'];
        $data_dashboardId = @$d['dashboardId'];
        $data_interval = @$d['interval'];
        $data_maxDataPoints = @$d['maxDataPoints'];

        $this->Logging("PanelID:" . $data_panelId, $data_panelId, true);

        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Endpoint:" . $endpoint . " Method:" . $method, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Raw:" . $data, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "APP:" . $data_app, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "TYPE:" . $data_type, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "RequestID:" . $data_requestId, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Timezone:" . $data_timezone, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "PanelID:" . $data_panelId, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Dashboard:" . $data_dashboardId, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Intervall:" . $data_interval, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "MaxDatapoints:" . $data_maxDataPoints, 0);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Target:" . $data_target, 0);

        $targetset = ($data_target !== "");

        // Legacy shortcut: "request metrics" (older protocol)
        if ($data_type == "timeseries" || $targetset == true) {
            $string = $this->ReturnMetrics($data_target);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "RequestMetrics:" . $string, 0);
            header('Content-Type: application/json; charset=utf-8');
            echo $string;
            return;
        }

        // Explore quirks (Grafana 10+ sometimes sends {} or {"payload":{}})
        if ($data == '{"payload":{}}' || trim($data) == '{}' || $data_app == "explore") {
            $string = $this->ReturnMetrics($data_target);
            $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Explore:" . $string, 0);
            // For Explore metrics requests, returning JSON helps.
            header('Content-Type: application/json; charset=utf-8');
            echo $string;
            return;
        }

        // Dashboard query handling
        $x = 0;

        if ($data_app == "dashboard") {
            // Reject browser GET/invalid payload only; DO NOT reject valid POSTs
            if ($method === 'GET') {
                $this->RespondText("Aufruf im Browser wird nicht unterstuetzt", 405);
                return false;
            }

            if (!isset($d['targets']) || !is_array($d['targets'])) {
                // This is not a Grafana /query payload
                $this->RespondText("Invalid Grafana query payload (missing targets)", 400);
                return false;
            }

            foreach ($d['targets'] as $target) {
                if (!isset($target['target'])) {
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Target is empty! Panel:" . $data_panelId . " Dashboard:" . $data_dashboardId, 0);
                    continue;
                }

                $data_target[$x] = $target['target'];

                if (!isset($target['hide'])) {
                    $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Target Hide is empty!", 0);
                    $data_hide[$x] = false;
                } else {
                    $data_hide[$x] = $target['hide'];
                }

                $data_data[$x] = false;
                $data_data[$x] = @$target['data']; // Additional Data (legacy)

                if (isset($target['payload'])) {
                    $data_data[$x] = @$target['payload']; // Additional Data (new)
                }

                $ObjectType = IPS_GetObject($data_target[$x]);
                $ObjectType = $ObjectType['ObjectType'];
                if ($ObjectType == 6) {
                    $Link = IPS_GetLink($data_target[$x]);
                    $data_target[$x] = $Link['TargetID'];
                    $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Type ist Link : " . $ObjectType . " - " . $Link['TargetID'], 0);
                }

                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Target:" . $data_target[$x] . " - Type : " . $ObjectType, 0);

                if ($data_hide[$x] != false) {
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Hide:" . $data_hide[$x], 0);
                }
                $x++;
            }

            if (!isset($data_target) || !is_array($data_target)) {
                $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Alle Targets sind leer ! Panel:" . $data_panelId . " Dashboard:" . $data_dashboardId, 0);
                return;
            }

            $data_rangefrom = $d['range']['from'];
            $data_rangeto = $d['range']['to'];

            $data_rangefrom = strtotime($d['range']['from']);
            $data_rangeto = strtotime($d['range']['to']);

            if ($data_rangeto > time()) {
                $data_rangeto = time();
            }

            $output = "From:" . $this->TimestampToDate($data_rangefrom) . " - " . "To:" . $this->TimestampToDate($data_rangeto);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $output, 0);
            $this->Logging($output, $data_panelId);

            $data_starttime = $d['startTime'];
            $data_starttime = intval($data_starttime / 1000);
            $data_starttime = $this->TimestampToDate($data_starttime);

            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Startime:" . $data_starttime, 0);

            $stringall = "";

            foreach ($data_target as $key => $dataID) {
                $pieces = explode(",", $dataID);

                $ID = $pieces[0];
                $target = @$pieces[1];

                $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Data ID:" . $ID, 0);

                if ($data_hide[$key] == true) {
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Data ID: HIDE", 0);
                    continue;
                }

                $additional_data = $this->GetAdditionalData($data_data[$key]);

                if ($additional_data['TimeOffset'] != 0) {
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "TimeOffset = " . $additional_data['TimeOffset'], 0);

                    $data_rangefrom = $data_rangefrom - $additional_data['TimeOffset'];
                    $data_rangeto = $data_rangeto - $additional_data['TimeOffset'];

                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "TimeOffset from = " . $this->TimestampToDate($data_rangefrom), 0);
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "TimeOffset to   = " . $this->TimestampToDate($data_rangeto), 0);
                } else {
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "TimeOffset = 0", 0);
                }

                $data_additional = false;
                if ($data_data[$key] == true) {
                    $data_additional = $data_data[$key];
                }

                if (!isset($ID)) {
                    continue;
                }

                if ($this->CheckVariable($ID) == false) {
                    continue;
                }

                $array = IPS_GetVariable($ID);
                $typ = $array['VariableType'];

                $RecordLimit = IPS_GetOption('ArchiveRecordLimit') - 1;

                $AggregationsStufe = $additional_data['Aggregationsstufe'];
                $agstufe = $this->CheckZeitraumForAggregatedValues($data_rangefrom, $data_rangeto, $ID, $AggregationsStufe);

                $dataVals = $this->GetArchivData($ID, $data_rangefrom, $data_rangeto, $agstufe, $typ, $additional_data);
                if ($dataVals == false) {
                    continue;
                }

                $count = count($dataVals);
                $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "1. Versuch Data Count:" . $count, 0);

                if ($count > $RecordLimit) {
                    $agstufe = 6;
                    $dataVals = $this->GetArchivData($ID, $data_rangefrom, $data_rangeto, $agstufe, $typ, $additional_data);
                    $count = is_array($dataVals) ? count($dataVals) : 0;
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "2. Versuch Data Count:" . $count, 0);
                }

                if ($count > $RecordLimit || $count == 0) {
                    $agstufe = 5;
                    $dataVals = $this->GetArchivData($ID, $data_rangefrom, $data_rangeto, $agstufe, $typ, $additional_data);
                    $count = is_array($dataVals) ? count($dataVals) : 0;
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "3. Versuch Data Count:" . $count, 0);
                }

                if ($count > $RecordLimit || $count == 0) {
                    $agstufe = 0;
                    $dataVals = $this->GetArchivData($ID, $data_rangefrom, $data_rangeto, $agstufe, $typ, $additional_data);
                    $count = is_array($dataVals) ? count($dataVals) : 0;
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "4. Versuch Data Count:" . $count, 0);
                }

                if ($count > $RecordLimit) {
                    $agstufe = 1;
                    $dataVals = $this->GetArchivData($ID, $data_rangefrom, $data_rangeto, $agstufe, $typ, $additional_data);
                    $count = is_array($dataVals) ? count($dataVals) : 0;
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "5. Versuch Data Count:" . $count, 0);
                }

                $DataOffset = $additional_data['DataOffset'];
                $TimeOffset = $additional_data['TimeOffset'];

                if (isset($additional_data['ReverseData']) && $additional_data['ReverseData'] == true) {
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Data Reverse.", 0);
                    $dataVals = array_reverse($dataVals);
                }

                if ($count > 0) {
                    $string = $this->CreateReturnString($dataVals, $target, $typ, $agstufe, $data_additional, $DataOffset, $TimeOffset, $additional_data);
                    $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Data String:" . $string, 0);
                    $stringall .= $string;
                }
            }

            $string = $this->CreateHeaderReturnString($stringall);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Data String ALL :" . $string, 0);

            header('Content-Type: application/json; charset=utf-8');
            echo $string;

            $HookEndtime = time();
            $HookLaufzeit = $HookEndtime - $HookStarttime;
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Hook Endtime:" . $this->TimestampToDate($HookEndtime), 0);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Hook Laufzeit:" . $HookLaufzeit . " Sekunden", 0);

            return;
        }

        if ($data_app != "dashboard") {
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Unbekanntes Telegramm empfangen bzw Testtelegramm Raw:" . $data, 0);
        }
    }

    //******************************************************************************
    // Additional JSON Data auswerten
    //******************************************************************************
    protected function GetAdditionalData($data)
    {
        $AdditionalData = array();

        $j = json_encode($data, true);
        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $j, 0);

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Input-" . $key . "[" . $value . "]", 0);
            }
        }

        if (!isset($data['Aggregationsstufe']))
            $AdditionalData['Aggregationsstufe'] = -1;
        else
            $AdditionalData['Aggregationsstufe'] = $data['Aggregationsstufe'];

        if (isset($data['AggregationsAvg']))
            $AdditionalData['AggregationsAvg'] = $data['AggregationsAvg'];
        if (isset($data['AggregationsMin']))
            $AdditionalData['AggregationsMin'] = $data['AggregationsMin'];
        if (isset($data['AggregationsMax']))
            $AdditionalData['AggregationsMax'] = $data['AggregationsMax'];

        if (isset($data['LastValues']))
            $AdditionalData['LastValues'] = $data['LastValues'];
        else
            $AdditionalData['LastValues'] = false;

        if (!isset($data['Resolution']))
            $AdditionalData['Resolution'] = -1;
        else
            $AdditionalData['Resolution'] = $data['Resolution'];

        if (!isset($data['DataOffset']))
            $AdditionalData['DataOffset'] = 0;
        else
            $AdditionalData['DataOffset'] = $data['DataOffset'];

        if (!isset($data['yoffset']))
            $AdditionalData['Yoffset'] = 0;
        else
            $AdditionalData['Yoffset'] = $data['yoffset'];

        if (!isset($data['TimeOffset'])) {
            $AdditionalData['TimeOffset'] = 0;
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Output-TimeOffset false", 0);
        } else {
            $AdditionalData['TimeOffset'] = $data['TimeOffset'];
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Output-TimeOffset : " . $AdditionalData['TimeOffset'], 0);
        }

        if (isset($data['DataFilter']))
            $AdditionalData['DataFilter'] = $data['DataFilter'];

        if (isset($data['ReverseData']))
            $AdditionalData['ReverseData'] = $data['ReverseData'];

        foreach ($AdditionalData as $key => $value) {
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Output-" . $key . "[" . $value . "]", 0);
        }

        return $AdditionalData;
    }

    //******************************************************************************
    //  Aggregationsstufe fuer Zeitraeume festlegen
    //******************************************************************************
    protected function CheckZeitraumForAggregatedValues($from, $to, $varID, $AggregationsStufe)
    {
        GLOBAL $data_panelId;

        switch ($AggregationsStufe) {
            case 0:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Stuendliche Aggregation", 0);
                break;
            case 1:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Taegliche Aggregation", 0);
                break;
            case 2:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Woechentliche Aggregation", 0);
                break;
            case 3:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Monatliche Aggregation", 0);
                break;
            case 4:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Jaehrliche Aggregation", 0);
                break;
            case 5:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "5-Minuetige Aggregation", 0);
                break;
            case 6:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "1-Minuetige Aggregation", 0);
                break;
            case 99:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Maximale Aufloesung", 0);
                break;
            case -1:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Keine Aggregation uebergeben", 0);
                break;
            default:
                $this->SendDebug(__FUNCTION__ . '[' . __LINE__ . ']', "Aggregation unbekannt", 0);
        }

        $archiv = $this->GetArchivID();
        $aggType = AC_GetAggregationType($archiv, $varID);

        $stufe = 99;

        $days = ($to - $from) / (3600 * 24);
        $hours = ($to - $from) / (3600);

        if ($aggType == 0) {
            if ($days > 7) {
                $stufe = 0;
            }
            if ($days > 100) {
                $stufe = 1;
            }
        }

        if ($aggType == 1) {
            $stufe = 0;
            if ($hours < 2)
                $stufe = 5;

            if ($days > 2)
                $stufe = 1;
            if ($days > 30)
                $stufe = 2;
        }

        if ($AggregationsStufe >= 0 && $AggregationsStufe <= 6)
            $stufe = $AggregationsStufe;
        if ($AggregationsStufe == 99)
            $stufe = $AggregationsStufe;

        $s = "Anzahl Tage:" . $days . " Aggreagationsstufe:" . $stufe . " Aggregationstype:" . $aggType;

        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $s, 0);
        $this->Logging($s, $data_panelId);

        return $stufe;
    }

    //******************************************************************************
    //  alle geloggten Variablen an Grafana senden ( Request Metrics )
    //  NOTE: returns JSON string (kept for backward compatibility)
    //******************************************************************************
    protected function ReturnMetrics($data_target)
    {
        $archiv = $this->GetArchivID();
        $varList = IPS_GetVariableList();

        sort($varList);

        $string = '[';

        foreach ($varList as $var) {
            $status = AC_GetLoggingStatus($archiv, $var);
            if ($status == true) {
                $name = IPS_GetName($var);
                $name = str_replace("'", '"', $name);
                $name = addslashes($name);

                $parent = IPS_GetParent($var);
                $parent = IPS_GetName($parent);
                $parent = str_replace("'", '"', $parent);
                $parent = addslashes($parent);

                $metrics = $var . "," . $name . "[" . $parent . "]";

                if ($data_target != "") {
                    $found = stripos($metrics, $data_target);
                    if ($found === false) {
                        continue;
                    }
                }

                $string = $string . '"' . $metrics . '",';
            }
        }

        // avoid invalid JSON if no entries matched
        if ($string !== '[') {
            $string = substr($string, 0, -1);
        }
        $string = $string . ']';

        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $string, 0);
        return $string;
    }

    //******************************************************************************
    //  Rueckgabewerte fuer eine Variable erstellen
    //******************************************************************************
    protected function CreateReturnString($data, $target, $typ, $agstufe, $data_data, $DataOffset, $TimeOffset, $additional_data)
    {
        $offset = floatval($DataOffset);

        if (isset($additional_data['Yoffset'])) {
            $offset = $additional_data['Yoffset'];
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Y-Offsetwert neu:" . $offset, 0);
        }

        $target = addslashes($target);
        $string = '{"target":"' . $target . '","datapoints":[';

        foreach ($data as $value) {
            if (isset($value['Value'])) {
                if (isset($additional_data['DataFilter'])) {
                    $filter = $additional_data['DataFilter'];
                    $v = str_replace(",", ".", $value['Value']);
                    if ($filter > $v)
                        continue;
                } else {
                    $v = str_replace(",", ".", $value['Value']);
                }
            } else {
                $min = @$value['Min'];
                $max = @$value['Max'];
                $avg = @$value['Avg'];

                $avg = str_replace(",", ".", $avg);
                $min = str_replace(",", ".", $min);
                $max = str_replace(",", ".", $max);

                $v = $avg;

                if (isset($additional_data['AggregationsMin']) == true && $min != false)
                    $v = $min;
                if (isset($additional_data['AggregationsMax']) == true && $max != false)
                    $v = $max;
            }

            if ($typ == 0) {
                if ($v == true) {
                    $v = 1;
                    $v = $v + $offset;
                    $v = str_replace(",", ".", $v);
                } else {
                    $v = 0;
                    $v = $v + $offset;
                    $v = str_replace(",", ".", $v);
                }
            }

            if ($TimeOffset == 0) {
                $Timestamp = $value['TimeStamp'];
            } else {
                $Timestamp = $value['TimeStamp'] + intval($TimeOffset);
            }

            $t = $this->TimestampToGrafanaTime($Timestamp);
            $string = $string . "[" . $v . "," . $t . "],";
        }

        if ($string !== '{"target":"' . $target . '","datapoints":[') {
            $string = substr($string, 0, -1);
        }
        $string = $string . "]},";

        return $string;
    }

    //******************************************************************************
    // endgueltigen String erstellen
    //******************************************************************************
    protected function CreateHeaderReturnString($string)
    {
        if ($string !== "") {
            $string = substr($string, 0, -1);
        }
        $string = "[" . $string . "]";
        return $string;
    }

    //******************************************************************************
    // Werte einer Variablen aus dem Archiv holen
    //******************************************************************************
    protected function GetArchivData($id, $from, $to, $agstufe, $typ, $additional_data)
    {
        GLOBAL $data_panelId;

        $archiv = $this->GetArchivID();

        $status = AC_GetLoggingStatus($archiv, $id);
        $arrayVar = IPS_GetVariable($id);
        $typ = $arrayVar['VariableType'];

        if ($status == FALSE) {
            $aktuell = GetValue($id);

            $s = " Variable wird nicht geloggt : " . $id . " aktuellen Wert nehmen: " . $aktuell;
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $s, 0);

            $reversed = array();

            if ($typ == 3) {
                $aktuell = '"' . $aktuell . '"';
            }

            array_push($reversed, array("TimeStamp" => $to, "Value" => $aktuell));
            return $reversed;
        }

        $aggType = AC_GetAggregationType($archiv, $id);

        $limit = 0;
        if (isset($additional_data['LastValues']) == true)
            $limit = $additional_data['LastValues'];
        $limit = 0;

        if ($agstufe == 99) {
            $s = "GetloggedValues:" . $id . " - " . $this->TimestampToDate($from) . " - " . $this->TimestampToDate($to) . " - Limit : " . $limit;
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $s, 0);
            $this->Logging($s, $data_panelId);
            $werte = AC_GetLoggedValues($archiv, $id, $from, $to, $limit);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Count: " . @count($werte), 0);
        } else {
            $s = "GetAggregatedValues:" . $agstufe . "-" . $archiv . "-" . $id . "- von:" . $this->TimestampToDate($from) . "- bis:" . $this->TimestampToDate($to);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $s, 0);
            $this->Logging($s, $data_panelId);
            $werte = @AC_GetAggregatedValues($archiv, $id, $agstufe, $from, $to, 0);
        }

        if (!is_array($werte)) {
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Kein Result : ", 0);
            return false;
        }

        $reversed = array_reverse($werte);
        $count = count($werte);

        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Anzahl der Werte : " . $count, 0);

        if ($aggType == 0) {
            $letzter_Wert = @AC_GetLoggedValues($archiv, $id, 0, $to, 1)[0]['Value'];

            $erster_Wert  = @AC_GetLoggedValues($archiv, $id, 0, $from - 1, 1);
            $erster_WertOK = false;

            if ($erster_Wert != false) {
                $erster_Wert = $erster_Wert[0]['Value'];
                $erster_WertOK = true;
            } else {
                $erster_Wert = false;
                $erster_WertOK = false;
            }
        } else {
            $letzter_Wert = false;
            $erster_Wert = false;
            $erster_WertOK = false;
        }

        if ($letzter_Wert == false) {
            $letzter_Wert = GetValue($id);
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Noch keine Daten geloggt aktueller Wert :" . $letzter_Wert . " ID:" . $id, 0);
        }

        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Erster Wert:[" . $erster_Wert . "] - Letzter Wert:[" . $letzter_Wert . "]", 0);

        if ($aggType == 0) {
            if ($agstufe == 99) {
                array_push($reversed, array("TimeStamp" => $to, "Value" => $letzter_Wert));
            } else {
                array_push($reversed, array("TimeStamp" => $to, "Avg" => $letzter_Wert));
            }

            if ($erster_WertOK != false) {
                if ($agstufe == 99) {
                    array_unshift($reversed, array("TimeStamp" => $from, "Value" => $erster_Wert));
                } else {
                    array_unshift($reversed, array("TimeStamp" => $from, "Avg" => $erster_Wert));
                }
            }
        }

        if ($typ == 3) {
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Achtung Wert sind Strings", 0);
            foreach ($reversed as $key => $value) {
                if (isset($value['Value'])) {
                    $str = $value['Value'];
                    $reversed[$key]['Value'] = '"' . $str . '"';
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
        if (is_numeric($var) == false) {
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Variable ist keine Zahl : " . $var, 0);
            $this->Logmessage("Grafana Variable ID " . $var . " Fehler !", KL_WARNING);
            return false;
        }

        $status = IPS_VariableExists($var);
        return $status;
    }

    //**************************************************************************
    // Logging (unchanged; currently returns immediately)
    //**************************************************************************
    private function Logging($Text, $file = "Grafana", $delete = false, $date = true)
    {
        return;

        if ($this->ReadPropertyBoolean("Logging") == false)
            return;

        $ordner = IPS_GetLogDir() . "Grafana";
        if (!is_dir($ordner))
            mkdir($ordner);

        if (!is_dir($ordner))
            return;

        $time = date("d.m.Y H:i:s");
        $logdatei = IPS_GetLogDir() . "Grafana/" . $file . ".log";

        if ($delete == true)
            @unlink($logdatei);

        $datei = fopen($logdatei, "a+");

        if ($date == true)
            fwrite($datei, $time . " " . $Text . chr(13));
        else
            fwrite($datei, $Text . chr(13));

        fclose($datei);
    }

    //******************************************************************************
    // Hook management
    //******************************************************************************
    protected function SubscribeHook($hook)
    {
        $WebHook = "/hook/Grafana" . $hook;

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;
            foreach ($hooks as $index => $h) {
                if ($h['Hook'] == $WebHook) {
                    if ($h['TargetID'] == $this->InstanceID) {
                        $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", "Hook bereits vorhanden : " . $h['TargetID'], 0);
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }

            if (!$found) {
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
            }
            $this->SendDebug(__FUNCTION__ . "[" . __LINE__ . "]", $WebHook . " erstellt", 0);
            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    protected function UnregisterHook($hook)
    {
        $WebHook = "/hook/Grafana" . $hook;

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;
            foreach ($hooks as $index => $h) {
                if ($h['Hook'] == $WebHook) {
                    $found = $index;
                    break;
                }
            }

            if ($found !== false) {
                array_splice($hooks, $index, 1);
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
