import { api } from './api';

/*
| Waits for a queued DeviceCommand to finish and returns it (2026-10-18).
|
| A device action that queues answers at once with "Queued …", which is not an
| outcome. This polls GET /device-commands/{id} until `is_finished`, so the page can
| say what actually happened — the command's own `message`, success or failure — on
| the button that started it and in a toast. TopBar's "sync all" polls the same
| endpoint for many commands at once.
|
| One missed poll is not an outcome, so errors are retried until the deadline. Past
| it, resolves null: "we stopped watching", never a guessed result (rule 10).
*/
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function waitForCommand(id, { intervalMs = 2000, timeoutMs = 20 * 60 * 1000 } = {}) {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        await sleep(intervalMs);
        try {
            const res = await api.get(`/device-commands/${id}`);
            if (res.data?.is_finished) return res.data;
        } catch {
            // ask again next round
        }
    }

    return null;
}
