# Changelog

## Unreleased

- Preserve optional tenant identity in entity serialization and signal reads; legacy entity construction and wire shape remain valid.
- Synchronize the additive security/risk schemas, including the bounded risk finding response. Security risk integration remains available through the documented HTTP boundary.
- Typed events, metrics, evaluation, replay and read contracts for Cbox Signals.
- Durable outbox transport and a signed receiver inbox with duplicate, ordering, rotation and expiry handling.
- Scoped entity erasure status, configuration acknowledgement and recent normalized observations.
- Independent PHP validation of shared wire fixtures, durable receiver tests and PHP 8.4/8.5 with Laravel 12/13 CI.
