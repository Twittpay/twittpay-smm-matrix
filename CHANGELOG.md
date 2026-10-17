# Changelog

## 2.7.0 - 2026-10-17
- Sends the customer's real name, email, phone and address; a missing field is left out (no placeholder values), so the customer goes straight to payment
- Retries up to 3 times on DNS or connection failures (connect timeout 8s); real gateway answers are never retried
- Sends meta_data as documented by TwittPay and reads either meta_data or metadata back

## 2.6.0 - 2026-10-03
- Release: Brand Key only, built-in checkout address

## 2.5.1 - 2026-10-03
- Optional Endpoint URL with secure default, HTTPS enforcement and Brand Key labels

## 1.0.0 - 2026-10-03
- Initial release: rebranded TwittPay addons
