import {
    AlertCircle,
    ArrowLeft,
    Camera,
    Check,
    ImagePlus,
    Loader2,
    Plus,
    RefreshCw,
    Sparkles,
    Trash2,
    X,
} from 'lucide-react';
import React, { useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Button, cn } from '../ui';

const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
const RESTAURANT_NAME = document.querySelector('meta[name=restaurant-name]')?.content || 'Our Kitchen';
const RESTAURANT_LOGO = document.querySelector('meta[name=restaurant-logo]')?.content || '';
const uid = () => (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random());
const MAX_PHOTOS = 6;
// Deep-linked from the onboarding wizard (/welcome step 2) — its Done screen
// then offers a way back instead of dead-ending in this standalone wizard.
const fromOnboarding = new URLSearchParams(window.location.search).get('from') === 'onboarding';

// Scoped keyframes for the two bespoke moments (scan sweep + success draw).
// The global app.css reduced-motion rule neutralises these automatically.
const STYLES = `
@keyframes imwSweep { 0% { top: -42%; } 100% { top: 104%; } }
.imw-sweep { position:absolute; left:0; right:0; height:42%; pointer-events:none;
    background:linear-gradient(180deg, rgba(239,108,26,0), rgba(239,108,26,.42), rgba(239,108,26,0));
    animation: imwSweep 1.5s ease-in-out infinite; }
@keyframes imwDraw { to { stroke-dashoffset: 0; } }
.imw-check-circle { stroke-dasharray:150; stroke-dashoffset:150; animation: imwDraw .55s cubic-bezier(.6,0,.4,1) forwards; }
.imw-check-path   { stroke-dasharray:48;  stroke-dashoffset:48;  animation: imwDraw .35s .45s ease forwards; }
@keyframes imwSlide { from { opacity:0; transform: translateY(14px); } to { opacity:1; transform:none; } }
.imw-slide { animation: imwSlide .38s cubic-bezier(.2,.8,.2,1) both; }
`;

// Scan/commit sit outside the app's api/* prefix, so a throttled (429) or crashed (500)
// response comes back as a plain HTML error page, not JSON — status code first, JSON second.
async function post(path, body) {
    let res;
    try {
        res = await fetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify(body),
        });
    } catch {
        throw new Error("Couldn't reach the server — check your connection and try again.");
    }
    if (res.status === 429)
        throw new Error("You've tried a few times quickly — wait a minute and try again.");
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Something went wrong — please try again.');
    return data;
}

// Read image files → data-URIs, keeping only images. Each becomes its own photo record —
// scanned, retried and reviewed independently of the others.
const readFiles = (files) =>
    Promise.all(
        [...files]
            .filter((f) => f.type.startsWith('image/'))
            .map(
                (f) =>
                    new Promise((res) => {
                        const r = new FileReader();
                        r.onload = () =>
                            res({
                                id: uid(),
                                dataUri: r.result,
                                name: f.name,
                                status: 'pending',
                                categories: [],
                                error: '',
                            });
                        r.readAsDataURL(f);
                    }),
            ),
    );

const inputCls =
    'rounded-lg border border-line bg-white px-2.5 py-1.5 text-sm text-ink shadow-soft outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/15 placeholder:text-stone-400';

function Stepper({ step }) {
    const steps = ['Upload', 'Review', 'Done'];
    return (
        <div className="mx-auto flex max-w-md items-start px-4 pb-3">
            {steps.map((label, i) => {
                const n = i + 1;
                const done = step > n;
                const active = step === n;
                return (
                    <React.Fragment key={label}>
                        <div className="flex flex-col items-center gap-1.5">
                            <div
                                className={cn(
                                    'flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold transition-all duration-300',
                                    done
                                        ? 'bg-emerald-600 text-white shadow-[0_4px_12px_-3px_rgb(5_150_105/0.5)]'
                                        : active
                                          ? 'brand-mark text-white shadow-brand scale-110 ring-2 ring-brand-200'
                                          : 'bg-stone-100 text-stone-400 ring-1 ring-line',
                                )}
                            >
                                {done ? <Check className="h-4 w-4" strokeWidth={3} aria-hidden="true" /> : n}
                            </div>
                            <span
                                className={cn(
                                    'text-[11px] font-semibold transition-colors',
                                    done || active ? 'text-brand-700' : 'text-stone-400',
                                )}
                            >
                                {label}
                            </span>
                        </div>
                        {n < 3 && (
                            <div className="relative mx-1.5 mt-4 h-[3px] flex-1 overflow-hidden rounded-full bg-line">
                                <span
                                    className={cn(
                                        'absolute inset-0 origin-left rounded-full bg-emerald-600 transition-transform duration-500 ease-out',
                                        step > n ? 'scale-x-100' : 'scale-x-0',
                                    )}
                                />
                            </div>
                        )}
                    </React.Fragment>
                );
            })}
        </div>
    );
}

