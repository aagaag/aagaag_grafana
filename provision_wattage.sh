#!/usr/bin/env bash
# Provision Grafana dashboard (file provisioning):
# - One State timeline panel per IP-Symcon top-level category
# - Each series shows raw wattage (0..500 W)
# - 0 W is BLACK
# - Nonzero wattage is binned into 9 logarithmic bands with a blue→yellow→red palette
# - Legend shows the wattage boundaries for each band

set -euo pipefail

# ------------------ CONFIG ------------------
DS_UID="df73sel68p0qoc"   # from your datasource edit URL
DASH_UID="ip-symcon-topcats-power-logstates"
DASH_TITLE="IP-Symcon – Power by top-level category (log bands)"
FOLDER="IP-Symcon"
TIMEZONE="browser"

PROV_ROOT="/etc/grafana/provisioning"
DASH_DIR="${PROV_ROOT}/dashboards/ip-symcon"
DASH_JSON="${DASH_DIR}/ip-symcon-topcats-power-logstates.json"
DASH_YAML="${PROV_ROOT}/dashboards/ip-symcon.yaml"

# Target format seen in your SimPod metric picker:
#   <VariableID>,<VariableName>[<InstanceName>]
# We provision with "<VariableID>," (ID prefix), which usually matches SimPod backends.
TARGET_SUFFIX=","


# ------------------ CATEGORY → VARIABLES ------------------
# NOTE: These are the VariableIDs from your table. Ensure these are the *wattage* variables.
declare -A CAT_VARS=(
  ["Ankleide walk-through"]="42602"
  ["Bathroom upstairs"]="16010 20384 15604 22292 36128 18197 42769 54369 13128"
  ["Bedroom upper floor"]="44017 43379 21649 41655 56267 57107 58023"
  ["Bikehouse"]="35150 54241 31119 21067 54034"
  ["carport and outside"]="15698 19985 21174 26594 28385 30397 51350 53464"
  ["Exercise room"]="26141 17228 57775"
  ["Formlabs room"]="32170 13355 54386 18040 25496 24655 47793"
  ["Hallway basement"]="40798"
  ["Hallway ground floor"]="38335 12075 35429"
  ["Hallway upstairs"]="51995 15929 17188"
  ["Insect destroyers"]="40452 49234 50687"
  ["Kitchen"]="15219 37033 48083"
  ["Living room"]="43804 21810"
  ["Office"]="12926 27383 15922 31718 44226 13426 41542 29390 59084"
  ["old Lehnstrasse"]="22567 27484"
  ["Shelter"]="16988 13952 46707"
  ["Unlocalized instances"]="31408 42028 39384 48887"
  ["Wash room"]="54276"
  ["Weather"]="32208"
)

# Stable panel order
CATS=(
  "Ankleide walk-through"
  "Bathroom upstairs"
  "Bedroom upper floor"
  "Bikehouse"
  "carport and outside"
  "Exercise room"
  "Formlabs room"
  "Hallway basement"
  "Hallway ground floor"
  "Hallway upstairs"
  "Insect destroyers"
  "Kitchen"
  "Living room"
  "Office"
  "old Lehnstrasse"
  "Shelter"
  "Unlocalized instances"
  "Wash room"
  "Weather"
)

# ------------------ LOG BANDS & COLORS ------------------
# Log-spaced boundaries (1..500 W), 9 bins:
# 1, 2.00, 3.98, 7.94, 15.83, 31.58, 63.00, 125.66, 250.66, 500
#
# Thresholds: value is the lower bound where that color starts applying.
# We force:
#   - 0 W (exact) => black
#   - >0 and <2  => first blue band
THRESH_STEPS='[
  {"color":"#000000","value":null},
  {"color":"#000000","value":0},
  {"color":"#1f4cff","value":0.0001},
  {"color":"#2467ff","value":2},
  {"color":"#2a87ff","value":3.98},
  {"color":"#2fb0ff","value":7.94},
  {"color":"#34d7ff","value":15.83},
  {"color":"#52f0c7","value":31.58},
  {"color":"#a6f06a","value":63},
  {"color":"#ffe24a","value":125.66},
  {"color":"#ff2a2a","value":250.66}
]'

