# Changelog

## Unreleased - Security fixes and signup link

### Security
- Payment callbacks: the signature is always verified with the API secret of the order's payment gateway (a "secret" value in the callback data is never used), is rejected when no secret is configured, and is compared in constant time.
- Payment callbacks: the order must belong to the ViaBill transaction and use a ViaBill payment gateway, and the amount and currency must match the order total.
- Payment callbacks: repeated APPROVED callbacks no longer create another payment or capture again; a cancelled order is never re-opened and an order with an approved payment is never cancelled.
- Payment callbacks: error responses no longer contain exception details; JSON callbacks are read for any JSON content type.
- PriceTags: settings values are no longer part of Twig template source (template injection) and are escaped in HTML attributes; custom CSS can no longer close the style element and widths must be plain CSS lengths.
- The module's copy of the API secret is stored in the State API instead of the exportable `viabill_payments.settings` configuration (update `viabill_payments_update_10201` moves existing values).
- Checkout: the redirect URL must be an HTTPS ViaBill address and is escaped in the redirect script and link.
- ViaBill API requests use explicit connect/total timeouts and certificate verification; connection failures are handled.
- ViaBill notification messages on the settings page are XSS-filtered.
- Capture, refund and void use the credentials of their own payment gateway; the credentials are found whatever the gateway is named.
- Removed the unused ViaBill login/register form classes.

### Added
- A link to the ViaBill online signup form below the account credentials while they are missing.
- `version` in `viabill_payments.info.yml`.
- Styling options for the legal note (font size, font style, text color, text alignment, margins and extra CSS).
- The legal note is always shown, with the default text in the selected language. Its default font style is normal.

### Fixed
- PriceTag width rules (invalid CSS, wrong selector on product pages) and the PriceTag currency on product pages.

## Version 2.1.3 - CCD2 Legal Note Support

### Changed
- Updated `core_version_requirement` in `viabill_payments.info.yml` to support Drupal 11 (`^10 || ^11`)
- Updated `composer.json` to support Commerce 3.x (`^2.0 || ^3.0`)
- Replaced deprecated `REQUEST_TIME` constant with `$this->time->getRequestTime()` in payment capture method
- Ensured PHP 8.3 compatibility by verifying no deprecated PHP functions are used

### Technical Details
- **Drupal Compatibility**: Now supports Drupal 10, and 11
- **Commerce Compatibility**: Now supports Commerce 2.x and 3.x (including 3.2.x)
- **PHP Compatibility**: Compatible with PHP 8.1, 8.2, and 8.3
- **Constructor**: Maintains proper dependency injection with nullable `MinorUnitsConverterInterface`
- **Payment Gateway**: Extends `OffsitePaymentGatewayBase` (no changes required)
- **Plugin Annotation**: Remains compatible with current Commerce API

### Tested Against
- Drupal 11.x
- Drupal Commerce 3.2.x
- PHP 8.3

### Notes
- No breaking changes to the module's API or functionality
- All existing features remain intact
- Module follows Drupal coding standards and best practices
- Uses proper dependency injection where applicable
- Static `\Drupal::` calls remain for backward compatibility (not ideal but functional)

### Migration Notes
If upgrading from a previous version:
1. Clear all caches after updating the module
2. Verify payment gateway configuration remains intact
3. Test payment operations (authorize, capture, void, refund) in a staging environment
4. Review any custom code that extends this module for compatibility

### Known Issues
- None identified at this time

### Future Improvements
- Consider refactoring remaining `\Drupal::` static calls to use dependency injection
- Add automated tests for payment gateway operations
- Improve code documentation

