# ViaBill Payments for Drupal Commerce

This module provides a ViaBill Payments gateway integration for Drupal Commerce, enabling merchants to accept payments through the ViaBill payment service.

## Requirements

This module requires the following:

- **Drupal**: 10.x or 11.x
- **PHP**: 8.1, 8.2, or 8.3 (8.3 recommended for Drupal 11)
- **Drupal Commerce**: 3.x (including 3.2.x)
- **Required Modules**:
  - commerce
  - commerce_payment
  - commerce_log

## Installation

### Using Composer (Recommended)

```bash
composer require myname/viabill_payments
```

### Manual Installation

1. Download the module and extract it to your `modules/custom` or `modules/contrib` directory
2. Enable the module using Drush or the Drupal admin interface:

```bash
drush en viabill_payments
```

Or navigate to **Extend** in the Drupal admin interface and enable "ViaBill Payments"

## Configuration

### Step 1: Register or Login with ViaBill

Before configuring the payment gateway, you need to obtain API credentials from ViaBill:

1. Navigate to **Commerce** → **Configuration** → **Payment gateways**
2. Click **Add payment gateway**
3. Select **ViaBill Payments** as the plugin
4. You will see a notice prompting you to log in or register with ViaBill
5. Click the login or register link to obtain your API credentials

### Step 2: Configure the Payment Gateway

Once you have your API credentials:

1. The API Key and API Secret will be automatically populated from your ViaBill account
2. Configure the following settings:
   - **Transaction Type**: Choose between "Authorize Only" or "Authorize and Capture"
   - **PriceTag Settings**: Configure how ViaBill price tags are displayed on your site
   - **Country and Language**: Set the default country and language for price tags

### Step 3: Enable PriceTags (Optional)

ViaBill PriceTags show customers how much they pay per month when using ViaBill. To enable them:

1. Follow the instructions in the payment gateway configuration form
2. Edit your theme's twig templates to include the PriceTag snippet where needed
3. Configure alignment, width, and dynamic pricing options as desired

## Features

### Payment Operations

- **Authorize Only**: Reserve funds without capturing them immediately
- **Authorize and Capture**: Automatically capture funds when authorized
- **Capture**: Manually capture previously authorized payments (full or partial)
- **Void**: Cancel an authorized payment before capture
- **Refund**: Refund captured payments (full or partial)

### PriceTags

- Display monthly payment amounts to customers
- Auto-detect or manually set country and language
- Dynamic pricing support for product variations
- Customizable alignment and width
- Automatic or manual insertion into product pages

### Logging

The module integrates with Commerce Log to track:
- Payment transactions
- Partial captures
- Refunds and voids
- API communication errors

## Upgrading to Drupal 11 / Commerce 3.2

This version has been updated for full compatibility with Drupal 11 and Commerce 3.2. When upgrading:

1. **Backup your database** before upgrading
2. Update Drupal core to 11.x (if applicable)
3. Update Commerce to 3.2.x (if applicable)
4. Update this module to the latest version
5. Clear all caches: `drush cr`
6. Verify your payment gateway configuration
7. Test payment operations in a staging environment before going live

### Changes in This Version

- Added support for Drupal 11
- Added support for Commerce 3.2.x
- Ensured PHP 8.3 compatibility
- Replaced deprecated `REQUEST_TIME` constant
- Updated composer dependencies

See `CHANGELOG.md` for detailed changes.

## Troubleshooting

### API Credentials Not Showing

If your API credentials are not automatically populated:
1. Visit the ViaBill login/register form from the payment gateway configuration page
2. Enter your ViaBill account credentials
3. The API key and secret will be stored and automatically used

### PriceTags Not Displaying

If PriceTags are not showing on your product pages:
1. Verify that you have inserted the PriceTag snippet in your theme templates
2. Check that the PriceTag script is configured in the payment gateway settings
3. Clear all caches: `drush cr`
4. Inspect the page source to ensure the PriceTag div is present

### Payment Failures

If payments are failing:
1. Check the Commerce logs at **Commerce** → **Logs**
2. Verify your API credentials are correct
3. Ensure your ViaBill account is active and properly configured
4. Check the Drupal logs at **Reports** → **Recent log messages**

## Support

For issues related to:
- **Module functionality**: Create an issue in the module's issue queue
- **ViaBill service**: Contact ViaBill support through your merchant account
- **Drupal Commerce**: Refer to the [Commerce documentation](https://docs.drupalcommerce.org/)

## License

This module is licensed under GPL-2.0-or-later.

## Credits

Developed for Drupal Commerce integration with ViaBill payment services.

