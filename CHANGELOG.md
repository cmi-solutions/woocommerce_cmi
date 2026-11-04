# Changelog

## 2.0.0

A major update. Some things now work differently, so please read **Before you update** and **Changes to how the plugin works** before installing it.

### Before you update

- Your site needs **PHP 8.3**, **WordPress 6.7** and **WooCommerce 9.0** or later.
- Order numbers will include a prefix, for example `WCA247-1050`. You can change it in the settings.
- After updating, test a payment, a confirmation and a refund using CMI's test environment.

### New

- **Manage payments from WooCommerce:** confirm, void, refund (full or partial) and check a payment's status at CMI, from the orders list or the order page.
- **Safety checks before every action:** before confirming, voiding or refunding, the plugin checks with CMI that the payment belongs to the order, so it won't act on another website's payment.
- **Stays in sync with the CMI back office:** a payment confirmed or voided in CMI updates the order in WooCommerce.
- **Refunds in other currencies** are calculated in MAD, using the rate applied at payment, so they match what CMI charged.
- **Supported currency plugins:** FOX / WOOCS, WPML + WCML, CURCY and Aelia Currency Switcher, or your own rates entered in the settings.
- **Same order number** in WooCommerce and in the CMI back office.
- **Automatic status checks** for Manual-mode orders when the customer doesn't return to the shop after paying.
- **Old pre-authorization warning:** before confirming a pre-authorization older than 5 days, you're advised to inform the customer, to reduce the risk of chargebacks.
- **Automatic Redirect** setting: choose whether CMI sends the customer back to your shop after paying.
- **Show/hide icon** on the Store Key and API Password fields.
- **English and French**, following your WordPress language.
- **HPOS and Checkout Blocks** support.

### Changes to how the plugin works

| Change | What you may need to do |
| --- | --- |
| Order numbers include a prefix, in WooCommerce and in CMI | Choose a readable prefix if you prefer, such as your shop's initials. If you use one CMI account on several websites, give each a different prefix |
| The **Order Identifier Format** setting was removed: the order number is always used | Nothing, unless you relied on sending WordPress's internal order ID |
| In **Automatic** mode, CMI captures the payment itself when it notifies your shop, even with the API configured | Your shop must be reachable from the internet |
| Refunds of orders in other currencies are calculated in MAD | For orders paid in another currency before 2.0.0, refund from the CMI back office |
| **Cancel** on CMI's page can no longer cancel or void an order that has a payment | If a customer asks to cancel after paying, void or refund the payment yourself |
| Payments in another currency are refused if no valid exchange rate is found | Set up your rates in the settings, or add MAD to your currency plugin |
| A Store Key containing the word "SKS" can't be saved | Ask CMI for a new key if yours contains it |
| Deleting the plugin removes the CMI connection credentials (your orders are kept) | Enter them again if you reinstall the plugin |
| The `woocommerce_cmi_exchange_rate` and `woocommerce_cmi_order_amount_mad` filters were removed | Developers who used them should switch to Manual rates or a supported currency plugin |
| Texts are in English with a French translation | Custom translations made for an earlier version need to be redone |

### Fixes

- Payments are matched to the right order, including on shops using sequential order numbers.
- **Check CMI** marks an order paid only when CMI reports the payment as captured.
- A late or repeated message from CMI can no longer change an order that was already paid or authorized.
- The CURCY and WPML + WCML currency integrations now read the correct exchange rates.
- Partial refunds made in CMI are no longer shown as full refunds.
- Refunds made in the CMI back office are recorded in WooCommerce when you click **Check CMI**, now also shown next to **Refund**. A refund from WooCommerce is refused until the order is up to date, so it can't use a wrong balance.
- The customer no longer sees a blank page if the return to the shop runs into an error.
- Admin pages no longer show an "Install the WooThemes Updater" notice, and unused files were removed.
- Payments are no longer rejected by CMI because another plugin (such as CleanTalk or TranslatePress) added hidden fields to the payment form.
- The plugin's files can no longer be opened directly in a browser.
