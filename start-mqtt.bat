@echo off
cd /d %~dp0
echo Starting the MQTT broker (Mosquitto) for live attendance updates...
docker compose -f docker-compose.mqtt.yml up -d
echo Broker running: 1883 (MQTT) and 9001 (WebSocket).
echo To stop it: docker compose -f docker-compose.mqtt.yml down