# Range mappings to make the legend show wattage boundaries
VALUE_MAPPINGS='[
  {"type":"range","options":{"from":0,"to":0,"result":{"text":"0 W"}}},
  {"type":"range","options":{"from":0.0001,"to":2,"result":{"text":"0–<2 W"}}},
  {"type":"range","options":{"from":2,"to":3.98,"result":{"text":"2–<3.98 W"}}},
  {"type":"range","options":{"from":3.98,"to":7.94,"result":{"text":"3.98–<7.94 W"}}},
  {"type":"range","options":{"from":7.94,"to":15.83,"result":{"text":"7.94–<15.83 W"}}},
  {"type":"range","options":{"from":15.83,"to":31.58,"result":{"text":"15.83–<31.58 W"}}},
  {"type":"range","options":{"from":31.58,"to":63,"result":{"text":"31.58–<63 W"}}},
  {"type":"range","options":{"from":63,"to":125.66,"result":{"text":"63–<125.66 W"}}},
  {"type":"range","options":{"from":125.66,"to":250.66,"result":{"text":"125.66–<250.66 W"}}},
  {"type":"range","options":{"from":250.66,"to":1e12,"result":{"text":"≥250.66 W"}}}
]'

# ------------------ HELPERS ------------------
mk_targets_json() {
  local vars="$1"
  local out=""
  local n=1
  for vid in $vars; do
    out+=$(cat <<EOF
{
  "refId": "$(printf "%c" $((64+n)))",
  "target": "${vid}${TARGET_SUFFIX}"
}
EOF
)
    out+=","
    n=$((n+1))
  done
  echo "${out%,}"
}

# ------------------ WRITE FILES ------------------
sudo mkdir -p "$DASH_DIR"

PANELS=""
Y=0
PANEL_ID=1

for cat in "${CATS[@]}"; do
  targets="$(mk_targets_json "${CAT_VARS[$cat]}")"

  PANELS+=$(cat <<EOF
{
  "id": ${PANEL_ID},
  "type": "state-timeline",
  "title": "${cat}",
  "datasource": {"type":"simpod-json-datasource","uid":"${DS_UID}"},
  "gridPos": {"h": 8, "w": 24, "x": 0, "y": ${Y}},
  "targets": [ ${targets} ],
  "options": {
    "mergeValues": false,
    "showValue": "never",
    "rowHeight": 0.85,
    "tooltip": {"mode":"single", "sort":"none"},
    "legend": {"showLegend": true, "displayMode": "list", "placement": "bottom"}
  },
  "fieldConfig": {
    "defaults": {
      "unit": "watt",
      "decimals": 0,
      "min": 0,
      "max": 500,
      "mappings": ${VALUE_MAPPINGS},
      "color": {"mode":"thresholds"},
      "thresholds": {"mode":"absolute", "steps": ${THRESH_STEPS}}
    },
    "overrides": []
  }
}
EOF
)
  PANELS+=","

  Y=$((Y+8))
  PANEL_ID=$((PANEL_ID+1))
done

PANELS="${PANELS%,}"

# IMPORTANT: file provisioning expects the raw dashboard model (NOT the HTTP API wrapper)
sudo tee "$DASH_JSON" >/dev/null <<EOF
{
  "id": null,
  "uid": "${DASH_UID}",
  "title": "${DASH_TITLE}",
  "timezone": "${TIMEZONE}",
  "schemaVersion": 39,
  "version": 1,
  "refresh": "30s",
  "tags": ["ip-symcon", "power", "log-bands", "state-timeline"],
  "time": {"from": "now-7d", "to": "now"},
  "annotations": {"list": []},
  "templating": {"list": []},
  "panels": [ ${PANELS} ]
}
EOF

sudo tee "$DASH_YAML" >/dev/null <<EOF
apiVersion: 1
providers:
  - name: 'ip-symcon'
    orgId: 1
    folder: '${FOLDER}'
    type: file
    disableDeletion: false
    editable: true
    updateIntervalSeconds: 10
    options:
      path: ${DASH_DIR}
EOF

# permissions
sudo chmod 755 "$DASH_DIR"
sudo chmod 644 "$DASH_JSON" "$DASH_YAML"

# restart grafana to reload provisioning
sudo systemctl restart grafana-server
