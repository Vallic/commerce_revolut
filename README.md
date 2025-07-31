Commerce Revolut
===============

CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Configuration

INTRODUCTION
------------
This module integrates Drupal Commerce with various Revolut payment solutions,
including the Revolut Pay [1], the Card payments [2] and the Payment Links [3].

1. https://developer.revolut.com/docs/guides/accept-payments/payment-methods/revolut-pay/introduction
2. https://developer.revolut.com/docs/guides/accept-payments/payment-methods/card-payments/introduction
3. https://developer.revolut.com/docs/guides/accept-payments/tutorials/payment-link

Revolut supports many payment method types, including credit card, Revolut wallet,
bank transfers, and more. Supports Strong Customer Authentication (e.g., 3D
Secure), and secure payment method tokenization.

## Features

* Supports checkout flow with and without a review pane: no configuration required.
* Uses the Revolut Checkout Widget library that ensures card data never touches your server
* Payments in Drupal Commerce synchronized with Revolut
* Supports voids, captures, and refunds through the order management interface
* Option to use Hosted checkout pages or embedded within Commerce Core checkout.


REQUIREMENTS
------------
This module should be added to your codebase via Composer

`composer require "drupal/commerce_revolut:^1.0"`

You must also have a Revolut merchant account or developer access to the account
you intend to configure for your integration.

There are no other requirements than [Commerce Core 3](https://www.drupal.org/project/commerce)

You can sign up for one [here](https://business.revolut.com/signup?promo=referabusiness&ext=ff26eea7-8586-388f-949d-b6ab33ab3a89&context=B2B_REFERRAL).


CONFIGURATION
-------------
Once you've installed the module, you must navigate to the Drupal Commerce
payment gateway configuration screen to define a payment gateway configuration.
This will require providing API keys and configuring the mode (Live vs. Test)
along with a variety of appearance related options depending on the plugin.

Note: Drupal Commerce recommends storing live API credentials outside of the
configuration object and importing them via a configuration override in your
settings.php file. To achieve this, you can input only your test credentials
for validation in the configuration form or input `PLACEHOLDER` and uncheck the
box that instructs the configuration form to validate your API keys.


