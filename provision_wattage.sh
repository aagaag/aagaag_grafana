#!/usr/bin/env bash
set -euo pipefail

# ------------------ CONFIG ------------------
DS_UID="df73sel68p0qoc"
DASH_UID="ip-symcon-topcats-power-logbands-30"
DASH_TITLE="IP-Symcon – Power by top-level category (30 log bands, 600W+)"
FOLDER="IP-Symcon"
TIMEZONE="browser"

PROV_ROOT="/etc/grafana/provisioning"
DASH_DIR="${PROV_ROOT}/dashboards/ip-symcon"
DASH_JSON="${DASH_DIR}/ip-symcon-topcats-power-logbands-30.json"
DASH_YAML="${PROV_ROOT}/dashboards/ip-symcon.yaml"

OLD_JSONS=(
  "${DASH_DIR}/ip-symcon-topcats-power-logstates.json"
  "${DASH_DIR}/ip-symcon-topcats-power-states.json"
)

# 30 states, log-spaced 1..600, last band is 600W+
N_BANDS=30
MAX_W=600

# ------------------ CATEGORY → VARIABLES ------------------
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

# ------------------ VARIABLEID → DEVICE LABEL ------------------
declare -A VAR_LABEL=(
  ["42602"]="blinds walk-in closet shellyplus2pm-3ce90e310434"
  ["16010"]="0x00158d00067a11daStatinBox"
  ["20384"]="blinds bathroom upper floor shellyplus2pm-ccdba7cfe700"
  ["15604"]="IR Heater bath right shellyplugsg3-8cbfea911dd0"
  ["22292"]="Led Strip & IR Heater left shellyplus2pm-d8132ad36aa4"
  ["36128"]="Led Strip & IR Heater left shellyplus2pm-d8132ad36aa4"
  ["18197"]="lights bathroom upper floor shellyplus2pm-08b61fcc8b58"
  ["42769"]="lights bathroom upper floor shellyplus2pm-08b61fcc8b58"
  ["54369"]="Shelly 1PM Mini Gen 3 cosmetic mirror"
  ["13128"]="switch cosmetic mirror 0x00158d0007c0b727"
  ["44017"]="lights bedroom upper floor"
  ["43379"]="BedsideSwitch0x00158d00087b8447"
  ["21649"]="shades upper floor bedroom left shellyplus2pm-b8d61a8b7de4"
  ["41655"]="shades upper-floor bedroom right Shelly 2.5 Shutter"
  ["56267"]="shades upper-floor bedroom right Shelly 2.5 Shutter"
  ["57107"]="shelly13 mini lights bedroom upper floor shelly1pmmini-348518e056e0"
  ["58023"]="shelly13 mini lights bedroom upper floor shelly1pmmini-348518e056e0"
  ["35150"]="bikehouse lights shellyplus2pm-5443b23d91d0"
  ["54241"]="bikehouse lights shellyplus2pm-5443b23d91d0"
  ["31119"]="button 0x00158d00087b844c_BikeHouseEntrance"
  ["21067"]="IR floodlight ShellyPlusPlugS"
  ["54034"]="ShellyPlusPlugS heater"
  ["15698"]="shellypro4pm-a0dd6c9efdc4"
  ["19985"]="shellypro4pm-a0dd6c9efdc4"
  ["21174"]="shellypro4pm-a0dd6c9efdc4"
  ["26594"]="shellypro4pm-a0dd6c9efdc4"
  ["28385"]="shellypro4pm-a0dd6c9efdc4"
  ["30397"]="shellypro4pm-a0dd6c9efdc4"
  ["51350"]="shellypro4pm-a0dd6c9efdc4"
  ["53464"]="shellypro4pm-a0dd6c9efdc4"
  ["26141"]="0x00158d000af2ae8a heater switch"
  ["17228"]="Exercise room basement shellyplus2pm-441793ce2390"
  ["57775"]="Exercise room basement shellyplus2pm-441793ce2390"
  ["32170"]="shelly mini spotlights hobby room ground floor Gen3Shelly1Mini"
  ["13355"]="0x00158d00087b8405"
  ["54386"]="0x00158d000af2c4cd"
  ["18040"]="shellyplus2pm-3ce90e300b2c"
  ["25496"]="shellyplus2pm-3ce90e300b2c"
  ["24655"]="shellyplus2pm-ccdba7d073dc"
  ["47793"]="shellyplus2pm-ccdba7d073dc"
  ["40798"]="basement stair lights Gen3Shelly1Mini"
  ["38335"]="(no instance) Hallway ground floor"
  ["12075"]="two-strip ground floor corridor LED lighting Shelly1Mini"
  ["35429"]="Window-Roller entrance"
  ["51995"]="doorOpener0x00158d00087b983b"
  ["15929"]="two-strip upper floor corridor LED lighting shellyplus2pm-441793ce2258"
  ["17188"]="two-strip upper floor corridor LED lighting shellyplus2pm-441793ce2258"
  ["40452"]="Insect destroyer ShellyPlusPlugS"
  ["49234"]="Insect destroyer ShellyPlusPlugS"
  ["50687"]="Insect destroyer ShellyPlusPlugS"
  ["15219"]="Shelly 2.5 Shutter"
  ["37033"]="Shelly 2.5 Shutter"
  ["48083"]="upper cupboard lights"
  ["43804"]="IKEA Lamp ShellyPlusPlugS"
  ["21810"]="indirect LED chandelier myStrom30"
  ["12926"]="office shades left shellyplus2pm-5443b23dadf4"
  ["27383"]="0x00158d000af2ae45 switch office lights at door"
  ["15922"]="0x00158d000af2ae8a switch office lights at desk"
  ["31718"]="IR Heater shellyplugsg3-8cbfea98d334"
  ["44226"]="Nuki OfficeDesk Switch 0x00158d00087b8455"
  ["13426"]="office shades middle shellyplus2pm-b8d61a8b6d74"
  ["41542"]="office shades right shellyplus2pm-80646fca4320"
  ["29390"]="shellyplus2pm-cc7b5c8903c8 office LED lights"
  ["59084"]="shellyplus2pm-cc7b5c8903c8 office LED lights"
  ["22567"]="Bedroom light switch bed"
  ["27484"]="Bedroom light switch wall"
  ["16988"]="Shelter window Ventilator Shelly"
  ["13952"]="shelterButton0x00158d00087b8405"
  ["46707"]="spotlights shelter Gen3Shelly1Mini"
  ["31408"]="shellyplugsg3-8cbfea91100c"
  ["42028"]="shellyplugsg3-8cbfea91cf60"
  ["39384"]="shellyplugsg3-8cbfea9678e0"
  ["48887"]="shellyplugsg3-8cbfea98d334"
  ["54276"]="ShellyPlusPlugS LED strip shower"
  ["32208"]="(no instance) Weather"
)

