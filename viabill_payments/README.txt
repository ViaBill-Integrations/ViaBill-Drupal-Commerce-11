ViaBill Payments module for Drupal Commerce
===========================================

Provides integration between Drupal Commerce and ViaBill, a "Buy Now, Pay Later"
(BNPL) payment service. With this module, merchants can offer customers the option 
to split payments into monthly installments through their ViaBill merchant account.

Project homepage: https://www.drupal.org/project/viabill_payments
ViaBill official site: https://viabill.com/

--------------------------------------------------------------------------------
CONTENTS OF THIS FILE
--------------------------------------------------------------------------------

 * Introduction
 * Requirements
 * Installation
 * Configuration
 * Troubleshooting
 * Maintainers

--------------------------------------------------------------------------------
INTRODUCTION
--------------------------------------------------------------------------------

The ViaBill Payments module integrates ViaBill with Drupal Commerce, allowing 
merchants to offer deferred payment options and improve the checkout experience 
for customers.

Key features:
 - Adds ViaBill as a payment gateway in Drupal Commerce.
 - Supports both test and live modes via API credentials.
 - Secure redirection to the ViaBill-hosted payment page.
 - Optional display of monthly installment pricing on product pages.
 
Please note that ViaBill Payments are available to merchants with a business address in Denmark and Spain only. 

--------------------------------------------------------------------------------
REQUIREMENTS
--------------------------------------------------------------------------------

This module requires the following:

 - Drupal 9.4 or later (Drupal 10.x supported)
 - Commerce module (2.x)
 - A valid ViaBill merchant account (https://viabill.com/)
 - API credentials (test/live) from ViaBill

--------------------------------------------------------------------------------
INSTALLATION
--------------------------------------------------------------------------------

1. Install the module using the Drupal UI or Drush:

   drush en viabill_payments

2. Clear the cache:

   drush cr

3. Add the payment gateway:

   - Go to: /admin/commerce/config/payment-gateways
   - Click "Add payment gateway"
   - Choose type: "ViaBill Payments"
   - Enter label, plugin configuration (API keys), and select test/live mode.

--------------------------------------------------------------------------------
CONFIGURATION
--------------------------------------------------------------------------------

After adding the ViaBill payment gateway, configure the following:

 - API credentials (test/live keys)
 - Enable logging (for debugging purposes)
 - Enable pricing widget (optional display of monthly payment info on product/cart pages)
 - Currency settings (must match ViaBill-supported currencies, i.e. DKK for EUR)
 - Order status mapping after payment success/failure

Optional: You may embed the ViaBill price breakdown widget via block or template integration.

--------------------------------------------------------------------------------
TROUBLESHOOTING
--------------------------------------------------------------------------------

If the payment gateway is not visible at checkout:
 - Ensure the order total is within ViaBill's minimum/maximum limits.
 - Check that the correct currency is used (e.g., DKK, EUR).
 - Review logs at /admin/reports/dblog or via Drush (`drush watchdog:show`).

For API errors:
 - Double-check your test/live credentials.
 - Use test mode first to verify connectivity.

For support, please create an issue in the module’s Drupal.org issue queue.

--------------------------------------------------------------------------------
MAINTAINERS
--------------------------------------------------------------------------------

Current Maintainer:
 * Dimirios Mourloukos - https://www.drupal.org/u/mourlouk

Contributions, patches, and feature requests are welcome via the project issue queue.

--------------------------------------------------------------------------------
LICENSE
--------------------------------------------------------------------------------

This project is GPL-2.0+ licensed. See LICENSE.txt for details.