/**
 * @file
 * JavaScript to generate RevolutCheckout token in PCI-compliant way.
 */

(function ( Drupal, drupalSettings) {

  'use strict';

  /**
   * Attaches the commerceRevolut behavior.
   */
  Drupal.behaviors.commerceRevolut = {

    attach: function (context) {
      if (!drupalSettings.commerceRevolut || !drupalSettings.commerceRevolut.publicKey) {
        return;
      }

      const revolutForm  = document.querySelector('.revolut-form');
      if (revolutForm && !revolutForm.classList.contains('revolut-processed')) {
        revolutForm.classList.add('revolut-processed');
        const integration_type = drupalSettings.commerceRevolut.integration;
        const checkoutForm = document.querySelector('.commerce-checkout-flow');
        const billing = drupalSettings.commerceRevolut.billing;
        let email = drupalSettings.commerceRevolut.email;

        switch (integration_type) {
          case 'revolut_checkout':
            RevolutCheckout(drupalSettings.commerceRevolut.token, drupalSettings.commerceRevolut.mode).then(function (instance) {
              const card = instance.createCardField({
                target: revolutForm.querySelector('#revolut-integration'),
                onSuccess() {
                  checkoutForm.querySelector('input.button--primary')?.setAttribute('disable', true);
                  checkoutForm.submit()
                },
                onError(error) {
                  revolutErrorHandling(checkoutForm, error.message ?? null);
                },
                onValidation(errors) {
                  let validation_error = '';
                  for (const error of errors) {
                    validation_error += error.message + '</br>'
                  }
                  revolutErrorHandling(checkoutForm, validation_error);
                },
                onCancel() {
                  window.alert('Cancel');
                },
              });

              // Take over form submission
              checkoutForm.addEventListener("submit", (event) => {
                let payment_method_id = checkoutForm.querySelector('#revolut-payment-method-id');
                if (!payment_method_id || payment_method_id.length > 0) {
                  return true;
                }
                event.preventDefault();
                const formData = new FormData(checkoutForm);

                let payload = {
                  savePaymentMethodFor: 'merchant'
                }

                // We have billing information.
                if (billing) {
                  payload.name = billing.given_name + ' ' + billing.family_name;
                  payload.cardholderName = billing.name;
                  payload.billingAddress = {
                    countryCode : billing.country_code,
                    region : billing.administrative_area,
                    city : billing.locality,
                    postcode : billing.postal_code,
                    streetLine1 : billing.address_line1,
                  };
                }
                else {
                  payload.name = formData.get('payment_information[add_payment_method][billing_information][address][0][address][given_name]') + ' ' + formData.get('payment_information[add_payment_method][billing_information][address][0][address][family_name]')
                }

                email = email ?? formData.get('contact_information[email]')

                if (email) {
                  payload.email = email;
                }

                card.submit(payload);
                payment_method_id.setAttribute('value', drupalSettings.commerceRevolut.order.id)
              });
            });

            break;

          case 'revolut_pay':
            const { revolutPay } = RevolutCheckout.payments({
              publicToken: drupalSettings.commerceRevolut.publicKey,
              mode: drupalSettings.commerceRevolut.mode,
            })

            const paymentOptions = {
              currency: drupalSettings.commerceRevolut.order.currency,
              totalAmount: drupalSettings.commerceRevolut.order.amount,
              // We can't just push existing token directly?.
              createOrder: async () => {
                return { publicId: drupalSettings.commerceRevolut.token }
              },
            }

            checkoutForm.querySelector('input.button--primary')?.setAttribute('disabled', true);
            console.log(paymentOptions)

            let revolut_button = document.getElementById('revolut-integration');
            if (!revolut_button.classList.contains('revolut-processed')) {
              revolutPay.mount(revolut_button, paymentOptions);
              revolut_button.classList.add('revolut-processed');
            }

            revolutPay.on('payment', (event) => {
              switch (event.type) {
                case 'cancel': {
                  revolutErrorHandling(checkoutForm, 'The payment was cancelled')
                  break
                }

                case 'success':
                  let payment_method_id = checkoutForm.querySelector('#revolut-payment-method-id');
                  payment_method_id.setAttribute('value', drupalSettings.commerceRevolut.order.id)
                  checkoutForm.submit();
                  break

                case 'error':
                  revolutErrorHandling(checkoutForm, event.error)
                  break
              }
            })

            break;
        }
      }
    },


    detach: function (context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      const form = document.querySelector('.revolut-form');
      if (!form) {
        return;
      }
      form.classList.remove('revolut-processed')

      document.querySelector('.commerce-checkout-flow')?.querySelector('input.button--primary')?.removeAttribute('disabled');
    },
  };

  /**
   *
   * @param {element} checkoutForm
   * @param {string|null} message
   */
  function revolutErrorHandling(checkoutForm, message) {
    const payment_error = checkoutForm.querySelector('#payment-errors');
    payment_error.innerHTML = '';
    if (message) {
      const error_wrapper = document.createElement('div');
      error_wrapper.classList.add('payment-messages', 'payment-messages--error');
      error_wrapper.innerHTML = message;
      payment_error.append(error_wrapper);
    }
  }

})(Drupal, drupalSettings);
