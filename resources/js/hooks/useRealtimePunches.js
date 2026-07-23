import mqtt from 'mqtt';
import { useEffect, useRef, useState } from 'react';

/**
 * Subscribes to the live-attendance MQTT topic and calls `onPunch(payload)`
 * for every message. Deliberately dumb/thin: takes plain connection details
 * and a callback, no app-specific logic - works the same whether the
 * broker/credentials come from an Inertia shared prop (today) or a future
 * authenticated API endpoint.
 *
 * Purely additive: if the broker is unreachable, mqtt.js just keeps quietly
 * retrying in the background (its default reconnect behavior) - nothing
 * here ever surfaces an error to the user or blocks anything else.
 *
 * @param {{host: string, port: number, username: string, password: string, topic: string}|null} config
 * @param {(payload: object) => void} onPunch
 * @returns {{connected: boolean}}
 */
export default function useRealtimePunches(config, onPunch) {
    const [connected, setConnected] = useState(false);
    const onPunchRef = useRef(onPunch);
    onPunchRef.current = onPunch;

    useEffect(() => {
        if (!config?.host || !config?.username) return undefined;

        const client = mqtt.connect(`ws://${config.host}:${config.port}`, {
            username: config.username,
            password: config.password,
            clientId: `payroll-web-${Math.random().toString(16).slice(2)}`,
            reconnectPeriod: 3000,
        });

        client.on('connect', () => {
            setConnected(true);
            client.subscribe(config.topic);
        });

        client.on('reconnect', () => setConnected(false));
        client.on('close', () => setConnected(false));
        client.on('error', () => setConnected(false));

        client.on('message', (_topic, message) => {
            try {
                onPunchRef.current?.(JSON.parse(message.toString()));
            } catch {
                // malformed payload - ignore, purely additive feature
            }
        });

        return () => {
            client.end(true);
        };
    }, [config?.host, config?.port, config?.username, config?.password, config?.topic]);

    return { connected };
}
