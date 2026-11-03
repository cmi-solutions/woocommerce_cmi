# WooCommerce CMI Gateway

A WooCommerce payment gateway for [CMI](https://www.cmi.co.ma/) (Centre Monétique Interbancaire, Morocco). Customers pay on CMI's secure hosted page, and merchants can confirm, void, refund and check payments directly from WooCommerce.

Updating from an earlier version? Read the [changelog](CHANGELOG.md) first: version 2.0.0 changes how some things work.

## Supported versions

| Component | Minimum | Tested up to |
| --- | --- | --- |
| PHP | 8.3 | – |
| WordPress | 6.7 | 7.0.4 |
| WooCommerce | 9.0 | 11.0.1 |

The plugin supports:

- **High-Performance Order Storage (HPOS)**, WooCommerce's order storage, as well as the older storage in WordPress posts.
- **Cart & Checkout Blocks**, as well as the classic checkout.

## Features

- **Secure hosted payment:** customers enter their card details on CMI's 3-D Secure page, never on your site.
- **Automatic or manual capture:** capture the payment immediately, or pre-authorize it and confirm later.
- **Manage payments from WooCommerce:** confirm, void, refund (full or partial) and check a payment's status at CMI, from the orders list or the order page.
- **Other currencies:** CMI always charges in MAD. Orders in other currencies are converted, show both amounts, and are refunded using the rate applied at payment.
- **Supported currency plugins:** FOX / WOOCS, WPML + WCML, CURCY and Aelia Currency Switcher, or your own rates.
- **Same order number everywhere:** orders show the same number in WooCommerce and in the CMI back office.
- **Safe with several websites:** if one CMI account is used by several websites, the plugin never acts on another website's payment.
- **Stays in sync with the CMI back office:** a payment confirmed or voided in CMI updates the order in WooCommerce.
- **Old pre-authorization warning:** before confirming a pre-authorization older than 5 days, you're advised to inform the customer, to reduce the risk of chargebacks.
- **English and French**, following your WordPress language.
- **HPOS and Checkout Blocks** support.

## Installation

1. Create a zip file of the `cmi` folder, so that the zip contains `cmi/gateway-cmi.php`.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the zip, and activate the plugin.
3. Open **WooCommerce → Settings → Payments → CMI** and enter the details provided by CMI (see [Configuration](#configuration)).
4. Before going live, test a payment, a confirmation and a refund using CMI's test environment.

**Your shop must be reachable from the internet.** With Automatic confirmation, CMI contacts your shop after each payment. A site running only on your computer won't receive these messages.

**Deleting the plugin** (from the Plugins page, not just deactivating it) removes the CMI connection credentials: Client ID, Store Key, payment page URL, and API URL, username and password. Your other settings and all order data are kept, so past orders keep their payment history.

## Configuration

All settings are in **WooCommerce → Settings → Payments → CMI**. Fields marked with * are required.

| Section | Settings |
| --- | --- |
| General | Enable CMI, payment title and description shown at checkout |
| Payment Setup | Kit type, confirmation mode, order status after capture, payment page language, automatic redirect, order identifier prefix |
| Gateway Connection | Client ID, Store Key, Payment Page URL. The Store Key must not contain the word "SKS" |
| API Management Credentials | CMI API URL, username and password (Complete Kit only) |
| Currency & Exchange Rates | How amounts in other currencies are converted to MAD |

### Kit type

| Kit | What it offers |
| --- | --- |
| **Standard** | Customers pay on CMI's page. You confirm, void and refund payments in the CMI back office |
| **Complete** | Adds a **CMI Actions** column to the orders list, to confirm, void, refund and check payments from WooCommerce. Requires the API credentials |

### Payment confirmation

| Mode | What happens |
| --- | --- |
| **Automatic** | The payment is captured as soon as the customer pays |
| **Manual** | The payment is only pre-authorized: the money is reserved on the customer's card, and the order goes **On hold** until you confirm or void it |

In Manual mode, the order is updated when the customer returns to your shop after paying. If the customer doesn't return:

- **Complete Kit:** the plugin checks CMI automatically about 15 minutes, 1 hour and 6 hours later, and moves the order to **On hold** if the payment is pre-authorized. These checks never confirm, void or refund a payment. You can also click **Check CMI** at any time.
- **Standard Kit:** check the CMI back office and update the order yourself.

WooCommerce's **Hold stock** setting (**WooCommerce → Settings → Products → Inventory**) can cancel unpaid orders automatically. Set it to more than 15 minutes, or leave it empty, so the plugin's first check runs first.

### Order status after capture

The status an order takes once its payment is captured: **Completed**, **Processing**, **Confirmed**, **On hold**, or a custom status you name.

### Payment page language

The language of CMI's payment page: **Auto** (your shop's language), French, English or Arabic.

### Automatic redirect

| Setting | After paying |
| --- | --- |
| **Enabled** (default) | CMI sends the customer straight back to your shop |
| **Disabled** | The customer stays on CMI's result page and returns to your shop themselves |

### Order identifier prefix

Each order is sent to CMI as `prefix + order number`, for example `WCA247-1050`, and shows this same number in WooCommerce.

- A unique prefix is created automatically. You can replace it with something readable, such as your shop's initials (letters, numbers, `-` and `_`; 20 characters maximum).
- **If you use one CMI account on several websites, give each website a different prefix.**
- Orders already sent to CMI keep their number if you change the prefix.

### Other currencies

CMI always charges in **MAD**. If your shop sells in another currency, choose an **Exchange Rate Mode**:

| Mode | When to use it |
| --- | --- |
| **Not needed** | Your shop sells only in MAD |
| **Manual** | You enter the rates yourself, in the table under this setting |
| **External Multi-Currency Plugin** | The rates come from FOX / WOOCS, WPML + WCML, CURCY or Aelia Currency Switcher |

**Manual rates:** enter how much of the currency **1 MAD** is worth. For example, if 1 MAD = 0.0926 EUR, enter `0.0926`, and a 100 EUR order is charged 1,079.91 MAD. Entering the rate the wrong way round (for example `10.8`) would charge far too little, so check the amount on CMI's page during your test.

**External plugin:** **MAD must be added as a currency in that plugin**, with a rate. The free versions of FOX and CURCY allow only two currencies, so one of them must be MAD. With any other currency plugin, use Manual.

If no valid rate is found, the payment is refused with a message, rather than charging a wrong amount. You are responsible for the accuracy of the exchange rates.

## License

GPL-2.0-or-later
