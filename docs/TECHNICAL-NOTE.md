# Keeping uncertainty out of saved menu data

A vision model can produce plausible JSON while misreading a price or dietary label. A restaurant menu importer must therefore separate extraction from persistence.

The workflow here has three steps: extract a draft, let the owner review it, then validate the submitted data before saving it in a transaction. The model does not write database rows.

Three failures illustrate why that boundary matters:

- In PHP, casting the nonempty string `"false"` to a boolean gives `true`. Dietary labels need explicit parsing, with a third value—`null`—for uncertainty.
- Removing nonnumeric characters from `-50` produces `50`. A parser should preserve meaning, so this implementation accepts a narrow decimal format and leaves invalid prices unresolved.
- Treating malformed model JSON as an empty menu hides a provider failure. Throwing a validation error lets the configured fallback provider run; a valid empty categories list remains a legitimate empty result.

These decisions are covered by offline tests with mocked HTTP responses. A separate live evaluation used four synthetic images. All 24 expected dish instances matched the scored fields, but six dishes were reused in the blurred fixture. Eighteen distinct dishes do not establish production accuracy. The evaluation records that limitation rather than turning a small success into a broad percentage claim.

The next improvements would be real-menu evaluation, explicit review of conflicting prices across photos, and consistent currency handling. A database transaction also does not by itself make concurrent imports exactly-once; that needs an independently tested uniqueness/idempotency design.

**AI assistance:** This technical note, implementation and verification materials were prepared with extensive AI assistance. It is included transparently as an AI-assisted explanation of the supplied code.
