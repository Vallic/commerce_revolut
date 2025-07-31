/**
 * @file
 * JavaScript to generate RevolutCheckout token in PCI-compliant way.
 */

(function (Drupal, drupalSettings) {
  /**
   * Handle error display.
   *
   * @param {Element} checkoutForm
   * @param {string|null} message
   */
  function revolutErrorHandling(checkoutForm, message) {
    const paymentError = checkoutForm.querySelector('#payment-errors');
    paymentError.innerHTML = '';
    if (message) {
      const errorWrapper = document.createElement('div');
      errorWrapper.classList.add('payment-messages', 'payment-messages--error');
      errorWrapper.innerHTML = message;
      paymentError.append(errorWrapper);
    }
  }

  /**
   * Attaches the commerceRevolut behavior.
   */
  Drupal.behaviors.commerceRevolut = {
    attach(context) {
      if (
        !drupalSettings.commerceRevolut ||
        !drupalSettings.commerceRevolut.publicKey
      ) {
        return;
      }

      const revolutForm = document.querySelector('.revolut-form');
      if (revolutForm && !revolutForm.classList.contains('revolut-processed')) {
        revolutForm.classList.add('revolut-processed');
        const integrationType = drupalSettings.commerceRevolut.integration;
        const checkoutForm = document.querySelector('.commerce-checkout-flow');
        const billing = drupalSettings.commerceRevolut.billing;
        let email = drupalSettings.commerceRevolut.email;

        switch (integrationType) {
          case 'revolut_checkout':
            /* global RevolutCheckout */
            RevolutCheckout(
              drupalSettings.commerceRevolut.token,
              drupalSettings.commerceRevolut.mode,
            ).then(function (instance) {
              const card = instance.createCardField({
                target: revolutForm.querySelector('#revolut-integration'),
                onSuccess() {
                  checkoutForm
                    .querySelector('input.button--primary')
                    ?.setAttribute('disable', 'true');
                  checkoutForm.submit();
                },
                onError(error) {
                  revolutErrorHandling(checkoutForm, error.message ?? null);
                },
                onValidation(errors) {
                  let validationError = '';
                  for (const error of errors) {
                    validationError += `${error.message}</br>`;
                  }
                  revolutErrorHandling(checkoutForm, validationError);
                },
                onCancel() {
                  revolutErrorHandling(
                    checkoutForm,
                    'The payment was cancelled',
                  );
                },
              });

              // Take over form submission
              checkoutForm.addEventListener('submit', (event) => {
                const paymentMethodId = checkoutForm.querySelector(
                  '#revolut-payment-method-id',
                );
                if (!paymentMethodId || paymentMethodId.length > 0) {
                  return true;
                }
                event.preventDefault();
                const formData = new FormData(checkoutForm);

                const payload = {
                  savePaymentMethodFor: 'merchant',
                };

                // We have billing information.
                if (billing) {
                  payload.name = `${billing.given_name} ${billing.family_name}`;
                  payload.cardholderName = billing.name;
                  payload.billingAddress = {
                    countryCode: billing.country_code,
                    region: billing.administrative_area,
                    city: billing.locality,
                    postcode: billing.postal_code,
                    streetLine1: billing.address_line1,
                  };
                } else {
                  payload.name = `${formData.get(
                    'payment_information[add_payment_method][billing_information][address][0][address][given_name]',
                  )} ${formData.get(
                    'payment_information[add_payment_method][billing_information][address][0][address][family_name]',
                  )}`;
                }

                email = email ?? formData.get('contact_information[email]');

                if (email) {
                  payload.email = email;
                }

                card.submit(payload);
                paymentMethodId.setAttribute(
                  'value',
                  drupalSettings.commerceRevolut.order.id,
                );
              });
            });

            break;

          case 'revolut_pay':
            /* global RevolutCheckout */
            const { revolutPay } = RevolutCheckout.payments({
              publicToken: drupalSettings.commerceRevolut.publicKey,
              mode: drupalSettings.commerceRevolut.mode,
            });

            const paymentOptions = {
              currency: drupalSettings.commerceRevolut.order.currency,
              totalAmount: drupalSettings.commerceRevolut.order.amount,
              // We can't just push existing token directly?.
              createOrder: async () => {
                return { publicId: drupalSettings.commerceRevolut.token };
              },
            };

            checkoutForm
              .querySelector('input.button--primary')
              ?.setAttribute('disabled', 'true');

            const revolutButton = document.getElementById(
              'revolut-integration',
            );
            if (!revolutButton.classList.contains('revolut-processed')) {
              revolutPay.mount(revolutButton, paymentOptions);
              revolutButton.classList.add('revolut-processed');
            }

            revolutPay.on('payment', (event) => {
              switch (event.type) {
                case 'cancel': {
                  revolutErrorHandling(
                    checkoutForm,
                    'The payment was cancelled',
                  );
                  break;
                }

                case 'success':
                  const paymentMethodId = checkoutForm.querySelector(
                    '#revolut-payment-method-id',
                  );
                  paymentMethodId.setAttribute(
                    'value',
                    drupalSettings.commerceRevolut.order.id,
                  );
                  checkoutForm.submit();
                  break;

                case 'error':
                  revolutErrorHandling(checkoutForm, event.error);
                  break;
              }
            });

            break;
        }
      }
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      const form = document.querySelector('.revolut-form');
      if (!form) {
        return;
      }
      form.classList.remove('revolut-processed');

      document
        .querySelector('.commerce-checkout-flow')
        ?.querySelector('input.button--primary')
        ?.removeAttribute('disabled');
    },
  };
})(Drupal, drupalSettings);