function ErrorBox({ children }) {
    if (!children) return null;
    return (
        <div className="animate-pop flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm font-medium text-red-600">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{children}</span>
        </div>
    );
}

// Escape hatch shown on the Upload and Scan screens — always visible (never a
// dead end), and honours ?from=onboarding so an owner who arrived from setup
// lands back on the onboarding Menu step instead of a standalone admin page.
function SkipLink() {
    return (
        <a
            href={fromOnboarding ? '/welcome?step=2' : '/admin/menu-items'}
            className="block text-center text-sm font-semibold text-stone-400 underline underline-offset-2 transition hover:text-stone-600"
        >
            {fromOnboarding ? "Skip — I'll add my menu later" : "Skip — I'll add dishes manually"}
        </a>
    );
}

function App() {
    const [step, setStep] = useState(1);
    const [photos, setPhotos] = useState([]);
    // {current,total} while scanAll works through the photos one at a time, else null.
    const [scanProgress, setScanProgress] = useState(null);
    const scanning = scanProgress !== null;
    const [gstRate, setGstRate] = useState(5);
    const [committing, setCommitting] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [dragOver, setDragOver] = useState(false);
    const fileRef = useRef(null);
    // Refs (not state) so a double-click/double-Enter can't slip a second request out before
    // React commits the disabling state update — belt-and-suspenders on top of the disabled button.
    const scanLockRef = useRef(false);
    const retryLockRef = useRef(false);
    const commitLockRef = useRef(false);

    // Categories from every photo that scanned successfully — what commit() sends, and what
    // the dish count everywhere else (header, sticky bar) counts.
    const committedCategories = photos.flatMap((p) => (p.status === 'done' ? p.categories : []));
    const dishCount = committedCategories.reduce(
        (n, c) => n + c.items.filter((it) => it.name.trim()).length,
        0,
    );

    // ---- photos ----
    async function addFiles(files) {
        if (!files?.length) return;
        setError('');
        const read = await readFiles(files);
        setPhotos((p) => [...p, ...read].slice(0, MAX_PHOTOS));
    }
    const removePhoto = (id) => setPhotos((p) => p.filter((x) => x.id !== id));

    function onDrop(e) {
        e.preventDefault();
        setDragOver(false);
        addFiles(e.dataTransfer.files);
    }

    // Scan exactly one photo and return its normalized categories (array-of-one images payload
    // — the /menu-import/scan endpoint itself is unchanged, still accepts up to 6).
    async function scanOnePhoto(photo) {
        const { categories: cats } = await post('/menu-import/scan', { images: [photo.dataUri] });
        return (cats || []).map((c) => ({
            name: c.name || '',
            items: (c.items || []).map((it) => ({
                name: it.name || '',
                price: it.price ?? '',
                is_veg: typeof it.is_veg === 'boolean' ? it.is_veg : null,
                variations: (it.variations || []).map((v) => ({ name: v.name || '', price: v.price ?? '' })),
            })),
        }));
    }

    // ---- step 1 → 2 ----
    // Scans every photo one request at a time (each with its own retry budget server-side) so
    // one blurry shot can't sink the other five. Any photo that reads cleanly is enough to move
    // on to review; the rest show up there as retryable error cards.
    async function scanAll() {
        if (!photos.length || scanLockRef.current || retryLockRef.current) return;
        scanLockRef.current = true;
        setError('');
        const targets = photos;
        let anyOk = false;

        for (let i = 0; i < targets.length; i++) {
            const photo = targets[i];
            setScanProgress({ current: i + 1, total: targets.length });
            setPhotos((ps) =>
                ps.map((p) => (p.id === photo.id ? { ...p, status: 'scanning', error: '' } : p)),
            );
            try {
                const categories = await scanOnePhoto(photo);
                anyOk = true;
                setPhotos((ps) =>
                    ps.map((p) => (p.id === photo.id ? { ...p, status: 'done', categories, error: '' } : p)),
                );
            } catch (e) {
                setPhotos((ps) =>
                    ps.map((p) =>
                        p.id === photo.id ? { ...p, status: 'error', error: e.message, categories: [] } : p,
                    ),
                );
            }
        }

        setScanProgress(null);
        scanLockRef.current = false;

        if (anyOk) {
            setStep(2);
        } else {
            setError('None of your photos could be read — try clearer shots.');
        }
    }

    // ---- retry a single photo from the review step ----
    async function retryPhoto(photoId) {
        if (retryLockRef.current || scanLockRef.current) return;
        const photo = photos.find((p) => p.id === photoId);
        if (!photo) return;
        retryLockRef.current = true;
        setRetrying(true);
        setPhotos((ps) => ps.map((p) => (p.id === photoId ? { ...p, status: 'scanning', error: '' } : p)));
        try {
            const categories = await scanOnePhoto(photo);
            setPhotos((ps) =>
                ps.map((p) => (p.id === photoId ? { ...p, status: 'done', categories, error: '' } : p)),
            );
        } catch (e) {
            setPhotos((ps) =>
                ps.map((p) =>
                    p.id === photoId ? { ...p, status: 'error', error: e.message, categories: [] } : p,
                ),
            );
        } finally {
            setRetrying(false);
            retryLockRef.current = false;
        }
    }

    // ---- review editing (each helper scoped to one photo's categories) ----
    const setCat = (photoId, ci, patch) =>
        setPhotos((ps) =>
            ps.map((p) =>
                p.id !== photoId
                    ? p
                    : { ...p, categories: p.categories.map((c, i) => (i === ci ? { ...c, ...patch } : c)) },
            ),
        );
    const setItem = (photoId, ci, ii, patch) =>
        setPhotos((ps) =>
            ps.map((p) =>
                p.id !== photoId
                    ? p
                    : {
                          ...p,
                          categories: p.categories.map((c, i) =>
                              i !== ci
                                  ? c
                                  : {
                                        ...c,
                                        items: c.items.map((it, j) => (j === ii ? { ...it, ...patch } : it)),
                                    },
                          ),
                      },
            ),
        );
    // Variation (size) edits — same nested-immutable-update shape as setItem, one level deeper.
    const setVariation = (photoId, ci, ii, vi, patch) =>
        setPhotos((ps) =>
            ps.map((p) =>
                p.id !== photoId
                    ? p
                    : {
                          ...p,
                          categories: p.categories.map((c, i) =>
                              i !== ci
                                  ? c
                                  : {
                                        ...c,
                                        items: c.items.map((it, j) =>
                                            j !== ii
                                                ? it
                                                : {
                                                      ...it,
                                                      variations: it.variations.map((v, k) =>
                                                          k === vi ? { ...v, ...patch } : v,
                                                      ),
                                                  },
                                        ),
                                    },
                          ),
                      },
            ),
        );
    const addItem = (photoId, ci) =>
        setPhotos((ps) =>
            ps.map((p) =>
                p.id !== photoId
                    ? p
                    : {
                          ...p,
                          categories: p.categories.map((c, i) =>
                              i !== ci
                                  ? c
                                  : {
                                        ...c,
                                        items: [
                                            ...c.items,
                                            { name: '', price: '', is_veg: null, variations: [] },
                                        ],
                                    },
                          ),
                      },
            ),
        );
    const removeItem = (photoId, ci, ii) =>
        setPhotos((ps) =>
            ps.map((p) =>
                p.id !== photoId
                    ? p
                    : {
                          ...p,
                          categories: p.categories.map((c, i) =>
                              i !== ci ? c : { ...c, items: c.items.filter((_, j) => j !== ii) },
                          ),
                      },
            ),
        );
    const removeCat = (photoId, ci) =>
        setPhotos((ps) =>
            ps.map((p) =>
                p.id !== photoId ? p : { ...p, categories: p.categories.filter((_, i) => i !== ci) },
            ),
        );
    const addCat = (photoId) =>
        setPhotos((ps) =>
            ps.map((p) =>
                p.id !== photoId
                    ? p
                    : {
                          ...p,
                          categories: [
                              ...p.categories,
                              { name: '', items: [{ name: '', price: '', is_veg: null, variations: [] }] },
                          ],
                      },
            ),
        );
    // "Add missed item" — tack onto that photo's last category, or start one if it scanned no categories at all.
    const addMissedItem = (photo) =>
        photo.categories.length ? addItem(photo.id, photo.categories.length - 1) : addCat(photo.id);

    // ---- step 2 → 3 ----
    async function commit() {
        if (
            committedCategories.some((c) =>
                c.items.some((it) => it.name.trim() && typeof it.is_veg !== 'boolean'),
            )
        ) {
            setError('Choose vegetarian or non-vegetarian for each dish before importing.');
            return;
        }
        const payload = committedCategories
            .map((c) => ({
                name: c.name,
                items: c.items
                    .filter((it) => it.name.trim())
                    .map((it) => ({
                        name: it.name.trim(),
                        price: it.price === '' || it.price == null ? 0 : Number(it.price),
                        is_veg: it.is_veg,
                        variations: (it.variations || [])
                            .filter((v) => String(v.name).trim())
                            .map((v) => ({ name: v.name, price: Number(v.price) || 0 })),
                    })),
            }))
            .filter((c) => c.items.length);

        if (!payload.length) {
            setError('Add at least one dish before importing.');
            return;
        }
        if (commitLockRef.current) return;
        commitLockRef.current = true;
        setError('');
        setCommitting(true);
        try {
            setResult(await post('/menu-import/commit', { categories: payload, gst_rate: gstRate }));
            setStep(3);
        } catch (e) {
            setError(e.message);
        } finally {
            setCommitting(false);
            commitLockRef.current = false;
        }
    }

    function reset() {
        setStep(1);
        setPhotos([]);
        setScanProgress(null);
        setResult(null);
        setError('');
    }

    return (
        <div className="flex min-h-[100dvh] flex-col bg-warm text-ink">
            <style>{STYLES}</style>

            {/* Top bar */}
            <header className="sticky top-0 z-20 border-b border-line bg-surface/90 shadow-soft backdrop-blur">
                <div className="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
                    <a
                        href={fromOnboarding ? '/welcome?step=2' : '/admin'}
                        aria-label={fromOnboarding ? 'Back to setup' : 'Back to admin'}
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-stone-500 transition hover:bg-stone-200 active:scale-90"
                    >
                        <ArrowLeft className="h-5 w-5" aria-hidden="true" />
                    </a>
                    {RESTAURANT_LOGO ? (
                        <img
                            src={RESTAURANT_LOGO}
                            alt={RESTAURANT_NAME}
                            className="h-9 w-9 shrink-0 rounded-lg object-contain"
                        />
                    ) : (
                        <span className="brand-mark flex h-9 w-9 shrink-0 items-center justify-center rounded-lg font-black text-white shadow-brand ring-1 ring-white/20">
                            {RESTAURANT_NAME[0]}
                        </span>
                    )}
                    <div className="min-w-0">
                        <div className="font-display truncate text-[17px] font-bold leading-tight text-ink">
                            Import Menu with AI
                        </div>
                        <div className="hidden text-[11px] font-medium text-stone-400 sm:block">
                            Extract a draft · review dishes, prices &amp; categories
                        </div>
                    </div>
                    <span className="ml-auto hidden shrink-0 items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-bold text-brand-700 ring-1 ring-brand-200 sm:inline-flex">
                        <Sparkles className="h-3.5 w-3.5" aria-hidden="true" />
                        AI
                    </span>
                </div>
                <Stepper step={step} />
            </header>

            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-6 pb-28">
                {/* ===================== STEP 1 — Upload ===================== */}
                {step === 1 && !scanning && (
                    <div className="imw-slide space-y-5">
                        <div className="mx-auto max-w-xl text-center">
                            <div className="brand-mark mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-brand">
                                <Sparkles className="h-7 w-7" aria-hidden="true" />
                            </div>
                            <h1 className="font-display text-2xl font-bold leading-tight text-ink">
                                Snap your menu — we'll build it for you
                            </h1>
                            <p className="mt-1.5 text-[15px] text-stone-500">
                                Photograph your printed menu and our AI reads every dish, price and category.
                                Check the extracted draft, make corrections, then import.
                            </p>
                        </div>

                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/*"
                            multiple
                            className="sr-only"
                            onChange={(e) => {
                                addFiles(e.target.files);
                                e.target.value = '';
                            }}
                        />

                        <div
                            role="button"
                            tabIndex={0}
                            onClick={() => fileRef.current?.click()}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    fileRef.current?.click();
                                }
                            }}
                            onDragOver={(e) => {
                                e.preventDefault();
                                setDragOver(true);
                            }}
                            onDragLeave={() => setDragOver(false)}
                            onDrop={onDrop}
                            className={cn(
                                'mx-auto flex max-w-xl cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-12 text-center transition duration-200 active:scale-[.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-cream',
                                dragOver
                                    ? 'border-brand-500 bg-brand-100 scale-[1.01]'
                                    : 'border-brand-300 bg-brand-50 hover:border-brand-400 hover:bg-brand-100',
                            )}
                        >
                            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-white/70 text-brand-500 shadow-soft">
                                <Camera className="h-6 w-6" aria-hidden="true" />
                            </div>
                            <span className="font-semibold text-brand-700">
                                Take a photo or choose from gallery
                            </span>
                            <span className="text-xs text-stone-500">
                                Tap here or drag photos in · up to {MAX_PHOTOS} · clear, straight-on shots
                                read best
                            </span>
                        </div>

                        {photos.length > 0 && (
                            <div className="mx-auto flex max-w-xl flex-wrap justify-center gap-2.5">
                                {photos.map((p) => (
                                    <div
                                        key={p.id}
                                        className="animate-pop group relative h-20 w-20 overflow-hidden rounded-xl border border-line shadow-card"
                                    >
                                        <img
                                            src={p.dataUri}
                                            alt={p.name}
                                            className="h-full w-full object-cover"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => removePhoto(p.id)}
                                            aria-label={`Remove ${p.name}`}
                                            className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-ink/70 text-white transition hover:bg-ink active:scale-90"
                                        >
                                            <X className="h-3 w-3" aria-hidden="true" />
                                        </button>
                                    </div>
                                ))}
                                {photos.length < MAX_PHOTOS && (
                                    <button
                                        type="button"
                                        onClick={() => fileRef.current?.click()}
                                        aria-label="Add more photos"
                                        className="flex h-20 w-20 flex-col items-center justify-center gap-0.5 rounded-xl border-2 border-dashed border-line bg-surface text-stone-400 transition hover:border-brand-300 hover:text-brand-500 active:scale-95"
                                    >
                                        <ImagePlus className="h-5 w-5" aria-hidden="true" />
                                        <span className="text-[10px] font-semibold">Add</span>
                                    </button>
                                )}
                            </div>
                        )}

                        <div className="mx-auto max-w-xl space-y-3">
                            <ErrorBox>{error}</ErrorBox>
                            <Button size="lg" className="w-full" onClick={scanAll} disabled={!photos.length}>
                                <Sparkles className="h-5 w-5" aria-hidden="true" />
                                Scan menu with AI
                            </Button>
                            <SkipLink />
                        </div>
                    </div>
                )}

                {/* ---- Scanning splash: each photo's thumbnail gets a live status overlay ---- */}
                {step === 1 && scanning && (
                    <div className="imw-slide flex flex-col items-center gap-5 py-6 text-center">
                        <div className="flex items-center gap-2 text-brand-700">
                            <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" />
                            <span className="font-display text-lg font-bold">
                                Reading photo {scanProgress.current} of {scanProgress.total}…
                            </span>
                        </div>
                        <div className="mx-auto flex max-w-xl flex-wrap justify-center gap-2.5">
                            {photos.map((p) => (
                                <div
                                    key={p.id}
                                    className="relative h-20 w-20 overflow-hidden rounded-xl border border-line shadow-card"
                                >
                                    <img
                                        src={p.dataUri}
                                        alt={p.name}
                                        className="h-full w-full object-cover"
                                    />
                                    {p.status !== 'pending' && (
                                        <div
                                            className={cn(
                                                'absolute inset-0 flex items-center justify-center',
                                                p.status === 'scanning'
                                                    ? 'bg-ink/45'
                                                    : p.status === 'done'
                                                      ? 'bg-emerald-600/55'
                                                      : 'bg-red-600/55',
                                            )}
                                        >
                                            {p.status === 'scanning' && (
                                                <Loader2
                                                    className="h-6 w-6 animate-spin text-white"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {p.status === 'done' && (
                                                <Check
                                                    className="h-6 w-6 text-white"
                                                    strokeWidth={3}
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {p.status === 'error' && (
                                                <AlertCircle
                                                    className="h-6 w-6 text-white"
                                                    aria-hidden="true"
                                                />
                                            )}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                        <p className="text-xs text-stone-400">
                            This can take up to half a minute per photo — hang tight, don't close this tab.
                        </p>
                        <div className="w-full max-w-xs">
                            <SkipLink />
                        </div>
                    </div>
                )}

                {/* ===================== STEP 2 — Review (page-wise, one section per photo) ===================== */}
                {step === 2 && (
                    <div className="imw-slide space-y-4">
                        <div>
                            <h1 className="font-display text-xl font-bold text-ink">
                                Review &amp; edit — {dishCount} {dishCount === 1 ? 'dish' : 'dishes'}
                            </h1>
                            <p className="mt-0.5 text-sm text-stone-500">
                                Check each name, price, category and dietary classification against your menu.
                                Correct or remove anything wrong before importing.
                            </p>
                        </div>

                        <div className="flex items-center gap-2 rounded-xl border border-line bg-surface p-2.5 shadow-card">
                            <span className="text-sm font-semibold text-ink">GST rate</span>
                            <span className="text-xs text-stone-400">applies to all imported dishes</span>
                            <div
                                className="ml-auto flex gap-1 rounded-lg bg-warm p-1 ring-1 ring-line"
                                role="group"
                                aria-label="GST rate for all imported dishes"
                            >
                                {[0, 5, 12, 18].map((r) => (
                                    <button
                                        key={r}
                                        type="button"
                                        onClick={() => setGstRate(r)}
                                        aria-pressed={gstRate === r}
                                        className={cn(
                                            'rounded-md px-2.5 py-1 text-xs font-bold tabular-nums transition active:scale-95',
                                            gstRate === r
                                                ? 'bg-brand-500 text-white shadow-brand'
                                                : 'text-stone-500 hover:bg-stone-100',
                                        )}
                                    >
                                        {r}%
                                    </button>
                                ))}
                            </div>
                        </div>

                        {photos
                            .filter((p) => p.status !== 'pending')
                            .map((photo) => (
                                <div
                                    key={photo.id}
                                    className="rounded-2xl border border-line bg-surface p-3 shadow-card"
                                >
                                    <div className="mb-2.5 flex items-center gap-2.5">
                                        <div className="h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-line shadow-soft">
                                            <img
                                                src={photo.dataUri}
                                                alt={photo.name}
                                                className="h-full w-full object-cover"
                                            />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate text-sm font-semibold text-ink">
                                                {photo.name}
                                            </div>
                                            {photo.status === 'done' && (
                                                <div className="text-xs text-stone-400">
                                                    {photo.categories.reduce(
                                                        (n, c) =>
                                                            n + c.items.filter((it) => it.name.trim()).length,
                                                        0,
                                                    )}{' '}
                                                    dishes found
                                                </div>
                                            )}
                                            {photo.status === 'scanning' && (
                                                <div className="text-xs text-stone-400">Rescanning…</div>
                                            )}
                                            {photo.status === 'error' && (
                                                <div className="text-xs font-semibold text-red-500">
                                                    Couldn't read this photo
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {photo.status === 'scanning' && (
                                        <div className="flex items-center gap-2 py-2 text-sm font-medium text-stone-500">
                                            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                                            Rescanning this photo…
                                        </div>
                                    )}

                                    {photo.status === 'error' && (
                                        <div className="space-y-2">
                                            <ErrorBox>{photo.error}</ErrorBox>
                                            <button
                                                type="button"
                                                onClick={() => retryPhoto(photo.id)}
                                                disabled={retrying || scanning}
                                                className="inline-flex items-center gap-1.5 rounded-xl border border-dashed border-brand-300 bg-brand-50/50 px-3 py-2 text-sm font-semibold text-brand-600 transition hover:bg-brand-50 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <RefreshCw
                                                    className={cn('h-4 w-4', retrying && 'animate-spin')}
                                                    aria-hidden="true"
                                                />
                                                Retry this photo
                                            </button>
                                        </div>
                                    )}

                                    {photo.status === 'done' && (
                                        <div className="space-y-3">
                                            {photo.categories.map((cat, ci) => (
                                                <div
                                                    key={ci}
                                                    className="rounded-xl bg-warm p-2.5 ring-1 ring-line"
                                                >
                                                    <div className="mb-2 flex items-center gap-2">
                                                        <input
                                                            value={cat.name}
                                                            onChange={(e) =>
                                                                setCat(photo.id, ci, { name: e.target.value })
                                                            }
                                                            placeholder="Category name"
                                                            className={cn(
                                                                inputCls,
                                                                'min-w-0 flex-1 font-bold',
                                                            )}
                                                        />
                                                        <span className="tnum shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-500">
                                                            {cat.items.filter((it) => it.name.trim()).length}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => removeCat(photo.id, ci)}
                                                            aria-label="Remove category"
                                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-stone-400 transition hover:bg-red-50 hover:text-red-500 active:scale-90"
                                                        >
                                                            <Trash2 className="h-4 w-4" aria-hidden="true" />
                                                        </button>
                                                    </div>
                                                    <div className="space-y-2">
                                                        {cat.items.map((it, ii) => (
                                                            <div
                                                                key={ii}
                                                                className="flex flex-wrap items-center gap-2 rounded-xl bg-white p-2 ring-1 ring-line"
                                                            >
                                                                <select
                                                                    aria-label={`Dietary classification for ${it.name || 'dish'}`}
                                                                    value={
                                                                        it.is_veg === null
                                                                            ? ''
                                                                            : String(it.is_veg)
                                                                    }
                                                                    onChange={(e) =>
                                                                        setItem(photo.id, ci, ii, {
                                                                            is_veg:
                                                                                e.target.value === ''
                                                                                    ? null
                                                                                    : e.target.value ===
                                                                                      'true',
                                                                        })
                                                                    }
                                                                    className={inputCls}
                                                                >
                                                                    <option value="">
                                                                        Choose veg / non-veg
                                                                    </option>
                                                                    <option value="true">Vegetarian</option>
                                                                    <option value="false">
                                                                        Non-vegetarian
                                                                    </option>
                                                                </select>
                                                                <input
                                                                    value={it.name}
                                                                    onChange={(e) =>
                                                                        setItem(photo.id, ci, ii, {
                                                                            name: e.target.value,
                                                                        })
                                                                    }
                                                                    placeholder="Dish name"
                                                                    className={cn(
                                                                        inputCls,
                                                                        'min-w-[7rem] flex-1',
                                                                    )}
                                                                />
                                                                <div className="flex items-center gap-1">
                                                                    <span className="text-sm font-semibold text-stone-400">
                                                                        ₹
                                                                    </span>
                                                                    <input
                                                                        aria-label={`Price for ${it.name || 'unnamed dish'}`}
                                                                        value={it.price}
                                                                        onChange={(e) =>
                                                                            setItem(photo.id, ci, ii, {
                                                                                price: e.target.value,
                                                                            })
                                                                        }
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.01"
                                                                        inputMode="decimal"
                                                                        className={cn(inputCls, 'tnum w-20')}
                                                                    />
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        removeItem(photo.id, ci, ii)
                                                                    }
                                                                    aria-label="Remove dish"
                                                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-stone-300 transition hover:bg-red-50 hover:text-red-500 active:scale-90"
                                                                >
                                                                    <X
                                                                        className="h-4 w-4"
                                                                        aria-hidden="true"
                                                                    />
                                                                </button>
                                                                {it.variations?.length > 0 && (
                                                                    <div className="flex w-full flex-wrap items-center gap-1.5 pl-9">
                                                                        <span className="text-[11px] font-semibold text-stone-400">
                                                                            Sizes:
                                                                        </span>
                                                                        {it.variations.map((v, vi) => (
                                                                            <div
                                                                                key={vi}
                                                                                className="flex items-center gap-1 rounded-lg bg-warm px-1.5 py-1 ring-1 ring-line"
                                                                            >
                                                                                <input
                                                                                    value={v.name}
                                                                                    onChange={(e) =>
                                                                                        setVariation(
                                                                                            photo.id,
                                                                                            ci,
                                                                                            ii,
                                                                                            vi,
                                                                                            {
                                                                                                name: e.target
                                                                                                    .value,
                                                                                            },
                                                                                        )
                                                                                    }
                                                                                    placeholder="Size"
                                                                                    aria-label="Variation name"
                                                                                    className="w-16 min-w-0 bg-transparent text-[11px] font-semibold text-ink outline-none placeholder:text-stone-400"
                                                                                />
                                                                                <span className="text-[11px] text-stone-400">
                                                                                    ₹
                                                                                </span>
                                                                                <input
                                                                                    value={v.price}
                                                                                    onChange={(e) =>
                                                                                        setVariation(
                                                                                            photo.id,
                                                                                            ci,
                                                                                            ii,
                                                                                            vi,
                                                                                            {
                                                                                                price: e
                                                                                                    .target
                                                                                                    .value,
                                                                                            },
                                                                                        )
                                                                                    }
                                                                                    type="number"
                                                                                    min="0"
                                                                                    step="0.01"
                                                                                    inputMode="decimal"
                                                                                    aria-label="Variation price"
                                                                                    className="tnum w-14 bg-transparent text-[11px] font-semibold text-ink outline-none"
                                                                                />
                                                                            </div>
                                                                        ))}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => addItem(photo.id, ci)}
                                                            className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 transition hover:text-brand-700 active:scale-95"
                                                        >
                                                            <Plus
                                                                className="h-3.5 w-3.5"
                                                                aria-hidden="true"
                                                            />
                                                            Add dish
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                            <button
                                                type="button"
                                                onClick={() => addMissedItem(photo)}
                                                className="inline-flex items-center gap-1.5 rounded-xl border border-dashed border-brand-300 bg-brand-50/50 px-3 py-2 text-sm font-semibold text-brand-600 transition hover:bg-brand-50 active:scale-95"
                                            >
                                                <Plus className="h-4 w-4" aria-hidden="true" />
                                                Add missed item
                                            </button>
                                        </div>
                                    )}
                                </div>
                            ))}

                        <ErrorBox>{error}</ErrorBox>
                    </div>
                )}

                {/* ===================== STEP 3 — Done ===================== */}
                {step === 3 && result && (
                    <div className="imw-slide mx-auto max-w-lg py-8 text-center">
                        <svg
                            viewBox="0 0 52 52"
                            className="mx-auto h-20 w-20"
                            role="img"
                            aria-label="Success"
                        >
                            <circle
                                className="imw-check-circle"
                                cx="26"
                                cy="26"
                                r="24"
                                fill="none"
                                stroke="#059669"
                                strokeWidth="3"
                            />
                            <path
                                className="imw-check-path"
                                fill="none"
                                stroke="#059669"
                                strokeWidth="4"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M14 27l8 8 16-16"
                            />
                        </svg>
                        <h1 className="font-display mt-4 text-2xl font-bold text-ink">Menu imported!</h1>
                        <p className="mt-1.5 text-stone-500">
                            <b className="text-ink">{result.created}</b>{' '}
                            {result.created === 1 ? 'dish' : 'dishes'} added across{' '}
                            <b className="text-ink">{result.categories}</b> categor
                            {result.categories === 1 ? 'y' : 'ies'}.
                        </p>
                        {(result.skipped_duplicate > 0 || result.skipped_no_price > 0) && (
                            <p className="mt-1 text-sm text-stone-400">
                                {result.skipped_duplicate > 0 && (
                                    <>{result.skipped_duplicate} skipped (already on your menu)</>
                                )}
                                {result.skipped_duplicate > 0 && result.skipped_no_price > 0 && ' · '}
                                {result.skipped_no_price > 0 && (
                                    <>{result.skipped_no_price} skipped (no price)</>
                                )}
                            </p>
                        )}
                        {result.skipped_no_price > 0 && (
                            <p className="mt-1.5 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700 ring-1 ring-amber-200">
                                Add a price to those dish{result.skipped_no_price === 1 ? '' : 'es'} in Menu
                                Items, then import again to bring{' '}
                                {result.skipped_no_price === 1 ? 'it' : 'them'} in.
                            </p>
                        )}
                        <p className="mt-2 text-sm text-stone-400">
                            Relevant photos are matched to each dish automatically — tweak anything in Menu
                            Items.
                        </p>
                        <div className="mt-6 flex flex-wrap justify-center gap-2">
                            {fromOnboarding ? (
                                <a
                                    href="/welcome?step=3"
                                    className="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-brand-500 px-5 text-[15px] font-semibold text-white shadow-brand transition duration-200 ease-out hover:-translate-y-px hover:bg-brand-600 active:scale-[.97]"
                                >
                                    Continue setup →
                                </a>
                            ) : (
                                <a
                                    href="/admin/menu-items"
                                    className="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-brand-500 px-5 text-[15px] font-semibold text-white shadow-brand transition duration-200 ease-out hover:-translate-y-px hover:bg-brand-600 active:scale-[.97]"
                                >
                                    <Check className="h-5 w-5" aria-hidden="true" />
                                    View menu
                                </a>
                            )}
                            <Button variant="outline" size="lg" onClick={reset}>
                                <Sparkles className="h-5 w-5" aria-hidden="true" />
                                Import another
                            </Button>
                        </div>
                    </div>
                )}
            </main>

            {/* Sticky action bar for the Review step */}
            {step === 2 && (
                <div className="sticky bottom-0 z-20 border-t border-line bg-surface/95 px-4 py-3 shadow-[0_-6px_20px_-10px_rgb(28_23_20/0.18)] backdrop-blur pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <div className="mx-auto flex max-w-3xl items-center gap-2">
                        <Button
                            variant="outline"
                            size="lg"
                            onClick={() => {
                                setStep(1);
                                setError('');
                            }}
                            disabled={committing}
                        >
                            <ArrowLeft className="h-5 w-5" aria-hidden="true" />
                            Back
                        </Button>
                        <Button
                            variant="success"
                            size="lg"
                            className="flex-1"
                            onClick={commit}
                            disabled={committing || !dishCount}
                        >
                            {committing ? (
                                <>
                                    <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" />
                                    Importing…
                                </>
                            ) : (
                                <>
                                    <Check className="h-5 w-5" aria-hidden="true" />
                                    Import {dishCount} {dishCount === 1 ? 'dish' : 'dishes'}
                                </>
                            )}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

createRoot(document.getElementById('menu-import')).render(<App />);
