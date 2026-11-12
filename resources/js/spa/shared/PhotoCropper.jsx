import { useCallback, useEffect, useRef, useState } from 'react';
import { Button, Card } from './ui';
import { FOCUS } from './theme';
import { useEscapeKey } from './useEscapeKey';

/*
| Crop a picture to a square before it is used (owner, 2026-11-12: "allow me to crop the picture
| when I upload from the computer; from the ID card, put it as it is"). Drag to place it, zoom with
| the slider or the wheel; arrow keys move, + and − zoom (rule 7). Returns a square JPEG File of
| `output` × `output` pixels through onDone; Cancel and Escape return nothing.
|
| No library: one image drawn with a transform, and a canvas for the result.
*/
const FRAME = 280; // the square on screen, CSS pixels

export default function PhotoCropper({ file, output = 460, title = 'Crop the photo', onDone, onCancel }) {
    const [img, setImg] = useState(null);
    const [url, setUrl] = useState(null);
    const [zoom, setZoom] = useState(1);
    const [pos, setPos] = useState({ x: 0, y: 0 });
    const drag = useRef(null);

    useEscapeKey(onCancel);

    useEffect(() => {
        const u = URL.createObjectURL(file);
        setUrl(u);
        const i = new Image();
        i.onload = () => setImg(i);
        i.src = u;
        return () => URL.revokeObjectURL(u);
    }, [file]);

    // The smallest scale that still covers the frame, times the zoom.
    const base = img ? FRAME / Math.min(img.naturalWidth, img.naturalHeight) : 1;
    const scale = base * zoom;

    const clamp = useCallback((p, s = scale) => {
        if (!img) return p;
        const w = img.naturalWidth * s;
        const h = img.naturalHeight * s;
        return { x: Math.min(0, Math.max(FRAME - w, p.x)), y: Math.min(0, Math.max(FRAME - h, p.y)) };
    }, [img, scale]);

    // Centre the picture when it loads.
    useEffect(() => {
        if (!img) return;
        const s = FRAME / Math.min(img.naturalWidth, img.naturalHeight);
        setZoom(1);
        setPos({ x: (FRAME - img.naturalWidth * s) / 2, y: (FRAME - img.naturalHeight * s) / 2 });
    }, [img]);

    // Zoom about the middle of the frame, so what is centred stays centred.
    const zoomTo = useCallback((z) => {
        const next = Math.min(4, Math.max(1, z));
        setPos((p) => {
            const cx = (FRAME / 2 - p.x) / scale;
            const cy = (FRAME / 2 - p.y) / scale;
            const s = base * next;
            return clamp({ x: FRAME / 2 - cx * s, y: FRAME / 2 - cy * s }, s);
        });
        setZoom(next);
    }, [base, scale, clamp]);

    function onPointerDown(e) {
        e.currentTarget.setPointerCapture(e.pointerId);
        drag.current = { x: e.clientX, y: e.clientY, start: pos };
    }
    function onPointerMove(e) {
        if (!drag.current) return;
        const d = drag.current;
        setPos(clamp({ x: d.start.x + (e.clientX - d.x), y: d.start.y + (e.clientY - d.y) }));
    }
    function onPointerUp() { drag.current = null; }

    function onKeyDown(e) {
        const step = e.shiftKey ? 20 : 5;
        const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
        if (moves[e.key]) {
            e.preventDefault();
            setPos((p) => clamp({ x: p.x + moves[e.key][0], y: p.y + moves[e.key][1] }));
        } else if (e.key === '+' || e.key === '=') {
            e.preventDefault(); zoomTo(zoom + 0.1);
        } else if (e.key === '-') {
            e.preventDefault(); zoomTo(zoom - 0.1);
        }
    }

    function done() {
        if (!img) return;
        const canvas = document.createElement('canvas');
        canvas.width = output;
        canvas.height = output;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; // a transparent PNG gets a white background, not black
        ctx.fillRect(0, 0, output, output);
        ctx.drawImage(img, -pos.x / scale, -pos.y / scale, FRAME / scale, FRAME / scale, 0, 0, output, output);
        canvas.toBlob((blob) => {
            if (blob) onDone(new File([blob], 'photo.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.9);
    }

    return (
        <div className="fixed inset-0 z-[80] flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4" onClick={(e) => { e.stopPropagation(); onCancel(); }} role="presentation">
            <Card className="w-full max-w-sm rounded-b-none p-5 sm:rounded-2xl" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="crop-title">
                <h2 id="crop-title" className="text-base font-semibold text-slate-900">{title}</h2>
                <p className="mt-0.5 text-xs text-slate-500">Drag to place it, zoom to fit. Arrow keys move it, + and − zoom.</p>

                <div className="mt-4 flex justify-center">
                    <div
                        tabIndex={0}
                        role="img"
                        aria-label="Photo crop area"
                        onPointerDown={onPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        onPointerCancel={onPointerUp}
                        onWheel={(e) => zoomTo(zoom + (e.deltaY < 0 ? 0.1 : -0.1))}
                        onKeyDown={onKeyDown}
                        className={`relative cursor-grab touch-none select-none overflow-hidden rounded-xl bg-slate-100 active:cursor-grabbing ${FOCUS}`}
                        style={{ width: FRAME, height: FRAME }}
                    >
                        {img && url && (
                            <img
                                src={url}
                                alt=""
                                draggable={false}
                                className="pointer-events-none absolute left-0 top-0 max-w-none"
                                style={{ width: img.naturalWidth * scale, height: img.naturalHeight * scale, transform: `translate(${pos.x}px, ${pos.y}px)` }}
                            />
                        )}
                        {/* The square that becomes the photo, with a thin guide. */}
                        <div className="pointer-events-none absolute inset-0 rounded-xl ring-2 ring-inset ring-white/80" />
                    </div>
                </div>

                <label className="mt-4 flex items-center gap-3 text-sm text-slate-600">
                    <span>Zoom</span>
                    <input
                        type="range"
                        min="1"
                        max="4"
                        step="0.01"
                        value={zoom}
                        onChange={(e) => zoomTo(Number(e.target.value))}
                        className={`flex-1 accent-violet-600 ${FOCUS}`}
                        aria-label="Zoom"
                    />
                </label>

                <div className="mt-5 flex justify-end gap-2">
                    <Button variant="secondary" onClick={onCancel}>Cancel</Button>
                    <Button onClick={done} disabled={!img}>Use this photo</Button>
                </div>
            </Card>
        </div>
    );
}
