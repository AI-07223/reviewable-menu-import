# Two-minute walkthrough script

This is a script for a recording, not a claim that a video has been recorded. Use only the synthetic fixtures and a disposable tenant.

- **0:00–0:20 — Problem:** “This workflow turns a photographed menu into an editable draft for a restaurant owner. AI extraction is useful, but it cannot be trusted to save business data directly.” Show clear.png.
- **0:20–0:45 — Review:** Upload and scan. Show editable names, prices, categories and dietary flags. Change a field. Select an unresolved dietary value and show that import is blocked.
- **0:45–1:05 — Persistence:** Resolve the flag, import, open Menu Items, and reload to show the change persisted. Do not imply currency formatting is fixed.
- **1:05–1:30 — Code:** Show decodeJson, dietaryFlag and num. Explain malformed responses trigger fallback, `"false"` must not become true, and `-50` must not become `50`.
- **1:30–1:50 — Evidence:** Run composer test and show the four-fixture evaluation. Say it is synthetic and small; do not say “100% accurate.”
- **1:50–2:00 — Ownership and next step:** “I use AI coding tools extensively. This sample shows the workflow and the verification around it. Next I would broaden real-menu evaluation and align currency handling across the app.”

Before recording, be able to explain the code above without reading this script. If asked something you do not know, say so and reason from the source rather than claiming experience you do not have.
