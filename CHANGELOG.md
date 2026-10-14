# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

- `CustomerTimelineEraserInterface` / `CustomerTimelineEraser`: erases personal data of a
  customer from their timeline entries (e-mail recipients, author name snapshots, checkout
  note content) in a single query + single flush. Intended to be called from the
  application's GDPR anonymisation process.
- Console command `tmonier:timeline:erase-customer <id-or-email> [--dry-run]` to trigger
  erasure from the CLI.

### Security

- **GDPR right to erasure**: e-mail addresses stored in `context.recipients` of `TYPE_EMAIL`
  entries, customer full-name snapshots in `author_name`, and checkout notes in `content`
  were not removed when a customer was anonymised (only deletion via `ON DELETE CASCADE` was
  covered). The new eraser service fills this gap.

## [1.0.0] — Initial release
