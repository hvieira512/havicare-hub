#!/usr/bin/env sh
set -eu

PASSWD_FILE="/mosquitto/config/passwd"
ACL_FILE="/mosquitto/config/acl"

PUBLISHER_USER="${MQTT_PUBLISHER_USERNAME:-}"
PUBLISHER_PASS="${MQTT_PUBLISHER_PASSWORD:-}"
SMOKE_USER="${MQTT_SMOKE_USERNAME:-}"
SMOKE_PASS="${MQTT_SMOKE_PASSWORD:-}"

if [ -z "$PUBLISHER_USER" ] || [ -z "$PUBLISHER_PASS" ]; then
  echo "mosquitto-bootstrap: MQTT_PUBLISHER_USERNAME and MQTT_PUBLISHER_PASSWORD are required" >&2
  exit 1
fi

rm -f "$PASSWD_FILE" "$ACL_FILE"

mosquitto_passwd -b -c "$PASSWD_FILE" "$PUBLISHER_USER" "$PUBLISHER_PASS"

if [ -n "$SMOKE_USER" ] && [ -n "$SMOKE_PASS" ]; then
  mosquitto_passwd -b "$PASSWD_FILE" "$SMOKE_USER" "$SMOKE_PASS"
fi

cat >"$ACL_FILE" <<EOF
user $PUBLISHER_USER
topic read /voerka/#
topic write /voerka/#
topic write +/watch/+/status
topic write +/watch/+/events
topic write +/watch/+/raw
topic readwrite +/+/watch/+/downlink
topic write +/ncs/+/status
topic write +/ncs/+/events
topic write +/ncs/+/raw
topic write +/ncs/+/telemetry
EOF

if [ -n "$SMOKE_USER" ]; then
  cat >>"$ACL_FILE" <<EOF

user $SMOKE_USER
topic read #
EOF
fi

chmod 0700 "$PASSWD_FILE" "$ACL_FILE"
chown mosquitto:mosquitto "$PASSWD_FILE" "$ACL_FILE"
