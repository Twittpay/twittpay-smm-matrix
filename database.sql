-- ===========================================================================
--  TWITTPAY - SMM Matrix
--  Adds the gateway row. Import this once, in phpMyAdmin, into your panel's
--  database. Nothing else in the database is touched.
--
--  The Brand Key and Endpoint URL are left empty on purpose, and the row is added
--  switched off - fill the two fields in from the admin area, then enable it.
-- ===========================================================================

INSERT INTO `gateways` (`id`, `code`, `name`, `sort_by`, `image`, `driver`, `status`, `parameters`, `currencies`, `extra_parameters`, `supported_currency`, `receivable_currencies`, `description`, `currency_type`, `is_sandbox`, `environment`, `is_manual`, `note`, `created_at`, `updated_at`) VALUES
(NULL, 'twittpay', 'Bkash/Nagad/Rocket/Upay', 1, 'gateway/twittpay.png', 'local', 0, '{\"api_key\":\"\",\"api_url\":\"\"}', '{\"0\":{\"BDT\":\"BDT\"}}', '', '[\"BDT\"]', '[{\"name\":\"BDT\",\"currency_symbol\":\"BDT\",\"conversion_rate\":\"120\",\"min_limit\":\"1\",\"max_limit\":\"100000\",\"percentage_charge\":\"0\",\"fixed_charge\":\"0\"}]', 'bKash, Nagad, Rocket, Upay and cards', 1, 0, 'live', 0, '', '2026-01-01 00:00:00', '2026-01-01 00:00:00');
