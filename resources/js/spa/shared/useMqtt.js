import mqtt from 'mqtt';
import { useEffect, useRef } from 'react';

// Subscribes the browser to tenant/user-scoped MQTT topics over WebSockets and
// invokes onMessage(topic, payload) for each message. Entirely optional and
// fail-safe: with no configured ws URL (or on any connection error) it simply
// does nothing and the UI keeps working via on-demand fetches (per the
// standards, MQTT only enhances the live experience).

const config = window.__APP_CONFIG__ || {};

export function useMqtt(topics, onMessage, { enabled = true } = {}) {
    const handlerRef = useRef(onMessage);
    handlerRef.current = onMessage;

    // Stable key so we only reconnect when the actual topic set changes.
    const topicKey = (topics || []).join('|');

    useEffect(() => {
        if (!enabled || !config.mqttUrl || !topicKey) return undefined;

        let client;
        try {
            client = mqtt.connect(config.mqttUrl, {
                reconnectPeriod: 5000,
                connectTimeout: 8000,
            });
        } catch {
            return undefined; // never let a broker problem break the app
        }

        const list = topicKey.split('|');

        client.on('connect', () => {
            list.forEach((t) => client.subscribe(t, { qos: 0 }));
        });

        client.on('message', (topic, message) => {
            let payload = null;
            try {
                payload = JSON.parse(message.toString());
            } catch {
                payload = message.toString();
            }
            handlerRef.current?.(topic, payload);
        });

        client.on('error', () => {
            // swallow; reconnect logic handles transient failures
        });

        return () => {
            try {
                client.end(true);
            } catch {
                /* noop */
            }
        };
    }, [enabled, topicKey]);
}
