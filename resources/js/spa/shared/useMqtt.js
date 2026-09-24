import mqtt from 'mqtt';
import { useEffect, useRef } from 'react';
import { moduleOfTopic, subscribeLive } from './liveFeed';

// Subscribes the browser to tenant/user-scoped MQTT topics over WebSockets and
// invokes onMessage(topic, payload) for each message.
//
// LIVE IS A RULE, NOT AN ENHANCEMENT (CLAUDE.md rule 18, 2026-09-24). With no
// configured ws URL — or while the broker cannot be reached — the same handler is
// driven by the /api/live poller (liveFeed.js) instead, with payload NULL meaning
// "this module changed; re-fetch". Handlers must treat a null payload as exactly that.

const config = window.__APP_CONFIG__ || {};

export function useMqtt(topics, onMessage, { enabled = true } = {}) {
    const handlerRef = useRef(onMessage);
    handlerRef.current = onMessage;

    // Stable key so we only reconnect when the actual topic set changes.
    const topicKey = (topics || []).join('|');

    useEffect(() => {
        if (!enabled || !topicKey) return undefined;

        const list = topicKey.split('|');

        // Fallback carrier: one subscription per distinct module the topics name.
        const fallback = () => {
            const modules = [...new Set(list.map(moduleOfTopic).filter(Boolean))];
            const offs = modules.map((m) => subscribeLive(m, () => handlerRef.current?.(`live/${m}`, null)));
            return () => offs.forEach((off) => off());
        };

        if (!config.mqttUrl) return fallback();

        let client;
        let stopFallback = null;
        try {
            client = mqtt.connect(config.mqttUrl, {
                reconnectPeriod: 5000,
                connectTimeout: 8000,
            });
        } catch {
            return fallback(); // never let a broker problem break the app — or its liveness
        }

        client.on('connect', () => {
            stopFallback?.();
            stopFallback = null;
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

        // Broker unreachable: stay live through the poller until it comes back.
        const toFallback = () => { if (!stopFallback) stopFallback = fallback(); };
        client.on('error', toFallback);
        client.on('offline', toFallback);

        return () => {
            stopFallback?.();
            try {
                client.end(true);
            } catch {
                /* noop */
            }
        };
    }, [enabled, topicKey]);
}