# ------------------ Generate thresholds + mappings (1 decimal everywhere) ------------------
export N_BANDS MAX_W
python3 - <<'PY' >/tmp/grafana_logbands.json
import math, json, os

N_BANDS = int(os.environ["N_BANDS"])
MAX_W   = float(os.environ["MAX_W"])

# boundaries from 1..MAX_W inclusive
bounds = [math.exp(math.log(1.0) + i*(math.log(MAX_W)-math.log(1.0))/N_BANDS) for i in range(N_BANDS+1)]

# round to ONE decimal (and keep strictly increasing)
bounds = [round(b, 1) for b in bounds]
for i in range(1, len(bounds)):
    if bounds[i] <= bounds[i-1]:
        bounds[i] = round(bounds[i-1] + 0.1, 1)

def lerp(a,b,t): return a + (b-a)*t
def hexrgb(r,g,b): return "#{:02x}{:02x}{:02x}".format(int(r),int(g),int(b))
blue   = (31, 76, 255)
yellow = (255, 226, 74)
red    = (255, 42, 42)

# N_BANDS colors from blue->yellow->red
colors = []
for i in range(N_BANDS):
    t = i/(N_BANDS-1) if N_BANDS > 1 else 0
    if t <= 0.5:
        tt = t/0.5
        rgb = (lerp(blue[0], yellow[0], tt), lerp(blue[1], yellow[1], tt), lerp(blue[2], yellow[2], tt))
    else:
        tt = (t-0.5)/0.5
        rgb = (lerp(yellow[0], red[0], tt), lerp(yellow[1], red[1], tt), lerp(yellow[2], red[2], tt))
    colors.append(hexrgb(*rgb))

# thresholds: null->black, 0->black, >0->first band, then each boundary
steps = [
    {"color":"#000000","value":None},
    {"color":"#000000","value":0},
    {"color":colors[0],"value":0.0001},
]
for i in range(1, N_BANDS):
    steps.append({"color":colors[i],"value":bounds[i]})

# legend mappings
mappings = [{"type":"range","options":{"from":0,"to":0,"result":{"text":"0.0 W"}}}]
mappings.append({"type":"range","options":{"from":0.0001,"to":bounds[1],"result":{"text":f"0.0–<{bounds[1]:.1f} W"}}})
for i in range(1, N_BANDS):
    lo = bounds[i]
    hi = bounds[i+1]
    if i == N_BANDS-1:
        mappings.append({"type":"range","options":{"from":lo,"to":1e12,"result":{"text":f"≥{lo:.1f} W"}}})
    else:
        mappings.append({"type":"range","options":{"from":lo,"to":hi,"result":{"text":f"{lo:.1f}–<{hi:.1f} W"}}})

print(json.dumps({"steps":steps, "mappings":mappings}))
PY

THRESH_STEPS="$(jq -c '.steps' /tmp/grafana_logbands.json)"
VALUE_MAPPINGS="$(jq -c '.mappings' /tmp/grafana_logbands.json)"

# ------------------ TARGETS (id + device label) ------------------
mk_targets_json() {
  local vars="$1"
  local out=""
  local n=1
  for vid in $vars; do
    local label="${VAR_LABEL[$vid]:-(unknown device)}"
    label="${label//\"/\\\"}"
    out+=$(cat <<EOF
{
  "refId": "$(printf "%c" $((64+n)))",
  "target": "${vid},${label}"
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

for f in "${OLD_JSONS[@]}"; do
  sudo rm -f "$f" || true
done

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
      "decimals": 1,
      "min": 0,
      "max": 600,
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

sudo tee "$DASH_JSON" >/dev/null <<EOF
{
  "id": null,
  "uid": "${DASH_UID}",
  "title": "${DASH_TITLE}",
  "timezone": "${TIMEZONE}",
  "schemaVersion": 39,
  "version": 1,
  "refresh": "30s",
  "tags": ["ip-symcon", "power", "log-bands", "state-timeline", "30-bands"],
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

sudo chmod 755 "$DASH_DIR"
sudo chmod 644 "$DASH_JSON" "$DASH_YAML"

sudo systemctl restart grafana-server
