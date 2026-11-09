# Feature Specification: Branded e-mail layout with CID brand images (spec 220 S7)

**Feature Branch**: `feat/brand-s7`  
**Created**: 2026-11-09  
**Status**: Active (target 1.4.0, unreleased)  

**Package**: `nowo-tech/mail-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Depends on**: `nowo-tech/brand-contracts` ^1.0 (optional, `suggest`); a `BrandProviderInterface` /
`BrandAssetReaderInterface` such as InstanceSettings ^1.5 with `brand.enabled: true` (optional)

## Summary

Spec 220 ("brand fully configurable per project"), slice S7. Owner decisions of 2026-11-08: the brand contract is the
`nowo-tech/brand-contracts` package (Q-O1), e-mail images are CID attachments (Q-O6), SVG is never uploaded (Q-O4).
MailKit ships an optional branded e-mail layout (table layout, inline styles) and `BrandedEmailHelper`, which reads the
brand name and colours from the contract and attaches the `logo_light` / `mark` / `extra.email_*` images as inline CID
parts read through `BrandAssetReaderInterface`. Without a provider the layout prints the `nowo_mail_kit.brand`
fallback and e-mails are byte-identical.

## User Scenarios

### US-1 — The project's logo, name and colours in e-mails (P1)

**Given** a brand provider with a PNG `logo_light`, **When** the host sends a TemplatedEmail that extends the layout
through `BrandedEmailHelper::apply()`, **Then** the HTML part shows the logo as `<img src="cid:…">` whose Content-ID is
an inline image part of a `multipart/related` body, the name is the logo `alt` and the footer, and the colours are the
brand's light palette. Without a logo, the `mark` and the name are shown.

### US-2 — Nothing changes without a provider (P1)

**Given** no brand-contracts, no provider, `brand: off`, or a failing provider, **When** the same e-mail is sent,
**Then** `apply()` returns it untouched (same context, no attachment) and the layout prints `brand.name` /
`brand.colors`; the rendered HTML and text are identical in every one of these cases.

### US-3 — Hostile or broken brand images never reach the recipient and never block the send (P1)

**Given** an uploaded asset whose bytes are SVG, HTML or a PNG-magic polyglot, a config-default SVG, an image above
the byte or dimension limits, a reader that throws, or an asset with an `https://` URL and no reader, **When** the
e-mail is built, **Then** no image part is attached, nothing is fetched, the name and colours still apply, the event is
logged with the slot and a 12-character hash prefix, and the send succeeds.

### US-4 — Host templates embed other brand images (P2)

**Given** an `extra.email_mascot` slot, **When** the host calls `embed($email, 'extra.email_mascot')`, **Then** it gets
`cid:nowo-brand-extra-email-mascot.png` (one part, reused on a second call); other slots (`favicon`, `logo_dark`,
`app_icon`, `extra.<non-email>`) give null.

## Requirements

- **FR-001** Layouts `@NowoMailKit/email/branded_layout.html.twig` and `.txt.twig`; blocks `html_lang`, `title`,
  `preheader`, `header`, `content`, `footer`; `mail_brand` available in every block.
- **FR-002** `BrandedEmailHelper::apply(TemplatedEmail)`: context entry `nowo_mail_brand` (name, 7 colours, logo, mark)
  and the logo (else the mark) as an inline `DataPart` named `nowo-brand-<slot>.<ext>`; untouched without a brand.
- **FR-003** `BrandedEmailHelper::embed(Email, slot): ?string` for `logo_light`, `mark`, `extra.email_<id>` only.
- **FR-004** Images: bytes from `BrandAssetReaderInterface` only (never the URL); attached only when the magic bytes are
  PNG / JPEG / WebP and `getimagesizefromstring()` agrees; SVG refused whatever the origin; each ≤
  `brand.max_image_bytes`, together ≤ `brand.max_total_bytes`, sides ≤ `brand.max_image_side`.
- **FR-005** Escaping: Twig auto-escape in the HTML layout; `nowo_mail_kit_plain_text` (control / bidi / separator
  characters removed) in the plain-text layout under `autoescape false`; the view is re-checked by
  `nowo_mail_kit_brand()` (colours `#rrggbb`, image `src` `cid:nowo-brand-…`, integer sizes, safe name).
- **FR-006** Colours only from `BrandColors` (light) and `ColorMath` mixes, or the `#rrggbb`-validated `brand.colors`.
- **FR-007** Failures (provider, reader, image, unexpected error) are logged and never thrown; the send never fails
  because of the brand.
- **FR-008** Stateless, readonly services; the provider is asked per call (worker-safe).
- **FR-009** Config `nowo_mail_kit.brand` (`mode`, `name`, `colors`, limits) validated at compile time.

## Success Criteria

- Tests: `tests/Unit/Brand/*`, `tests/Integration/Brand/BrandedEmailTest.php`,
  `tests/Unit/DependencyInjection/BrandConfigurationTest.php` (CID present, non-raster upload refused, byte-identical
  without a provider, size limits, escaping); coverage stays 100 %.
- SECURITY.md threat rows; audit status re-verification pending (spec 220 S4–S7 batch audit).

## Out of scope

- Linking the public file URL instead of CID (Q-O6 "linking is an option"): not offered in 1.4.
- Dark-mode palettes in e-mails; per-locale names; using the eyebrow or wordmark.
- Rendering the mark in the footer (the header already shows the logo or the mark).
