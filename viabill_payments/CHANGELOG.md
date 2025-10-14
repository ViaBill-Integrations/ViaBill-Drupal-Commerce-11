# Changelog

## Version 3.0.0 - Drupal 11 and Commerce 3.2 Compatibility Update

### Changed
- Updated `core_version_requirement` in `viabill_payments.info.yml` to support Drupal 11 (`^9 || ^10 || ^11`)
- Updated `composer.json` to support Commerce 3.x (`^2.0 || ^3.0`)
- Replaced deprecated `REQUEST_TIME` constant with `$this->time->getRequestTime()` in payment capture method
- Ensured PHP 8.3 compatibility by verifying no deprecated PHP functions are used

### Technical Details
- **Drupal Compatibility**: Now supports Drupal 9, 10, and 11
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

